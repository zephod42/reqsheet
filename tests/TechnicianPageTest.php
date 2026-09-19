<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\TechnicianPage;
use Reqsheet\Technician\TechnicianPlanningStore;

final class TechnicianPageTest
{
    public static function run(): void
    {
        $store = new TechnicianPageStoreFake();
        $page = new TechnicianPage($store, 1, 20, ['display_name' => 'Tech', 'roles' => ['technician']]);
        $day = $page->handle('GET', ['date' => '2026-09-21', 'rooms' => 'all'], []);
        assertContainsValue('JSM', $day, 'Technician grid did not show teacher initials.');
        assertContainsValue('Very long requisition text', $day, 'Technician grid did not show the saved requisition.');
        assertContainsValue('title="Very long requisition text', $day, 'Technician grid did not expose full requisition text for hover.');
        assertContainsValue('ROOM FREE', $day, 'Technician grid did not distinguish a genuinely free room.');
        assertNotContainsValue('Save My rooms', $day, 'Personal room saving remained exposed.');

        $print = $page->handle('GET', ['date' => '2026-09-23', 'rooms' => 'all', 'print' => 'week'], []);
        assertSameValue(3, substr_count($print, 'class="technician-sheet"'), 'Selected-week print did not use the configured working days.');
        assertContainsValue('window.print()', $print, 'Selected-week print did not invoke the native print dialog.');
        assertContainsValue('Day View · Monday 21 September 2026', $print, 'Selected week was not calculated from the selected date.');
        assertContainsValue('Day View · Thursday 24 September 2026', $print, 'Non-standard working-day print date was incorrect.');
        assertContainsValue('21-09-2026 Mon', $page->handle('GET', ['date' => '2026-09-21', 'teacher' => 10], []), 'Secondary technician date format was not UK-style.');
    }
}

final class TechnicianPageStoreFake implements TechnicianPlanningStore
{
    public function technicianBelongsToOrganisation(int $userId, int $organisationId): bool { return $userId === 20 && $organisationId === 1; }
    public function roomsForOrganisation(int $organisationId): array { return [['id' => 1, 'code' => 'LAB-A'], ['id' => 2, 'code' => 'LAB-B']]; }
    public function teachersForOrganisation(int $organisationId): array { return [['id' => 10, 'name' => 'John Smith']]; }
    public function defaultRoomIds(int $organisationId, int $userId): array { return [1]; }
    public function saveDefaultRoomIds(int $organisationId, int $userId, array $roomIds): void {}
    public function workingDays(int $organisationId): array { return [1, 4, 5]; }
    public function workingWeekStart(int $organisationId, DateTimeImmutable $date): DateTimeImmutable { return new DateTimeImmutable('2026-09-21'); }
    public function daily(int $organisationId, DateTimeImmutable $date, array $roomIds): array
    {
        return [
            'version' => ['id' => 1],
            'slots' => [
                ['id' => 1, 'sequence_number' => 1, 'kind' => 'teaching', 'label' => 'P1'],
                ['id' => 2, 'sequence_number' => 2, 'kind' => 'teaching', 'label' => 'P2'],
            ],
            'occurrences' => [[
                'id' => 50, 'lesson_date' => $date->format('Y-m-d'), 'snapshot_teacher_user_id' => 10,
                'teacher_name' => 'John Smith', 'teacher_initials' => 'JSM', 'snapshot_class_code' => '9A/Sc1',
                'snapshot_room_code' => 'LAB-A', 'snapshot_start_slot_id' => 1, 'snapshot_duration_periods' => 2,
                'period_label' => 'P1', 'state' => 'requirements_entered', 'requirements_text' => 'Very long requisition text',
                'planning_notes' => null, 'risk_assessment_text' => null,
            ]],
        ];
    }
    public function weekForTeacher(int $organisationId, int $teacherId, DateTimeImmutable $start): array { return [['lesson_date' => '2026-09-21', 'period_label' => 'P1', 'snapshot_class_code' => '9A/Sc1', 'snapshot_room_code' => 'LAB-A', 'requirements_text' => 'Very long requisition text']]; }
    public function weekForRoom(int $organisationId, int $roomId, DateTimeImmutable $start): array { return $this->weekForTeacher($organisationId, 10, $start); }
}
