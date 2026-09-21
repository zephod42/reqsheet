<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use Reqsheet\Http\TechnicianPage;
use Reqsheet\Http\CsrfToken;
use Reqsheet\Technician\PdoTechnicianPlanningStore;
use Reqsheet\Technician\TechnicianPlanningStore;

final class TechnicianPageTest
{
    public static function run(): void
    {
        $pdo = new TechnicianRecordingPdo();
        (new PdoTechnicianPlanningStore($pdo))->daily(7, new DateTimeImmutable('2026-09-18'), []);
        assertSameValue(['id' => 7, 'effective_from_date' => '2026-09-18', 'effective_to_date' => '2026-09-18'], $pdo->lastParameters, 'Technician effective-template lookup bound unexpected calendar parameters.');
        preg_match_all('/:([a-z_]+)/', $pdo->lastSql, $placeholders);
        assertSameValue(count($placeholders[1]), count(array_unique($placeholders[1])), 'Technician effective-template lookup reused a named placeholder that native MySQL PDO cannot bind.');

        $store = new TechnicianPageStoreFake();
        $page = new TechnicianPage($store, 1, 20, ['staff_identifier' => 'TEC', 'roles' => ['technician']]);
        $warning = null;
        set_error_handler(static function (int $severity, string $message): bool {
            throw new \ErrorException($message, 0, $severity);
        }, E_WARNING);
        try {
            $page->handle('GET', ['date' => '2026-09-21'], []);
        } catch (\ErrorException $exception) {
            $warning = $exception->getMessage();
        } finally {
            restore_error_handler();
        }
        assertSameValue(null, $warning, 'Technician navigation without a rooms query parameter emitted a warning.');
        $store->rooms = [];
        $empty = $page->handle('GET', ['date' => '2026-09-21'], []);
        assertContainsValue('No rooms have been configured', $empty, 'Technician view did not provide an empty-room state.');
        $store->rooms = [['id' => 1, 'code' => 'LAB-A'], ['id' => 2, 'code' => 'LAB-B']];
        $store->hasActiveTimetable = false;
        assertContainsValue('No active timetable is configured', $page->handle('GET', ['date' => '2026-09-21', 'rooms' => 'all'], []), 'Technician view did not provide an inactive-timetable state.');
        $store->hasActiveTimetable = true;
        $day = $page->handle('GET', ['date' => '2026-09-21', 'rooms' => 'all'], []);
        assertContainsValue('class="alpha-banner"', $day, 'Authenticated technician view did not render the global alpha banner.');
        assertContainsValue('JSM', $day, 'Technician grid did not show teacher initials.');
        assertContainsValue('class="technician-cell class-tone-', $day, 'Technician lesson did not receive a deterministic class colour.');
        assertContainsValue('Very long requisition text', $day, 'Technician grid did not show the saved requisition.');
        assertContainsValue('title="Very long requisition text', $day, 'Technician grid did not expose full requisition text for hover.');
        assertContainsValue('ROOM FREE', $day, 'Technician grid did not distinguish a genuinely free room.');
        assertContainsValue('Mark as Prepped', $day, 'Occupied technician lesson did not expose preparation control.');
        assertNotContainsValue('Save My rooms', $day, 'Personal room saving remained exposed.');

        $store->deletedTeacher = true;
        $deletedDay = $page->handle('GET', ['date' => '2026-09-21', 'rooms' => 'all'], []);
        assertContainsValue('???', $deletedDay, 'Deleted teacher lessons were not rendered with the agreed placeholder.');
        assertNotContainsValue('JSM', $deletedDay, 'Deleted teacher initials leaked into the technician view.');
        $store->deletedTeacher = false;

        $prepared = $page->handle('POST', [], ['date' => '2026-09-21', 'action' => 'set_prepared', 'occurrence_id' => 50, 'prepared' => 'yes', 'csrf_token' => CsrfToken::value()]);
        assertContainsValue('Lesson marked as prepped', $prepared, 'Technician preparation update did not report success.');
        assertSameValue([1, 20, 50, true], $store->preparationUpdate, 'Technician preparation update was not tenant/user scoped.');

        $print = $page->handle('GET', ['date' => '2026-09-23', 'rooms' => 'all', 'print' => 'week'], []);
        assertSameValue(3, substr_count($print, 'class="technician-sheet"'), 'Selected-week print did not use the configured working days.');
        assertContainsValue('window.print()', $print, 'Selected-week print did not invoke the native print dialog.');
        assertContainsValue('class="alpha-banner"', $print, 'Technician print page did not retain the global alpha banner in the screen document.');
        assertContainsValue('<h1>Print View</h1>', $print, 'Selected-week print did not identify the read-only print view.');
        assertContainsValue('Back to Technician View', $print, 'Selected-week print did not provide a return link.');
        assertContainsValue('href="/technician?date=2026-09-23&rooms=all"', $print, 'Selected-week print did not preserve the selected date and room mode.');
        assertNotContainsValue('name="action" value="set_prepared"', $print, 'Selected-week print exposed a preparation form.');
        assertNotContainsValue('Mark as Prepped', $print, 'Selected-week print exposed the interactive preparation action.');
        assertNotContainsValue('Mark as Not Prepped', $print, 'Selected-week print exposed the interactive un-preparation action.');
        assertContainsValue('Day View · Monday 21 September 2026', $print, 'Selected week was not calculated from the selected date.');
        assertContainsValue('Day View · Thursday 24 September 2026', $print, 'Non-standard working-day print date was incorrect.');
        $css = (string) file_get_contents(__DIR__ . '/../public/assets/app.css');
        assertContainsValue('break-inside: avoid', $css, 'Technician weekly print sheets did not prevent internal pagination splits.');
        assertNotContainsValue('.technician-week-print .technician-sheet { min-height: 100vh', $css, 'Technician weekly print retained the overflow-causing viewport height.');
        assertContainsValue('.technician-grid th:first-child { width: 6rem; min-width: max-content;', $css, 'Technician period column was not modestly narrowed.');
        assertContainsValue('.technician-cell-heading { display: grid; grid-template-columns: minmax(0, 1fr) auto minmax(0, 1fr); align-items: center; gap: .35rem; font-size: .9em; }', $css, 'Technician class and teacher labels were not compacted consistently.');
        assertContainsValue('.print-date { margin: 0 0 .7rem; font-size: 1rem; text-align: center; }', $css, 'Technician print day heading was not centred.');
        assertContainsValue('.site-nav, .alpha-banner, .page-header a, dialog', $css, 'Alpha banner was not excluded from print output.');
        $store->dailyCalls = [];
        $customDay = $page->handle('GET', ['date' => '2026-09-23', 'rooms' => 'custom', 'room_ids' => ['2']], []);
        assertContainsValue('href="/technician?date=2026-09-23&rooms=custom&room_ids[]=2&print=week"', $customDay, 'Custom room selection was not passed from Day View to Week Print.');
        assertSameValue([2], $store->dailyCalls[array_key_last($store->dailyCalls)], 'Day View did not pass the single custom room to its query.');
        assertNotContainsValue('LAB-A</th>', $customDay, 'An unselected room appeared in the custom Day View.');
        $store->dailyCalls = [];
        $customPrint = $page->handle('GET', ['date' => '2026-09-23', 'rooms' => 'custom', 'room_ids' => ['2'], 'print' => 'week'], []);
        assertContainsValue('href="/technician?date=2026-09-23&rooms=custom&room_ids[]=2"', $customPrint, 'Custom room selection was not preserved by the print return link.');
        assertSameValue([[2], [2], [2]], $store->dailyCalls, 'Week Print did not apply the single custom room to every printed day.');
        assertContainsValue('LAB-B</th>', $customPrint, 'The selected room was missing from the custom Week Print.');
        assertNotContainsValue('LAB-A</th>', $customPrint, 'An unselected room appeared in the custom Week Print.');
        $store->dailyCalls = [];
        $multiPrint = $page->handle('GET', ['date' => '2026-09-23', 'rooms' => 'custom', 'room_ids' => ['2', '1'], 'print' => 'week'], []);
        assertSameValue([[2, 1], [2, 1], [2, 1]], $store->dailyCalls, 'Multiple custom rooms were not applied to every printed day in the requested order.');
        assertContainsValue('LAB-A</th><th>LAB-B</th>', $multiPrint, 'Custom Week Print did not retain the existing room order.');
        assertContainsValue('21-09-2026 Mon', $page->handle('GET', ['date' => '2026-09-21', 'teacher' => 10], []), 'Secondary technician date format was not UK-style.');
    }
}

final class TechnicianRecordingPdo extends PDO
{
    public string $lastSql = '';
    public array $lastParameters = [];

    public function __construct() {}

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->lastSql = $query;
        return new TechnicianRecordingStatement($this);
    }
}

final class TechnicianRecordingStatement extends PDOStatement
{
    public function __construct(private readonly TechnicianRecordingPdo $pdo) {}

    public function execute(?array $params = null): bool
    {
        $this->pdo->lastParameters = $params ?? [];
        return true;
    }

    public function fetch(?int $mode = null, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return false;
    }
}

final class TechnicianPageStoreFake implements TechnicianPlanningStore
{
    public array $rooms = [['id' => 1, 'code' => 'LAB-A'], ['id' => 2, 'code' => 'LAB-B']];
    public bool $hasActiveTimetable = true;
    public bool $deletedTeacher = false;
    public array $preparationUpdate = [];
    public array $dailyCalls = [];
    public function technicianBelongsToOrganisation(int $userId, int $organisationId): bool { return $userId === 20 && $organisationId === 1; }
    public function roomsForOrganisation(int $organisationId): array { return $this->rooms; }
    public function teachersForOrganisation(int $organisationId): array { return [['id' => 10, 'name' => 'John Smith']]; }
    public function defaultRoomIds(int $organisationId, int $userId): array { return [1]; }
    public function saveDefaultRoomIds(int $organisationId, int $userId, array $roomIds): void {}
    public function setPrepared(int $organisationId, int $userId, int $occurrenceId, bool $prepared): bool { $this->preparationUpdate = [$organisationId, $userId, $occurrenceId, $prepared]; return true; }
    public function workingDays(int $organisationId): array { return [1, 4, 5]; }
    public function workingWeekStart(int $organisationId, DateTimeImmutable $date): DateTimeImmutable { return new DateTimeImmutable('2026-09-21'); }
    public function daily(int $organisationId, DateTimeImmutable $date, array $roomIds): array
    {
        $this->dailyCalls[] = $roomIds;
        return [
            'version' => $this->hasActiveTimetable ? ['id' => 1] : null,
            'slots' => [
                ['id' => 1, 'sequence_number' => 1, 'kind' => 'teaching', 'label' => 'P1'],
                ['id' => 2, 'sequence_number' => 2, 'kind' => 'teaching', 'label' => 'P2'],
            ],
            'occurrences' => [[
                'id' => 50, 'lesson_date' => $date->format('Y-m-d'), 'snapshot_teacher_user_id' => 10,
                'teacher_name' => $this->deletedTeacher ? '???' : 'John Smith', 'teacher_initials' => $this->deletedTeacher ? '???' : 'JSM', 'snapshot_class_code' => '9A/Sc1',
                'snapshot_room_code' => 'LAB-A', 'snapshot_start_slot_id' => 1, 'snapshot_duration_periods' => 2,
                'prepared_at' => null,
                'period_label' => 'P1', 'state' => 'requirements_entered', 'requirements_text' => 'Very long requisition text',
                'planning_notes' => null, 'risk_assessment_text' => null,
            ], [
                'id' => 51, 'lesson_date' => $date->format('Y-m-d'), 'snapshot_teacher_user_id' => 10,
                'teacher_name' => $this->deletedTeacher ? '???' : 'John Smith', 'teacher_initials' => $this->deletedTeacher ? '???' : 'JSM', 'snapshot_class_code' => '10B/Sc1',
                'snapshot_room_code' => 'LAB-B', 'snapshot_start_slot_id' => 1, 'snapshot_duration_periods' => 1,
                'prepared_at' => null, 'period_label' => 'P1', 'state' => 'requirements_entered', 'requirements_text' => 'LAB-B requisition',
                'planning_notes' => null, 'risk_assessment_text' => null,
            ]],
        ];
    }
    public function weekForTeacher(int $organisationId, int $teacherId, DateTimeImmutable $start): array { return [['lesson_date' => '2026-09-21', 'period_label' => 'P1', 'snapshot_class_code' => '9A/Sc1', 'snapshot_room_code' => 'LAB-A', 'requirements_text' => 'Very long requisition text']]; }
    public function weekForRoom(int $organisationId, int $roomId, DateTimeImmutable $start): array { return $this->weekForTeacher($organisationId, 10, $start); }
}
