<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;
use PDO;
use PDOStatement;

final class PdoTimetableConfigurationStore implements TimetableConfigurationStore
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
                    duration_periods, class_code, room_code
             FROM recurring_lessons WHERE timetable_version_id = :version_id
             ORDER BY day_of_week, start_slot_id, id',
        );
        $statement->execute(['version_id' => $versionId]);
        return array_map(
            static fn (array $row): RecurringLesson => new RecurringLesson(
                (int) $row['id'], (int) $row['timetable_version_id'], (int) $row['teacher_user_id'],
                (int) $row['day_of_week'], (int) $row['start_slot_id'], (int) $row['duration_periods'],
                (string) $row['class_code'], (string) $row['room_code'],
            ),
            $statement->fetchAll(),
        );
    }

    public function insertLesson(int $versionId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, string $classCode, string $roomCode): int
    {
        $statement = $this->prepare(
            'INSERT INTO recurring_lessons
                (timetable_version_id, teacher_user_id, day_of_week, start_slot_id, duration_periods, class_code, room_code)
             VALUES (:version_id, :teacher, :day, :start_slot, :duration, :class_code, :room_code)',
        );
        $statement->execute([
            'version_id' => $versionId,
            'teacher' => $teacherUserId,
            'day' => $dayOfWeek,
            'start_slot' => $startSlotId,
            'duration' => $durationPeriods,
            'class_code' => $classCode,
            'room_code' => $roomCode,
        ]);
        return (int) $this->pdo->lastInsertId();
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
}
