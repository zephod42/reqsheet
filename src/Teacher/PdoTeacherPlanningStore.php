<?php

declare(strict_types=1);

namespace Reqsheet\Teacher;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;
use Reqsheet\Timetable\PdoTimetableGenerationStore;
use Reqsheet\Timetable\TimetableOccurrenceGenerator;

final class PdoTeacherPlanningStore implements TeacherPlanningStore
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function teacherBelongsToOrganisation(int $teacherId, int $organisationId): bool
    {
        $statement = $this->prepare("SELECT 1 FROM users WHERE id = :teacher_id AND organisation_id = :organisation_id AND is_active = TRUE AND (is_teacher = TRUE OR operational_role = 'teacher')");
        $statement->execute(['teacher_id' => $teacherId, 'organisation_id' => $organisationId]);
        return $statement->fetchColumn() !== false;
    }

    public function classesForTeacher(int $organisationId, int $teacherId): array
    {
        $statement = $this->prepare(
            'SELECT DISTINCT c.id, c.class_code
             FROM recurring_lessons rl
             JOIN timetable_versions tv ON tv.id = rl.timetable_version_id AND tv.organisation_id = :organisation_id
             JOIN organisation_classes c ON c.id = rl.class_id AND c.organisation_id = :class_organisation_id
             WHERE rl.teacher_user_id = :teacher_id
             ORDER BY c.class_code, c.id',
        );
        $statement->execute(['organisation_id' => $organisationId, 'class_organisation_id' => $organisationId, 'teacher_id' => $teacherId]);
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'code' => (string) $row['class_code']], $statement->fetchAll());
    }

    public function effectiveVersion(int $organisationId, DateTimeImmutable $date): ?TimetableVersion
    {
        $statement = $this->prepare(
            'SELECT tv.id, tv.organisation_id, tv.label, tv.effective_from, tv.effective_to, tv.first_day_of_week
             FROM timetable_versions tv
             JOIN organisations o ON o.id = tv.organisation_id AND o.active_timetable_version_id IS NOT NULL
             WHERE tv.organisation_id = :organisation_id
               AND tv.effective_from <= :effective_from_date
               AND (tv.effective_to IS NULL OR tv.effective_to > :effective_to_date)
             ORDER BY tv.effective_from DESC, tv.id DESC LIMIT 1',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'effective_from_date' => $date->format('Y-m-d'),
            'effective_to_date' => $date->format('Y-m-d'),
        ]);
        $row = $statement->fetch();
        if ($row === false) return null;
        return new TimetableVersion(
            (int) $row['id'],
            (int) $row['organisation_id'],
            $row['label'] === null ? null : (string) $row['label'],
            self::date((string) $row['effective_from']),
            $row['effective_to'] === null ? null : self::date((string) $row['effective_to']),
            (int) $row['first_day_of_week'],
        );
    }

    public function activeFirstDayOfWeek(int $organisationId): int
    {
        $statement = $this->prepare('SELECT tv.first_day_of_week FROM organisations o JOIN timetable_versions tv ON tv.id = o.active_timetable_version_id WHERE o.id = :organisation_id');
        $statement->execute(['organisation_id' => $organisationId]);
        $value = $statement->fetchColumn();
        return $value === false ? 1 : (int) $value;
    }

    public function ensureOccurrencesForWeek(int $organisationId, DateTimeImmutable $start, DateTimeImmutable $end): void
    {
        $this->ensureOccurrencesForRange($organisationId, $start, $end);
    }

    public function ensureOccurrencesForRange(
        int $organisationId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?int $teacherId = null,
        ?int $classId = null,
    ): void {
        if (($teacherId === null) !== ($classId === null)) {
            throw new \InvalidArgumentException('Teacher and class filters must be supplied together.');
        }

        $generator = new TimetableOccurrenceGenerator(new PdoTimetableGenerationStore($this->pdo));
        $lessonIdsByVersion = $teacherId === null ? null : $this->lessonIdsForTeacherClass($organisationId, $teacherId, $classId);
        foreach ($this->effectiveVersionRanges($organisationId, $start, $end) as $range) {
            $lessonIds = $lessonIdsByVersion === null ? null : ($lessonIdsByVersion[$range['version_id']] ?? []);
            if ($lessonIds === []) continue;
            $generator->generate(
                $organisationId,
                $range['version_id'],
                $range['start']->format('Y-m-d'),
                $range['end']->format('Y-m-d'),
                $lessonIds,
            );
        }
    }

    /** @return array<int, list<int>> */
    private function lessonIdsForTeacherClass(int $organisationId, int $teacherId, int $classId): array
    {
        $statement = $this->prepare(
            'SELECT rl.id, rl.timetable_version_id
             FROM recurring_lessons rl
             JOIN timetable_versions tv ON tv.id = rl.timetable_version_id AND tv.organisation_id = :organisation_id
             JOIN organisation_classes c ON c.id = rl.class_id AND c.organisation_id = :class_organisation_id
             WHERE rl.teacher_user_id = :teacher_id AND rl.class_id = :class_id',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'class_organisation_id' => $organisationId,
            'teacher_id' => $teacherId,
            'class_id' => $classId,
        ]);
        $ids = [];
        foreach ($statement->fetchAll() as $row) {
            $ids[(int) $row['timetable_version_id']][] = (int) $row['id'];
        }
        return $ids;
    }

    /** @return list<array{version_id:int,start:DateTimeImmutable,end:DateTimeImmutable}> */
    private function effectiveVersionRanges(int $organisationId, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $statement = $this->prepare(
            'SELECT tv.id, tv.effective_from, tv.effective_to
             FROM timetable_versions tv
             JOIN organisations o ON o.id = tv.organisation_id AND o.active_timetable_version_id IS NOT NULL
             WHERE tv.organisation_id = :organisation_id
               AND tv.effective_from <= :end_date
               AND (tv.effective_to IS NULL OR tv.effective_to > :start_date)
             ORDER BY tv.effective_from DESC, tv.id DESC',
        );
        $statement->execute([
            'organisation_id' => $organisationId,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
        ]);
        $versions = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'from' => self::date((string) $row['effective_from']),
            'to' => $row['effective_to'] === null ? null : self::date((string) $row['effective_to']),
        ], $statement->fetchAll());

        $ranges = [];
        for ($date = $start; $date <= $end; $date = $date->modify('+1 day')) {
            $versionId = null;
            foreach ($versions as $version) {
                if ($version['from'] <= $date && ($version['to'] === null || $version['to'] > $date)) {
                    $versionId = $version['id'];
                    break;
                }
            }
            if ($versionId === null) continue;
            $last = array_key_last($ranges);
            if ($last !== null && $ranges[$last]['version_id'] === $versionId && $ranges[$last]['end']->modify('+1 day') == $date) {
                $ranges[$last]['end'] = $date;
            } else {
                $ranges[] = ['version_id' => $versionId, 'start' => $date, 'end' => $date];
            }
        }
        return $ranges;
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

    public function occurrencesForTeacherClass(int $organisationId, int $teacherId, int $classId, DateTimeImmutable $start, DateTimeImmutable $end, int $limit, bool $descending = false): array
    {
        $limit = max(1, min(21, $limit));
        $order = $descending ? 'DESC' : 'ASC';
        $statement = $this->occurrenceQuery(
            'o.organisation_id = :organisation_id AND o.snapshot_teacher_user_id = :teacher_id
             AND rl.class_id = :class_id AND o.lesson_date BETWEEN :start_date AND :end_date',
            'o.lesson_date ' . $order . ', s.sequence_number ' . $order . ', o.id ' . $order,
            $limit,
        );
        $statement->execute([
            'organisation_id' => $organisationId, 'teacher_id' => $teacherId, 'class_id' => $classId,
            'start_date' => $start->format('Y-m-d'), 'end_date' => $end->format('Y-m-d'),
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

    private function occurrenceQuery(string $condition, string $order = 'o.lesson_date, s.sequence_number, o.id', ?int $limit = null): PDOStatement
    {
        $limitSql = $limit === null ? '' : ' LIMIT ' . max(1, min(21, $limit));
        return $this->prepare(
            'SELECT o.id, o.lesson_date, o.timetable_version_id, o.snapshot_teacher_user_id,
                    rl.class_id,
                    o.snapshot_class_code, o.snapshot_room_code, o.snapshot_start_slot_id,
                    o.snapshot_duration_periods, s.day_of_week, s.teaching_period_number,
                    s.label AS slot_label, r.state, r.requirements_text, r.planning_notes,
                    r.risk_assessment_text
             FROM lesson_occurrences o
             JOIN recurring_lessons rl ON rl.id = o.recurring_lesson_id
             JOIN timetable_slots s ON s.id = o.snapshot_start_slot_id
             LEFT JOIN requisitions r ON r.lesson_occurrence_id = o.id
             WHERE ' . $condition . '
             ORDER BY ' . $order . $limitSql,
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
