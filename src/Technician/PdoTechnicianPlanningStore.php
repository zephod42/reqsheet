<?php

declare(strict_types=1);

namespace Reqsheet\Technician;

use DateTimeImmutable;
use PDO;
use Reqsheet\Timetable\PdoTimetableGenerationStore;
use Reqsheet\Timetable\TimetableOccurrenceGenerator;

final class PdoTechnicianPlanningStore implements TechnicianPlanningStore
{
    public function __construct(private readonly PDO $pdo) {}

    public function technicianBelongsToOrganisation(int $userId, int $organisationId): bool
    {
        $s = $this->pdo->prepare("SELECT 1 FROM users WHERE id = :id AND organisation_id = :organisation_id AND (is_technician = TRUE OR operational_role = 'technician') AND is_active = TRUE");
        $s->execute(['id' => $userId, 'organisation_id' => $organisationId]); return $s->fetchColumn() !== false;
    }

    public function roomsForOrganisation(int $organisationId): array
    {
        $s = $this->pdo->prepare('SELECT id, room_code AS code FROM organisation_rooms WHERE organisation_id = :organisation_id ORDER BY room_code');
        $s->execute(['organisation_id' => $organisationId]); return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code']], $s->fetchAll());
    }
    public function workingDays(int $organisationId): array
    {
        $s = $this->pdo->prepare('SELECT working_days FROM organisation_settings WHERE organisation_id = :id'); $s->execute(['id' => $organisationId]);
        $value = $s->fetchColumn(); return $value === false ? [1, 2, 3, 4, 5] : array_values(array_filter(array_map('intval', explode(',', (string) $value)), static fn (int $day): bool => $day >= 1 && $day <= 7));
    }

    public function teachersForOrganisation(int $organisationId): array
    {
        $s = $this->pdo->prepare("SELECT id, staff_identifier AS name FROM users WHERE organisation_id = :organisation_id AND is_active = TRUE AND (is_teacher = TRUE OR operational_role = 'teacher') ORDER BY staff_identifier");
        $s->execute(['organisation_id' => $organisationId]); return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name']], $s->fetchAll());
    }

    public function defaultRoomIds(int $organisationId, int $userId): array
    {
        $s = $this->pdo->prepare('SELECT room_id FROM technician_room_preferences WHERE organisation_id = :organisation_id AND user_id = :user_id ORDER BY room_id');
        $s->execute(['organisation_id' => $organisationId, 'user_id' => $userId]); return array_map('intval', $s->fetchAll(PDO::FETCH_COLUMN));
    }

    public function saveDefaultRoomIds(int $organisationId, int $userId, array $roomIds): void
    {
        if (!$this->pdo->beginTransaction()) throw new \RuntimeException('Unable to save room preference.');
        try {
            $delete = $this->pdo->prepare('DELETE FROM technician_room_preferences WHERE organisation_id = :organisation_id AND user_id = :user_id');
            $delete->execute(['organisation_id' => $organisationId, 'user_id' => $userId]);
            $insert = $this->pdo->prepare('INSERT INTO technician_room_preferences (organisation_id, user_id, room_id) SELECT :organisation_id, :user_id, id FROM organisation_rooms WHERE id = :room_id AND organisation_id = :organisation_id');
            foreach (array_values(array_unique(array_map('intval', $roomIds))) as $roomId) $insert->execute(['organisation_id' => $organisationId, 'user_id' => $userId, 'room_id' => $roomId]);
            $this->pdo->commit();
        } catch (\Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }

    public function setPrepared(int $organisationId, int $userId, int $occurrenceId, bool $prepared): bool
    {
        // Keep the permission and tenant checks in the data layer as well as at the
        // route boundary: this prevents a forged occurrence id being used by a
        // technician from another organisation.
        if (!$this->technicianBelongsToOrganisation($userId, $organisationId)) return false;
        $exists = $this->pdo->prepare('SELECT 1 FROM lesson_occurrences WHERE id = :occurrence_id AND organisation_id = :organisation_id');
        $exists->execute(['occurrence_id' => $occurrenceId, 'organisation_id' => $organisationId]);
        if ($exists->fetchColumn() === false) return false;
        $s = $this->pdo->prepare(
            'UPDATE lesson_occurrences
             SET prepared_at = ' . ($prepared ? 'UTC_TIMESTAMP(6)' : 'NULL') . '
             WHERE id = :occurrence_id AND organisation_id = :organisation_id'
        );
        $s->execute(['occurrence_id' => $occurrenceId, 'organisation_id' => $organisationId]);
        return true;
    }

    public function daily(int $organisationId, DateTimeImmutable $date, array $roomIds): array
    {
        $version = $this->version($organisationId, $date);
        if ($version === null) return ['version' => null, 'slots' => [], 'occurrences' => []];
        if ($date >= new DateTimeImmutable('today')) (new TimetableOccurrenceGenerator(new PdoTimetableGenerationStore($this->pdo)))->generate($organisationId, (int) $version['id'], $date->format('Y-m-d'), $date->format('Y-m-d'));
        $slots = $this->slots((int) $version['id'], (int) $date->format('N'));
        $params = ['organisation_id' => $organisationId, 'lesson_date' => $date->format('Y-m-d')];
        $roomSql = '';
        if ($roomIds !== []) {
            $codes = [];
            foreach ($this->roomsForOrganisation($organisationId) as $room) if (in_array((int) $room['id'], $roomIds, true)) $codes[] = $room['code'];
            $roomSql = $codes === [] ? ' AND 1 = 0' : ' AND o.snapshot_room_code IN (' . implode(',', array_fill(0, count($codes), '?')) . ')';
        } else $codes = [];
        $sql = "SELECT o.id, o.lesson_date, o.snapshot_teacher_user_id, CASE WHEN u.is_active = TRUE THEN u.staff_identifier ELSE '???' END AS teacher_name, CASE WHEN u.is_active = TRUE THEN u.staff_identifier ELSE '???' END AS teacher_initials, o.snapshot_class_code, o.snapshot_room_code, o.snapshot_start_slot_id, o.snapshot_duration_periods, o.prepared_at, s.sequence_number, s.label AS period_label, r.state, r.requirements_text, r.planning_notes, r.risk_assessment_text FROM lesson_occurrences o LEFT JOIN users u ON u.id = o.snapshot_teacher_user_id JOIN timetable_slots s ON s.id = o.snapshot_start_slot_id LEFT JOIN requisitions r ON r.lesson_occurrence_id = o.id WHERE o.organisation_id = ? AND o.lesson_date = ?" . $roomSql . ' ORDER BY s.sequence_number, o.snapshot_room_code, o.id';
        $statement = $this->pdo->prepare($sql); $statement->execute(array_merge([$organisationId, $date->format('Y-m-d')], $codes));
        return ['version' => $version, 'slots' => $slots, 'occurrences' => $statement->fetchAll()];
    }

    public function weekForTeacher(int $organisationId, int $teacherId, DateTimeImmutable $start): array { return $this->inspection($organisationId, 'snapshot_teacher_user_id', $teacherId, $start); }
    public function weekForRoom(int $organisationId, int $roomId, DateTimeImmutable $start): array { $rooms = $this->roomsForOrganisation($organisationId); $code = ''; foreach ($rooms as $room) if ($room['id'] === $roomId) $code = $room['code']; return $this->inspection($organisationId, 'snapshot_room_code', $code, $start); }

    private function inspection(int $organisationId, string $column, int|string $value, DateTimeImmutable $start): array
    {
        $s = $this->pdo->prepare("SELECT o.lesson_date, o.snapshot_class_code, o.snapshot_room_code, s.label AS period_label, r.requirements_text FROM lesson_occurrences o JOIN timetable_slots s ON s.id = o.snapshot_start_slot_id LEFT JOIN requisitions r ON r.lesson_occurrence_id = o.id WHERE o.organisation_id = :organisation_id AND o.{$column} = :value AND o.lesson_date BETWEEN :start AND :end ORDER BY o.lesson_date, s.sequence_number");
        $s->execute(['organisation_id' => $organisationId, 'value' => $value, 'start' => $start->format('Y-m-d'), 'end' => $start->modify('+6 days')->format('Y-m-d')]); return $s->fetchAll();
    }

    public function workingWeekStart(int $organisationId, DateTimeImmutable $date): DateTimeImmutable
    {
        $version = $this->version($organisationId, $date);
        $first = $version === null ? 1 : (int) $version['first_day_of_week'];
        return $date->modify('-' . (((int) $date->format('N') - $first + 7) % 7) . ' days');
    }

    private function version(int $organisationId, DateTimeImmutable $date): ?array
    {
        $s = $this->pdo->prepare('SELECT tv.id, tv.label, tv.first_day_of_week FROM timetable_versions tv JOIN organisations o ON o.id = tv.organisation_id AND o.active_timetable_version_id IS NOT NULL WHERE tv.organisation_id = :id AND tv.effective_from <= :effective_from_date AND (tv.effective_to IS NULL OR tv.effective_to > :effective_to_date) ORDER BY tv.effective_from DESC, tv.id DESC LIMIT 1');
        $s->execute(['id' => $organisationId, 'effective_from_date' => $date->format('Y-m-d'), 'effective_to_date' => $date->format('Y-m-d')]); $r = $s->fetch(); return $r === false ? null : $r;
    }
    private function slots(int $versionId, int $day): array { $s = $this->pdo->prepare('SELECT id, sequence_number, kind, teaching_period_number, label FROM timetable_slots WHERE timetable_version_id = :version_id AND day_of_week = :day ORDER BY sequence_number'); $s->execute(['version_id' => $versionId, 'day' => $day]); return $s->fetchAll(); }
}
