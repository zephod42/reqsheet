<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;
use PDO;
use PDOStatement;

final class PdoTimetableConfigurationStore implements ResourceTimetableStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function organisationExists(int $organisationId): bool
    {
        $statement = $this->prepare('SELECT 1 FROM organisations WHERE id = :id');
        $statement->execute(['id' => $organisationId]);
        return $statement->fetchColumn() !== false;
    }

    public function findVersion(int $versionId): ?TimetableVersion
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, label, effective_from, effective_to
             FROM timetable_versions WHERE id = :id',
        );
        $statement->execute(['id' => $versionId]);
        $row = $statement->fetch();
        return $row === false ? null : new TimetableVersion(
            (int) $row['id'],
            (int) $row['organisation_id'],
            $row['label'] === null ? null : (string) $row['label'],
            self::date((string) $row['effective_from']),
            $row['effective_to'] === null ? null : self::date((string) $row['effective_to']),
        );
    }

    public function versionsForOrganisation(int $organisationId): array
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, label, effective_from, effective_to
             FROM timetable_versions WHERE organisation_id = :organisation_id
             ORDER BY effective_from, id',
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(self::version(...), $statement->fetchAll());
    }

    public function usersForOrganisation(int $organisationId): array
    {
        $statement = $this->prepare(
            'SELECT id, display_name, staff_identifier, is_active
             FROM users WHERE organisation_id = :organisation_id
             ORDER BY display_name, id',
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'display_name' => (string) $row['display_name'],
            'staff_identifier' => $row['staff_identifier'] === null ? null : (string) $row['staff_identifier'],
            'is_active' => (bool) $row['is_active'],
        ], $statement->fetchAll());
    }

    public function roomCodesForVersion(int $versionId): array
    {
        $statement = $this->prepare(
            "SELECT DISTINCT room_code FROM recurring_lessons
             WHERE timetable_version_id = :version_id AND TRIM(room_code) <> ''
             ORDER BY room_code",
        );
        $statement->execute(['version_id' => $versionId]);
        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    public function roomsForOrganisation(int $organisationId): array
    {
        $statement = $this->prepare('SELECT id, room_code FROM organisation_rooms WHERE organisation_id = :organisation_id ORDER BY room_code, id');
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['room_code']], $statement->fetchAll());
    }

    public function classesForOrganisation(int $organisationId): array
    {
        $statement = $this->prepare('SELECT id, class_code FROM organisation_classes WHERE organisation_id = :organisation_id ORDER BY class_code, id');
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['class_code']], $statement->fetchAll());
    }

    public function createRoom(int $organisationId, string $code): int
    {
        $statement = $this->prepare('INSERT INTO organisation_rooms (organisation_id, room_code) VALUES (:organisation_id, :code)');
        $statement->execute(['organisation_id' => $organisationId, 'code' => trim($code)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createClass(int $organisationId, string $code): int
    {
        $statement = $this->prepare('INSERT INTO organisation_classes (organisation_id, class_code) VALUES (:organisation_id, :code)');
        $statement->execute(['organisation_id' => $organisationId, 'code' => trim($code)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createTeacher(int $organisationId, string $code, string $displayName): int
    {
        $statement = $this->prepare(
            'INSERT INTO users (organisation_id, display_name, staff_identifier, operational_role, is_admin, account_state)
             VALUES (:organisation_id, :display_name, :code, \'teacher\', FALSE, \'awaiting_first_login\')',
        );
        $statement->execute(['organisation_id' => $organisationId, 'display_name' => trim($displayName) === '' ? trim($code) : trim($displayName), 'code' => trim($code)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function roomBelongsToOrganisation(int $roomId, int $organisationId): bool
    {
        return $this->resourceBelongs('organisation_rooms', $roomId, $organisationId);
    }

    public function classBelongsToOrganisation(int $classId, int $organisationId): bool
    {
        return $this->resourceBelongs('organisation_classes', $classId, $organisationId);
    }

    public function roomCode(int $roomId): ?string
    {
        return $this->resourceCode('organisation_rooms', 'room_code', $roomId);
    }

    public function classCode(int $classId): ?string
    {
        return $this->resourceCode('organisation_classes', 'class_code', $classId);
    }

    public function insertResourceLesson(int $versionId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, int $classId, int $roomId): int
    {
        $statement = $this->prepare(
            'INSERT INTO recurring_lessons
                (timetable_version_id, teacher_user_id, day_of_week, start_slot_id, duration_periods, class_code, room_code, class_id, room_id)
             SELECT :version_id, :teacher, :day, :start_slot, :duration, c.class_code, r.room_code, c.id, r.id
             FROM organisation_classes c CROSS JOIN organisation_rooms r
             WHERE c.id = :class_id AND r.id = :room_id',
        );
        $statement->execute(['version_id' => $versionId, 'teacher' => $teacherUserId, 'day' => $dayOfWeek, 'start_slot' => $startSlotId, 'duration' => $durationPeriods, 'class_id' => $classId, 'room_id' => $roomId]);
        if ($statement->rowCount() !== 1) throw new \RuntimeException('Timetable resources are not available.');
        return (int) $this->pdo->lastInsertId();
    }

    public function updateResourceLesson(int $lessonId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, int $classId, int $roomId): void
    {
        $statement = $this->prepare(
            'UPDATE recurring_lessons rl JOIN organisation_classes c ON c.id = :class_id JOIN organisation_rooms r ON r.id = :room_id
             SET rl.teacher_user_id = :teacher, rl.day_of_week = :day, rl.start_slot_id = :start_slot,
                 rl.duration_periods = :duration, rl.class_code = c.class_code, rl.room_code = r.room_code,
                 rl.class_id = c.id, rl.room_id = r.id WHERE rl.id = :id',
        );
        $statement->execute(['id' => $lessonId, 'teacher' => $teacherUserId, 'day' => $dayOfWeek, 'start_slot' => $startSlotId, 'duration' => $durationPeriods, 'class_id' => $classId, 'room_id' => $roomId]);
        if ($statement->rowCount() < 1) throw new \RuntimeException('Timetable resources are not available.');
    }

    public function insertVersion(int $organisationId, ?string $label, DateTimeImmutable $effectiveFrom, ?DateTimeImmutable $effectiveTo): int
    {
        $statement = $this->prepare(
            'INSERT INTO timetable_versions (organisation_id, label, effective_from, effective_to)
             VALUES (:organisation_id, :label, :effective_from, :effective_to)',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'label' => $label,
            'effective_from' => $effectiveFrom->format('Y-m-d'),
            'effective_to' => $effectiveTo?->format('Y-m-d'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createSuccessorVersion(int $organisationId, int $sourceVersionId, ?string $label, DateTimeImmutable $effectiveFrom): int
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin successor template creation.');
        try {
            $source = $this->findVersion($sourceVersionId);
            if ($source === null || $source->organisationId !== $organisationId) throw new TimetableValidationException(['The source timetable template is not available for this organisation.']);
            $close = $this->prepare('UPDATE timetable_versions SET effective_to = :effective_to WHERE id = :id AND organisation_id = :organisation_id');
            $close->execute(['effective_to' => $effectiveFrom->format('Y-m-d'), 'id' => $sourceVersionId, 'organisation_id' => $organisationId]);
            $insert = $this->prepare('INSERT INTO timetable_versions (organisation_id, label, effective_from, effective_to) VALUES (:organisation_id, :label, :effective_from, :effective_to)');
            $insert->execute([
                'organisation_id' => $organisationId, 'label' => $label,
                'effective_from' => $effectiveFrom->format('Y-m-d'),
                'effective_to' => $source->effectiveTo?->format('Y-m-d'),
            ]);
            $id = (int) $this->pdo->lastInsertId();
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete successor template creation.');
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function occurrenceCountForVersionFrom(int $versionId, DateTimeImmutable $date): int
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM lesson_occurrences WHERE timetable_version_id = :version_id AND lesson_date >= :lesson_date');
        $statement->execute(['version_id' => $versionId, 'lesson_date' => $date->format('Y-m-d')]);
        return (int) $statement->fetchColumn();
    }

    public function slotsForVersion(int $versionId): array
    {
        $statement = $this->prepare(
            'SELECT id, timetable_version_id, day_of_week, sequence_number, kind,
                    teaching_period_number, label, starts_at, ends_at
             FROM timetable_slots WHERE timetable_version_id = :version_id
             ORDER BY day_of_week, sequence_number',
        );
        $statement->execute(['version_id' => $versionId]);
        return array_map(self::slot(...), $statement->fetchAll());
    }

    public function insertSlot(int $versionId, int $dayOfWeek, int $sequenceNumber, string $kind, ?int $teachingPeriodNumber, string $label, string $startsAt, string $endsAt): int
    {
        $statement = $this->prepare(
            'INSERT INTO timetable_slots
                (timetable_version_id, day_of_week, sequence_number, kind, teaching_period_number, label, starts_at, ends_at)
             VALUES (:version_id, :day, :sequence, :kind, :period, :label, :starts_at, :ends_at)',
        );
        $statement->execute([
            'version_id' => $versionId,
            'day' => $dayOfWeek,
            'sequence' => $sequenceNumber,
            'kind' => $kind,
            'period' => $teachingPeriodNumber,
            'label' => $label,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function findTeacherOrganisation(int $teacherUserId): ?int
    {
        $statement = $this->prepare('SELECT organisation_id FROM users WHERE id = :id');
        $statement->execute(['id' => $teacherUserId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (int) $value;
    }

    public function lessonsForVersion(int $versionId): array
    {
        $statement = $this->prepare(
            'SELECT id, timetable_version_id, teacher_user_id, day_of_week, start_slot_id,
                    duration_periods, class_code, room_code, class_id, room_id
             FROM recurring_lessons WHERE timetable_version_id = :version_id
             ORDER BY day_of_week, start_slot_id, id',
        );
        $statement->execute(['version_id' => $versionId]);
        return array_map(
            static fn (array $row): RecurringLesson => new RecurringLesson(
                (int) $row['id'], (int) $row['timetable_version_id'], (int) $row['teacher_user_id'],
                (int) $row['day_of_week'], (int) $row['start_slot_id'], (int) $row['duration_periods'],
                (string) $row['class_code'], (string) $row['room_code'], (int) $row['class_id'], (int) $row['room_id'],
            ),
            $statement->fetchAll(),
        );
    }

    public function findLesson(int $lessonId): ?RecurringLesson
    {
        $statement = $this->prepare(
            'SELECT id, timetable_version_id, teacher_user_id, day_of_week, start_slot_id,
                    duration_periods, class_code, room_code, class_id, room_id
             FROM recurring_lessons WHERE id = :id',
        );
        $statement->execute(['id' => $lessonId]);
        $row = $statement->fetch();
        return $row === false ? null : self::lesson($row);
    }

    public function occurrenceCountForLesson(int $lessonId): int
    {
        $statement = $this->prepare('SELECT COUNT(*) FROM lesson_occurrences WHERE recurring_lesson_id = :id');
        $statement->execute(['id' => $lessonId]);
        return (int) $statement->fetchColumn();
    }

    public function insertLesson(int $versionId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, string $classCode, string $roomCode): int
    {
        $organisationId = $this->organisationForVersion($versionId);
        $classId = $this->ensureClass($organisationId, $classCode);
        $roomId = $this->ensureRoom($organisationId, $roomCode);
        return $this->insertResourceLesson($versionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, $classId, $roomId);
    }

    public function updateLesson(int $lessonId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, string $classCode, string $roomCode): void
    {
        $lesson = $this->findLesson($lessonId);
        if ($lesson === null) throw new \RuntimeException('Lesson does not exist.');
        $organisationId = $this->organisationForVersion($lesson->timetableVersionId);
        $classId = $this->ensureClass($organisationId, $classCode);
        $roomId = $this->ensureRoom($organisationId, $roomCode);
        $this->updateResourceLesson($lessonId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, $classId, $roomId);
    }

    public function deleteLesson(int $lessonId): void
    {
        $statement = $this->prepare('DELETE FROM recurring_lessons WHERE id = :id');
        $statement->execute(['id' => $lessonId]);
    }

    /** @param array<string, mixed> $row */
    private static function version(array $row): TimetableVersion
    {
        return new TimetableVersion(
            (int) $row['id'], (int) $row['organisation_id'],
            $row['label'] === null ? null : (string) $row['label'],
            self::date((string) $row['effective_from']),
            $row['effective_to'] === null ? null : self::date((string) $row['effective_to']),
        );
    }

    /** @param array<string, mixed> $row */
    private static function slot(array $row): TimetableSlot
    {
        return new TimetableSlot(
            (int) $row['id'], (int) $row['timetable_version_id'], (int) $row['day_of_week'],
            (int) $row['sequence_number'], (string) $row['kind'],
            $row['teaching_period_number'] === null ? null : (int) $row['teaching_period_number'],
            (string) $row['label'], substr((string) $row['starts_at'], 0, 8), substr((string) $row['ends_at'], 0, 8),
        );
    }

    /** @param array<string, mixed> $row */
    private static function lesson(array $row): RecurringLesson
    {
        return new RecurringLesson(
            (int) $row['id'], (int) $row['timetable_version_id'], (int) $row['teacher_user_id'],
            (int) $row['day_of_week'], (int) $row['start_slot_id'], (int) $row['duration_periods'],
            (string) $row['class_code'], (string) $row['room_code'], (int) $row['class_id'], (int) $row['room_id'],
        );
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new \RuntimeException('Database returned an invalid date.');
        }
        return $date;
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) {
            throw new \RuntimeException('Unable to prepare timetable configuration query.');
        }
        return $statement;
    }

    private function resourceBelongs(string $table, int $resourceId, int $organisationId): bool
    {
        $statement = $this->prepare("SELECT 1 FROM {$table} WHERE id = :id AND organisation_id = :organisation_id");
        $statement->execute(['id' => $resourceId, 'organisation_id' => $organisationId]);
        return $statement->fetchColumn() !== false;
    }

    private function resourceCode(string $table, string $column, int $resourceId): ?string
    {
        $statement = $this->prepare("SELECT {$column} FROM {$table} WHERE id = :id");
        $statement->execute(['id' => $resourceId]);
        $value = $statement->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function organisationForVersion(int $versionId): int
    {
        $statement = $this->prepare('SELECT organisation_id FROM timetable_versions WHERE id = :id');
        $statement->execute(['id' => $versionId]);
        $organisationId = $statement->fetchColumn();
        if ($organisationId === false) throw new \RuntimeException('Timetable version does not exist.');
        return (int) $organisationId;
    }

    private function ensureRoom(int $organisationId, string $code): int
    {
        $statement = $this->prepare('SELECT id FROM organisation_rooms WHERE organisation_id = :organisation_id AND room_code = :code');
        $statement->execute(['organisation_id' => $organisationId, 'code' => trim($code)]);
        $id = $statement->fetchColumn();
        return $id === false ? $this->createRoom($organisationId, $code) : (int) $id;
    }

    private function ensureClass(int $organisationId, string $code): int
    {
        $statement = $this->prepare('SELECT id FROM organisation_classes WHERE organisation_id = :organisation_id AND class_code = :code');
        $statement->execute(['organisation_id' => $organisationId, 'code' => trim($code)]);
        $id = $statement->fetchColumn();
        return $id === false ? $this->createClass($organisationId, $code) : (int) $id;
    }
}
