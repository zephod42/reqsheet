<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;
use PDO;
use PDOStatement;

final class PdoTimetableGenerationStore implements TimetableGenerationStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function findVersion(int $organisationId, int $versionId): ?TimetableVersion
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, label, effective_from, effective_to, first_day_of_week
             FROM timetable_versions
             WHERE id = :id AND organisation_id = :organisation_id',
        );
        $statement->execute(['id' => $versionId, 'organisation_id' => $organisationId]);
        $row = $statement->fetch();

        return $row === false ? null : new TimetableVersion(
            (int) $row['id'],
            (int) $row['organisation_id'],
            $row['label'] === null ? null : (string) $row['label'],
            self::date($row['effective_from']),
            $row['effective_to'] === null ? null : self::date($row['effective_to']),
            (int) $row['first_day_of_week'],
        );
    }

    public function findTeacherOrganisation(int $teacherUserId): ?int
    {
        $statement = $this->prepare('SELECT organisation_id FROM users WHERE id = :id');
        $statement->execute(['id' => $teacherUserId]);
        $organisationId = $statement->fetchColumn();

        return $organisationId === false ? null : (int) $organisationId;
    }

    public function findSlot(int $slotId): ?TimetableSlot
    {
        $statement = $this->prepare(
            'SELECT id, timetable_version_id, day_of_week, sequence_number, kind, teaching_period_number
             FROM timetable_slots WHERE id = :id',
        );
        $statement->execute(['id' => $slotId]);
        $row = $statement->fetch();

        return $row === false ? null : self::slot($row);
    }

    public function slotsForVersion(int $versionId): array
    {
        $statement = $this->prepare(
            'SELECT id, timetable_version_id, day_of_week, sequence_number, kind, teaching_period_number
             FROM timetable_slots WHERE timetable_version_id = :version_id
             ORDER BY day_of_week, sequence_number',
        );
        $statement->execute(['version_id' => $versionId]);

        return array_map(self::slot(...), $statement->fetchAll());
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
                (int) $row['id'],
                (int) $row['timetable_version_id'],
                (int) $row['teacher_user_id'],
                (int) $row['day_of_week'],
                (int) $row['start_slot_id'],
                (int) $row['duration_periods'],
                (string) $row['class_code'],
                (string) $row['room_code'],
            ),
            $statement->fetchAll(),
        );
    }

    public function existingOccurrenceKeys(int $organisationId, string $startDate, string $endDate): array
    {
        $statement = $this->prepare(
            'SELECT lesson_date, recurring_lesson_id
             FROM lesson_occurrences
             WHERE organisation_id = :organisation_id
               AND lesson_date BETWEEN :start_date AND :end_date',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'start_date' => $startDate,
            'end_date' => $endDate,
        ]);

        $keys = [];
        foreach ($statement->fetchAll() as $row) {
            $keys[$row['lesson_date'] . '/' . $row['recurring_lesson_id']] = true;
        }

        return $keys;
    }

    public function begin(): void
    {
        if (!$this->pdo->beginTransaction()) {
            throw new \RuntimeException('Unable to begin occurrence generation transaction.');
        }
    }

    public function commit(): void
    {
        if (!$this->pdo->commit()) {
            throw new \RuntimeException('Unable to commit occurrence generation transaction.');
        }
    }

    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function insertOccurrence(
        int $organisationId,
        RecurringLesson $lesson,
        TimetableSlot $startSlot,
        TimetableVersion $version,
        string $lessonDate,
    ): void {
        $statement = $this->prepare(
            'INSERT INTO lesson_occurrences
                (organisation_id, recurring_lesson_id, timetable_version_id, lesson_date,
                 snapshot_teacher_user_id, snapshot_class_code, snapshot_room_code,
                 snapshot_start_slot_id, snapshot_duration_periods)
             VALUES
                (:organisation_id, :recurring_lesson_id, :timetable_version_id, :lesson_date,
                 :teacher_user_id, :class_code, :room_code, :start_slot_id, :duration_periods)',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'recurring_lesson_id' => $lesson->id,
            'timetable_version_id' => $version->id,
            'lesson_date' => $lessonDate,
            'teacher_user_id' => $lesson->teacherUserId,
            'class_code' => $lesson->classCode,
            'room_code' => $lesson->roomCode,
            'start_slot_id' => $startSlot->id,
            'duration_periods' => $lesson->durationPeriods,
        ]);
    }

    /** @param array<string, mixed> $row */
    private static function slot(array $row): TimetableSlot
    {
        return new TimetableSlot(
            (int) $row['id'],
            (int) $row['timetable_version_id'],
            (int) $row['day_of_week'],
            (int) $row['sequence_number'],
            (string) $row['kind'],
            $row['teaching_period_number'] === null ? null : (int) $row['teaching_period_number'],
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
            throw new \RuntimeException('Unable to prepare timetable query.');
        }

        return $statement;
    }
}
