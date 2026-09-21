<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;
use PDO;
use PDOStatement;

final class PdoTimetableConfigurationStore implements ResourceTimetableStore, EditableTimetableConfigurationStore, TimetableCsvImportStore
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
            'SELECT id, organisation_id, label, effective_from, effective_to, first_day_of_week
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
            (int) $row['first_day_of_week'],
        );
    }

    public function versionsForOrganisation(int $organisationId): array
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, label, effective_from, effective_to, first_day_of_week
             FROM timetable_versions WHERE organisation_id = :organisation_id
             ORDER BY id',
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(self::version(...), $statement->fetchAll());
    }

    public function activeVersionId(int $organisationId): ?int
    {
        $statement = $this->prepare('SELECT active_timetable_version_id FROM organisations WHERE id = :organisation_id');
        $statement->execute(['organisation_id' => $organisationId]);
        $value = $statement->fetchColumn();
        return $value === false || $value === null ? null : (int) $value;
    }

    public function activateVersion(int $organisationId, int $versionId): void
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin timetable activation.');
        try {
            $lock = $this->prepare('SELECT id FROM organisations WHERE id = :organisation_id FOR UPDATE');
            $lock->execute(['organisation_id' => $organisationId]);
            if ($lock->fetchColumn() === false) throw new TimetableValidationException(['Organisation is invalid.']);
            $version = $this->prepare('SELECT id FROM timetable_versions WHERE id = :version_id AND organisation_id = :organisation_id');
            $version->execute(['version_id' => $versionId, 'organisation_id' => $organisationId]);
            if ($version->fetchColumn() === false) throw new TimetableValidationException(['Timetable template is not available for this organisation.']);
            $update = $this->prepare('UPDATE organisations SET active_timetable_version_id = :version_id WHERE id = :organisation_id');
            $update->execute(['version_id' => $versionId, 'organisation_id' => $organisationId]);
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete timetable activation.');
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function usersForOrganisation(int $organisationId): array
    {
        $statement = $this->prepare(
            'SELECT id, staff_identifier, is_active
             FROM users WHERE organisation_id = :organisation_id AND (is_teacher = TRUE OR operational_role = \'teacher\')
             ORDER BY staff_identifier, id',
        );
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
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

    public function roomsForOrganisation(int $organisationId, bool $includeArchived = false): array
    {
        $statement = $this->prepare('SELECT id, room_code, archived_at FROM organisation_rooms WHERE organisation_id = :organisation_id' . ($includeArchived ? '' : ' AND archived_at IS NULL') . ' ORDER BY room_code, id');
        $statement->execute(['organisation_id' => $organisationId]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['room_code'], 'archived' => $row['archived_at'] !== null], $statement->fetchAll());
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

    public function archiveRoom(int $organisationId, int $roomId): void
    {
        $statement = $this->prepare('UPDATE organisation_rooms SET archived_at = COALESCE(archived_at, CURRENT_TIMESTAMP(6)) WHERE id = :room_id AND organisation_id = :organisation_id');
        $statement->execute(['room_id' => $roomId, 'organisation_id' => $organisationId]);
        if ($statement->rowCount() < 1 && !$this->roomExistsForOrganisation($organisationId, $roomId)) throw new TimetableValidationException(['Room is not available for this organisation.']);
    }

    public function restoreRoom(int $organisationId, int $roomId): void
    {
        $statement = $this->prepare('UPDATE organisation_rooms SET archived_at = NULL WHERE id = :room_id AND organisation_id = :organisation_id');
        $statement->execute(['room_id' => $roomId, 'organisation_id' => $organisationId]);
        if ($statement->rowCount() < 1 && !$this->roomExistsForOrganisation($organisationId, $roomId)) throw new TimetableValidationException(['Room is not available for this organisation.']);
    }

    public function deleteRoomPermanently(int $organisationId, int $roomId): bool
    {
        $statement = $this->prepare(
            'DELETE FROM organisation_rooms
             WHERE id = :room_id AND organisation_id = :organisation_id
               AND NOT EXISTS (SELECT 1 FROM recurring_lessons rl WHERE rl.room_id = organisation_rooms.id)
               AND NOT EXISTS (SELECT 1 FROM lesson_occurrences lo WHERE lo.organisation_id = organisation_rooms.organisation_id AND lo.snapshot_room_code = organisation_rooms.room_code)
               AND NOT EXISTS (SELECT 1 FROM technician_room_preferences trp WHERE trp.organisation_id = organisation_rooms.organisation_id AND trp.room_id = organisation_rooms.id)',
        );
        $statement->execute(['room_id' => $roomId, 'organisation_id' => $organisationId]);
        return $statement->rowCount() === 1;
    }

    public function roomHasReferences(int $organisationId, int $roomId): bool
    {
        $statement = $this->prepare(
            'SELECT EXISTS (
                SELECT 1 FROM organisation_rooms r
                WHERE r.id = :room_id AND r.organisation_id = :organisation_id
                  AND (
                    EXISTS (SELECT 1 FROM recurring_lessons rl WHERE rl.room_id = r.id)
                    OR EXISTS (SELECT 1 FROM lesson_occurrences lo WHERE lo.organisation_id = r.organisation_id AND lo.snapshot_room_code = r.room_code)
                    OR EXISTS (SELECT 1 FROM technician_room_preferences trp WHERE trp.organisation_id = r.organisation_id AND trp.room_id = r.id)
                  )
            )',
        );
        $statement->execute(['room_id' => $roomId, 'organisation_id' => $organisationId]);
        return (int) $statement->fetchColumn() === 1;
    }

    private function roomExistsForOrganisation(int $organisationId, int $roomId): bool
    {
        $statement = $this->prepare('SELECT 1 FROM organisation_rooms WHERE id = :room_id AND organisation_id = :organisation_id');
        $statement->execute(['room_id' => $roomId, 'organisation_id' => $organisationId]);
        return $statement->fetchColumn() !== false;
    }

    public function createClass(int $organisationId, string $code): int
    {
        $statement = $this->prepare('INSERT INTO organisation_classes (organisation_id, class_code) VALUES (:organisation_id, :code)');
        $statement->execute(['organisation_id' => $organisationId, 'code' => trim($code)]);
        return (int) $this->pdo->lastInsertId();
    }

    public function createTeacher(int $organisationId, string $code): int
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin teacher creation.');
        try {
            $lock = $this->prepare('SELECT id FROM organisations WHERE id = :id FOR UPDATE');
            $lock->execute(['id' => $organisationId]);
            if ($lock->fetchColumn() === false) throw new TimetableValidationException(['Organisation is invalid.']);
            $number = $this->prepare('SELECT COALESCE(MAX(teacher_number), 0) + 1 FROM users WHERE organisation_id = :organisation_id');
            $number->execute(['organisation_id' => $organisationId]);
            $statement = $this->prepare(
                'INSERT INTO users (organisation_id, staff_identifier, operational_role, is_admin, is_teacher, is_technician, teacher_number, account_state)
                 VALUES (:organisation_id, :code, \'teacher\', FALSE, TRUE, FALSE, :teacher_number, \'awaiting_first_login\')',
            );
            $statement->execute(['organisation_id' => $organisationId, 'code' => trim($code), 'teacher_number' => (int) $number->fetchColumn()]);
            $id = (int) $this->pdo->lastInsertId();
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete teacher creation.');
            return $id;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function roomBelongsToOrganisation(int $roomId, int $organisationId): bool
    {
        return $this->resourceBelongs('organisation_rooms', $roomId, $organisationId);
    }

    public function roomIsActive(int $roomId, int $organisationId): bool
    {
        $statement = $this->prepare('SELECT 1 FROM organisation_rooms WHERE id = :room_id AND organisation_id = :organisation_id AND archived_at IS NULL');
        $statement->execute(['room_id' => $roomId, 'organisation_id' => $organisationId]);
        return $statement->fetchColumn() !== false;
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

    public function beginCsvImport(int $organisationId, int $versionId): bool
    {
        if ($this->pdo->inTransaction() || !$this->pdo->beginTransaction()) {
            throw new \RuntimeException('Unable to begin timetable CSV import transaction.');
        }
        try {
            $organisation = $this->prepare('SELECT id FROM organisations WHERE id = :organisation_id FOR UPDATE');
            $organisation->execute(['organisation_id' => $organisationId]);
            if ($organisation->fetchColumn() === false) throw new TimetableCsvImportException(['The organisation is not available.']);
            $version = $this->prepare('SELECT organisation_id FROM timetable_versions WHERE id = :version_id FOR UPDATE');
            $version->execute(['version_id' => $versionId]);
            $owner = $version->fetchColumn();
            if ($owner === false || (int) $owner !== $organisationId) {
                throw new TimetableCsvImportException(['The selected timetable is not available for this organisation.']);
            }

            foreach ([
                ['SELECT id FROM timetable_slots WHERE timetable_version_id = :scope FOR UPDATE', $versionId],
                ['SELECT id FROM organisation_rooms WHERE organisation_id = :scope FOR UPDATE', $organisationId],
                ['SELECT id FROM organisation_classes WHERE organisation_id = :scope FOR UPDATE', $organisationId],
                ["SELECT id FROM users WHERE organisation_id = :scope AND (is_teacher = TRUE OR operational_role = 'teacher') FOR UPDATE", $organisationId],
            ] as [$sql, $scope]) {
                $lock = $this->prepare($sql);
                $lock->execute(['scope' => $scope]);
                $lock->fetchAll();
            }
            $settings = $this->prepare('SELECT allow_double_periods FROM organisation_settings WHERE organisation_id = :organisation_id FOR UPDATE');
            $settings->execute(['organisation_id' => $organisationId]);
            $value = $settings->fetchColumn();
            return $value !== false && (bool) $value;
        } catch (\Throwable $exception) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function createCsvImportVersion(int $organisationId, int $sourceVersionId, string $requestedLabel): array
    {
        if (!$this->pdo->inTransaction()) throw new \RuntimeException('Timetable CSV import transaction is not active.');
        $sourceStatement = $this->prepare('SELECT id, organisation_id, first_day_of_week FROM timetable_versions WHERE id = :id FOR UPDATE');
        $sourceStatement->execute(['id' => $sourceVersionId]);
        $source = $sourceStatement->fetch();
        if ($source === false || (int) $source['organisation_id'] !== $organisationId) throw new TimetableCsvImportException(['The source timetable is not available for this organisation.']);

        $base = trim($requestedLabel) !== '' ? trim($requestedLabel) : 'Timetable_imported';
        $label = $base;
        $suffix = 1;
        while (true) {
            $check = $this->prepare('SELECT 1 FROM timetable_versions WHERE organisation_id = :organisation_id AND label = :label LIMIT 1');
            $check->execute(['organisation_id' => $organisationId, 'label' => $label]);
            if ($check->fetchColumn() === false) break;
            $suffix++;
            $tail = '_'. $suffix;
            $label = mb_substr($base, 0, 255 - mb_strlen($tail)) . $tail;
        }
        $insert = $this->prepare('INSERT INTO timetable_versions (organisation_id, label, effective_from, effective_to, first_day_of_week) VALUES (:organisation_id, :label, :effective_from, NULL, :first_day)');
        $insert->execute(['organisation_id' => $organisationId, 'label' => $label, 'effective_from' => '1000-01-01', 'first_day' => (int) $source['first_day_of_week']]);
        $versionId = (int) $this->pdo->lastInsertId();
        $slots = $this->prepare('SELECT id, day_of_week, sequence_number, kind, teaching_period_number, label, starts_at, ends_at FROM timetable_slots WHERE timetable_version_id = :version_id ORDER BY day_of_week, sequence_number');
        $slots->execute(['version_id' => $sourceVersionId]);
        $slotMap = [];
        foreach ($slots->fetchAll() as $slot) {
            $copy = $this->prepare('INSERT INTO timetable_slots (timetable_version_id, day_of_week, sequence_number, kind, teaching_period_number, label, starts_at, ends_at) VALUES (:version_id, :day, :sequence, :kind, :period, :label, :starts_at, :ends_at)');
            $copy->execute(['version_id' => $versionId, 'day' => (int) $slot['day_of_week'], 'sequence' => (int) $slot['sequence_number'], 'kind' => (string) $slot['kind'], 'period' => $slot['teaching_period_number'] === null ? null : (int) $slot['teaching_period_number'], 'label' => (string) $slot['label'], 'starts_at' => (string) $slot['starts_at'], 'ends_at' => (string) $slot['ends_at']]);
            $slotMap[(int) $slot['id']] = (int) $this->pdo->lastInsertId();
        }
        return ['id' => $versionId, 'label' => $label, 'slot_map' => $slotMap];
    }

    public function ensureCsvImportClass(int $organisationId, string $code): array
    {
        if (!$this->pdo->inTransaction()) throw new \RuntimeException('Timetable CSV import transaction is not active.');
        $code = trim($code);
        $find = $this->prepare('SELECT id FROM organisation_classes WHERE organisation_id = :organisation_id AND class_code = :code FOR UPDATE');
        $find->execute(['organisation_id' => $organisationId, 'code' => $code]);
        $id = $find->fetchColumn();
        if ($id !== false) return ['id' => (int) $id, 'created' => false];
        $insert = $this->prepare('INSERT INTO organisation_classes (organisation_id, class_code) VALUES (:organisation_id, :code)');
        $insert->execute(['organisation_id' => $organisationId, 'code' => $code]);
        return ['id' => (int) $this->pdo->lastInsertId(), 'created' => true];
    }

    public function activateCsvImportVersion(int $organisationId, int $versionId): void
    {
        if (!$this->pdo->inTransaction()) throw new \RuntimeException('Timetable CSV import transaction is not active.');
        $check = $this->prepare('SELECT id FROM timetable_versions WHERE id = :version_id AND organisation_id = :organisation_id FOR UPDATE');
        $check->execute(['version_id' => $versionId, 'organisation_id' => $organisationId]);
        if ($check->fetchColumn() === false) throw new TimetableCsvImportException(['The imported timetable is not available for this organisation.']);
        $update = $this->prepare('UPDATE organisations SET active_timetable_version_id = :version_id WHERE id = :organisation_id');
        $update->execute(['version_id' => $versionId, 'organisation_id' => $organisationId]);
    }

    public function insertCsvImportLesson(
        int $organisationId,
        int $versionId,
        int $teacherUserId,
        int $dayOfWeek,
        int $startSlotId,
        int $durationPeriods,
        int $classId,
        int $roomId,
    ): int {
        if (!$this->pdo->inTransaction()) throw new \RuntimeException('Timetable CSV import transaction is not active.');
        $statement = $this->prepare(
            "INSERT INTO recurring_lessons
                (timetable_version_id, teacher_user_id, day_of_week, start_slot_id, duration_periods, class_code, room_code, class_id, room_id)
             SELECT tv.id, u.id, :day, s.id, :duration, c.class_code, r.room_code, c.id, r.id
             FROM timetable_versions tv
             JOIN users u ON u.id = :teacher_id AND u.organisation_id = tv.organisation_id
                AND u.is_active = TRUE AND (u.is_teacher = TRUE OR u.operational_role = 'teacher')
             JOIN organisation_classes c ON c.id = :class_id AND c.organisation_id = tv.organisation_id
             JOIN organisation_rooms r ON r.id = :room_id AND r.organisation_id = tv.organisation_id
             JOIN timetable_slots s ON s.id = :slot_id AND s.timetable_version_id = tv.id
                AND s.day_of_week = :slot_day AND s.kind = 'teaching'
             WHERE tv.id = :version_id AND tv.organisation_id = :organisation_id",
        );
        $statement->execute([
            'day' => $dayOfWeek,
            'duration' => $durationPeriods,
            'teacher_id' => $teacherUserId,
            'class_id' => $classId,
            'room_id' => $roomId,
            'slot_id' => $startSlotId,
            'slot_day' => $dayOfWeek,
            'version_id' => $versionId,
            'organisation_id' => $organisationId,
        ]);
        if ($statement->rowCount() !== 1) throw new \RuntimeException('A validated timetable resource became unavailable.');
        return (int) $this->pdo->lastInsertId();
    }

    public function commitCsvImport(): void
    {
        if (!$this->pdo->inTransaction() || !$this->pdo->commit()) throw new \RuntimeException('Unable to commit timetable CSV import.');
    }

    public function rollbackCsvImport(): void
    {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
    }

    public function insertVersion(int $organisationId, ?string $label, DateTimeImmutable $effectiveFrom, ?DateTimeImmutable $effectiveTo, int $firstDayOfWeek = 1): int
    {
        $statement = $this->prepare(
            'INSERT INTO timetable_versions (organisation_id, label, effective_from, effective_to, first_day_of_week)
             VALUES (:organisation_id, :label, :effective_from, :effective_to, :first_day_of_week)',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'label' => $label,
            'effective_from' => $effectiveFrom->format('Y-m-d'),
            'effective_to' => $effectiveTo?->format('Y-m-d'),
            'first_day_of_week' => $firstDayOfWeek,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function updateVersion(int $organisationId, int $versionId, string $label, int $firstDayOfWeek, array $slots): void
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin timetable edit.');
        try {
            $check = $this->prepare('SELECT id FROM timetable_versions WHERE id = :id AND organisation_id = :organisation_id FOR UPDATE');
            $check->execute(['id' => $versionId, 'organisation_id' => $organisationId]);
            if ($check->fetchColumn() === false) throw new TimetableValidationException(['The timetable template is not available for this organisation.']);
            $update = $this->prepare('UPDATE timetable_versions SET label = :label, first_day_of_week = :first_day WHERE id = :id AND organisation_id = :organisation_id');
            $update->execute(['label' => $label, 'first_day' => $firstDayOfWeek, 'id' => $versionId, 'organisation_id' => $organisationId]);
            $existing = [];
            $select = $this->prepare('SELECT id, day_of_week, sequence_number FROM timetable_slots WHERE timetable_version_id = :version_id ORDER BY day_of_week, sequence_number FOR UPDATE');
            $select->execute(['version_id' => $versionId]);
            foreach ($select->fetchAll() as $row) $existing[(int) $row['day_of_week'] . ':' . (int) $row['sequence_number']] = (int) $row['id'];
            $keep = [];
            foreach ($slots as $slot) {
                $key = (int) $slot['day'] . ':' . (int) $slot['sequence']; $keep[$key] = true;
                if (isset($existing[$key])) {
                    $statement = $this->prepare('UPDATE timetable_slots SET kind = :kind, teaching_period_number = :period, label = :label, starts_at = :starts_at, ends_at = :ends_at WHERE id = :id');
                    $statement->execute(['kind' => $slot['kind'], 'period' => $slot['period'], 'label' => $slot['label'], 'starts_at' => $slot['starts_at'], 'ends_at' => $slot['ends_at'], 'id' => $existing[$key]]);
                } else $this->insertSlot($versionId, (int) $slot['day'], (int) $slot['sequence'], (string) $slot['kind'], $slot['period'] === null ? null : (int) $slot['period'], (string) $slot['label'], (string) $slot['starts_at'], (string) $slot['ends_at']);
            }
            foreach ($existing as $key => $slotId) {
                if (isset($keep[$key])) continue;
                $used = $this->prepare('SELECT 1 FROM recurring_lessons WHERE start_slot_id = :slot_id UNION ALL SELECT 1 FROM lesson_occurrences WHERE snapshot_start_slot_id = :slot_id LIMIT 1');
                $used->execute(['slot_id' => $slotId]);
                if ($used->fetchColumn() !== false) throw new TimetableValidationException(['This timetable period is part of existing lessons or dated history and cannot be removed.']);
                $delete = $this->prepare('DELETE FROM timetable_slots WHERE id = :id AND timetable_version_id = :version_id');
                $delete->execute(['id' => $slotId, 'version_id' => $versionId]);
            }
            if (!$this->pdo->commit()) throw new \RuntimeException('Unable to complete timetable edit.');
        } catch (\Throwable $exception) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $exception; }
    }

    public function createSuccessorVersion(int $organisationId, int $sourceVersionId, ?string $label, DateTimeImmutable $effectiveFrom): int
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to begin successor template creation.');
        try {
            $source = $this->findVersion($sourceVersionId);
            if ($source === null || $source->organisationId !== $organisationId) throw new TimetableValidationException(['The source timetable template is not available for this organisation.']);
            $close = $this->prepare('UPDATE timetable_versions SET effective_to = :effective_to WHERE id = :id AND organisation_id = :organisation_id');
            $close->execute(['effective_to' => $effectiveFrom->format('Y-m-d'), 'id' => $sourceVersionId, 'organisation_id' => $organisationId]);
            $insert = $this->prepare('INSERT INTO timetable_versions (organisation_id, label, effective_from, effective_to, first_day_of_week) VALUES (:organisation_id, :label, :effective_from, :effective_to, :first_day_of_week)');
            $insert->execute([
                'organisation_id' => $organisationId, 'label' => $label,
                'effective_from' => $effectiveFrom->format('Y-m-d'),
                'effective_to' => $source->effectiveTo?->format('Y-m-d'),
                'first_day_of_week' => $source->firstDayOfWeek,
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
        $statement = $this->prepare("SELECT organisation_id FROM users WHERE id = :id AND is_active = TRUE AND (is_teacher = TRUE OR operational_role = 'teacher')");
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
            (int) $row['first_day_of_week'],
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
