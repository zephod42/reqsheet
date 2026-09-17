<?php

declare(strict_types=1);

namespace Reqsheet\Teacher;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;

final class PdoTeacherPlanningStore implements TeacherPlanningStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function teacherBelongsToOrganisation(int $teacherId, int $organisationId): bool
    {
        $statement = $this->prepare('SELECT 1 FROM users WHERE id = :teacher_id AND organisation_id = :organisation_id');
        $statement->execute(['teacher_id' => $teacherId, 'organisation_id' => $organisationId]);
        return $statement->fetchColumn() !== false;
    }

    public function effectiveVersion(int $organisationId, DateTimeImmutable $date): ?TimetableVersion
    {
        $statement = $this->prepare(
            'SELECT id, organisation_id, label, effective_from, effective_to
             FROM timetable_versions
             WHERE organisation_id = :organisation_id
               AND effective_from <= :effective_date
               AND (effective_to IS NULL OR :effective_date < effective_to)
             ORDER BY effective_from DESC, id DESC LIMIT 1',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'effective_date' => $date->format('Y-m-d'),
        ]);
        $row = $statement->fetch();
        if ($row === false) return null;
        return new TimetableVersion(
            (int) $row['id'],
            (int) $row['organisation_id'],
            $row['label'] === null ? null : (string) $row['label'],
            self::date((string) $row['effective_from']),
            $row['effective_to'] === null ? null : self::date((string) $row['effective_to']),
        );
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
        return array_map(static fn (array $row): TimetableSlot => new TimetableSlot(
            (int) $row['id'],
            (int) $row['timetable_version_id'],
            (int) $row['day_of_week'],
            (int) $row['sequence_number'],
            (string) $row['kind'],
            $row['teaching_period_number'] === null ? null : (int) $row['teaching_period_number'],
            (string) $row['label'],
            substr((string) $row['starts_at'], 0, 8),
            substr((string) $row['ends_at'], 0, 8),
        ), $statement->fetchAll());
    }

    public function occurrencesForTeacherDate(int $organisationId, int $teacherId, DateTimeImmutable $date): array
    {
        $statement = $this->occurrenceQuery(
            'o.organisation_id = :organisation_id AND o.snapshot_teacher_user_id = :teacher_id AND o.lesson_date = :lesson_date',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'teacher_id' => $teacherId,
            'lesson_date' => $date->format('Y-m-d'),
        ]);
        return $statement->fetchAll();
    }

    public function findOccurrenceForTeacher(int $organisationId, int $teacherId, int $occurrenceId): ?array
    {
        $statement = $this->occurrenceQuery(
            'o.organisation_id = :organisation_id AND o.snapshot_teacher_user_id = :teacher_id AND o.id = :occurrence_id',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'teacher_id' => $teacherId,
            'occurrence_id' => $occurrenceId,
        ]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function savePlanning(int $occurrenceId, string $state, string $lessonOutline, string $requisitions, string $riskAssessment): void
    {
        $statement = $this->prepare(
            'INSERT INTO requisitions
                (lesson_occurrence_id, state, requirements_text, planning_notes, risk_assessment_text)
             VALUES (:occurrence_id, :state, :requirements, :outline, :risk)
             ON DUPLICATE KEY UPDATE
                state = VALUES(state), requirements_text = VALUES(requirements_text),
                planning_notes = VALUES(planning_notes), risk_assessment_text = VALUES(risk_assessment_text)',
        );
        $statement->execute([
            'occurrence_id' => $occurrenceId,
            'state' => $state,
            'requirements' => $requisitions === '' ? null : $requisitions,
            'outline' => $lessonOutline === '' ? null : $lessonOutline,
            'risk' => $riskAssessment === '' ? null : $riskAssessment,
        ]);
    }

    private function occurrenceQuery(string $condition): PDOStatement
    {
        return $this->prepare(
            'SELECT o.id, o.lesson_date, o.timetable_version_id, o.snapshot_teacher_user_id,
                    o.snapshot_class_code, o.snapshot_room_code, o.snapshot_start_slot_id,
                    o.snapshot_duration_periods, s.day_of_week, s.teaching_period_number,
                    s.label AS slot_label, r.state, r.requirements_text, r.planning_notes,
                    r.risk_assessment_text
             FROM lesson_occurrences o
             JOIN timetable_slots s ON s.id = o.snapshot_start_slot_id
             LEFT JOIN requisitions r ON r.lesson_occurrence_id = o.id
             WHERE ' . $condition . '
             ORDER BY o.lesson_date, s.sequence_number, o.id',
        );
    }

    private function prepare(string $sql): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        if ($statement === false) throw new \RuntimeException('Unable to prepare teacher planning query.');
        return $statement;
    }

    private static function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) throw new \RuntimeException('Database returned an invalid date.');
        return $date;
    }
}
