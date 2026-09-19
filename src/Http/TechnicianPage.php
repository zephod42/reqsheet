<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use DateInterval;
use DateTimeImmutable;
use Reqsheet\Technician\TechnicianPlanningStore;

final class TechnicianPage
{
    public function __construct(private readonly TechnicianPlanningStore $store, private readonly int $organisationId, private readonly int $userId, private readonly array $user = []) {}

    public function handle(string $method, array $query, array $input): string
    {
        if (!$this->store->technicianBelongsToOrganisation($this->userId, $this->organisationId)) { http_response_code(403); return PageLayout::render('Technician', '<p class="error">Technician access is not available.</p>', $this->user); }
        $message = null;
        // Room selection is deliberately request-scoped. Personal room defaults are retained in
        // the database for compatibility, but are no longer exposed or loaded here.
        $date = $this->date((string) ($query['date'] ?? 'today'));
        if (isset($query['teacher']) || isset($query['room'])) {
            $rows = isset($query['teacher']) ? $this->store->weekForTeacher($this->organisationId, (int) $query['teacher'], $this->weekStart($date)) : $this->store->weekForRoom($this->organisationId, (int) $query['room'], $this->weekStart($date));
            return PageLayout::render('Technician inspection', $this->inspectionPage($date, $rows), $this->user);
        }
        $rooms = $this->store->roomsForOrganisation($this->organisationId);
        $defaults = array_column($rooms, 'id');
        $mode = in_array(($query['rooms'] ?? 'my'), ['all', 'my', 'custom'], true) ? (string) $query['rooms'] : 'my';
        $selected = $mode === 'all' ? array_column($rooms, 'id') : ($mode === 'custom' ? array_values(array_intersect(array_map('intval', (array) ($query['room_ids'] ?? [])), array_column($rooms, 'id'))) : array_values(array_intersect($defaults, array_column($rooms, 'id'))));
        if ($mode === 'my' && $selected === []) $selected = array_column($rooms, 'id');
        if (($query['print'] ?? '') === 'week') return PageLayout::render('Technician selected week', $this->weekPrint($date, $selected), $this->user);
        $data = $this->store->daily($this->organisationId, $date, $selected);
        $body = '<section class="page-header"><div><p class="eyebrow">Technician</p><h1>Day View</h1></div><div class="form-actions"><button type="button" onclick="window.print()">Print Selected Day</button><a class="button secondary" href="/technician?date=' . $date->format('Y-m-d') . '&rooms=' . $mode . '&print=week">Print Selected Week</a></div></section>';
        $body .= '<div class="technician-controls"><a class="week-arrow" href="/technician?date=' . $date->modify('-1 day')->format('Y-m-d') . '&rooms=' . $mode . '">‹</a><strong class="technician-date">' . $this->e($date->format('l j F Y')) . '</strong><a class="week-arrow" href="/technician?date=' . $date->modify('+1 day')->format('Y-m-d') . '&rooms=' . $mode . '">›</a><a class="button secondary" href="/technician?date=' . (new DateTimeImmutable('today'))->format('Y-m-d') . '&rooms=' . $mode . '">Today</a></div>';
        $body .= $message === null ? '' : '<p class="notice">' . $this->e($message) . '</p>';
        $body .= $this->roomControls($rooms, $defaults, $mode, $selected);
        $body .= $this->grid($date, $rooms, $selected, $data['slots'], $data['occurrences']);
        $body .= $this->inspectionLinks($date);
        return PageLayout::render('Technician daily preparation', $body, $this->user);
    }

    private function roomControls(array $rooms, array $defaults, string $mode, array $selected): string
    {
        $html = '<section class="technician-room-controls"><form method="get"><input type="hidden" name="date" value="' . $this->e((string) ($_GET['date'] ?? 'today')) . '"><label>Rooms<select name="rooms" onchange="this.form.submit()"><option value="all"' . ($mode === 'all' ? ' selected' : '') . '>All rooms</option><option value="custom"' . ($mode === 'custom' ? ' selected' : '') . '>Custom room selection</option></select></label>';
        if ($mode === 'custom') foreach ($rooms as $room) $html .= '<label class="check-label"><input type="checkbox" name="room_ids[]" value="' . (int) $room['id'] . '"' . (in_array((int) $room['id'], $selected, true) ? ' checked' : '') . '> ' . $this->e($room['code']) . '</label>';
        return $html . '<button class="secondary">Apply display</button></form></section>';
    }

    private function grid(DateTimeImmutable $date, array $rooms, array $selected, array $slots, array $occurrences): string
    {
        $rooms = array_values(array_filter($rooms, static fn (array $room): bool => in_array((int) $room['id'], $selected, true)));
        $byRoom = []; foreach ($occurrences as $occurrence) $byRoom[(string) $occurrence['snapshot_room_code']][] = $occurrence;
        $html = '<section class="technician-sheet"><h2 class="print-date">Day View · ' . $this->e($date->format('l j F Y')) . '</h2><div class="timetable-scroll"><table class="technician-grid"><thead><tr><th>Period</th>'; foreach ($rooms as $room) $html .= '<th>' . $this->e($room['code']) . '</th>'; $html .= '</tr></thead><tbody>';
        foreach ($slots as $slot) { $separator = ($slot['kind'] ?? '') !== 'teaching'; $html .= '<tr' . ($separator ? ' class="technician-separator"' : '') . '><th>' . $this->e((string) $slot['label']) . '</th>'; foreach ($rooms as $room) { $occurrence = $separator ? null : $this->occurrenceForSlot($byRoom[$room['code']] ?? [], $slot, $slots); $html .= '<td>' . ($separator ? '' : ($occurrence === null ? '<span class="room-free">ROOM FREE</span>' : $this->cell($occurrence))) . '</td>'; } $html .= '</tr>'; }
        return $html . '</tbody></table></div></section>';
    }

    private function occurrenceForSlot(array $occurrences, array $slot, array $slots): ?array
    {
        foreach ($occurrences as $occurrence) {
            foreach ($slots as $candidate) if ((int) $candidate['id'] === (int) $occurrence['snapshot_start_slot_id']) {
                if ((int) $candidate['sequence_number'] <= (int) $slot['sequence_number'] && (int) $slot['sequence_number'] < (int) $candidate['sequence_number'] + (int) $occurrence['snapshot_duration_periods']) return $occurrence;
            }
        }
        return null;
    }

    private function inspectionLinks(DateTimeImmutable $date): string
    {
        $html = '<section class="technician-secondary"><h2>Secondary inspection</h2><p class="muted">Inspect the same dated lessons and requisitions by teacher or room.</p><form method="get"><input type="hidden" name="date" value="' . $date->format('Y-m-d') . '"><label>Teacher<select name="teacher">';
        foreach ($this->store->teachersForOrganisation($this->organisationId) as $teacher) $html .= '<option value="' . $teacher['id'] . '">' . $this->e($teacher['name']) . '</option>';
        $html .= '</select></label><button class="secondary">Open teacher week</button></form><form method="get"><input type="hidden" name="date" value="' . $date->format('Y-m-d') . '"><label>Room<select name="room">';
        foreach ($this->store->roomsForOrganisation($this->organisationId) as $room) $html .= '<option value="' . $room['id'] . '">' . $this->e($room['code']) . '</option>';
        return $html . '</select></label><button class="secondary">Open room week</button></form></section>';
    }

    private function inspectionPage(DateTimeImmutable $date, array $rows): string
    {
        $html = '<section class="page-header"><div><p class="eyebrow">Technician</p><h1>Week inspection</h1></div><a class="button secondary" href="/technician?date=' . $date->format('Y-m-d') . '">Day View</a></section><p class="context">Week beginning ' . $this->e($this->weekStart($date)->format('j F Y')) . '</p><div class="timetable-scroll"><table class="people-table"><thead><tr><th>Date</th><th>Period</th><th>Class</th><th>Room</th><th>Requisition</th></tr></thead><tbody>';
        foreach ($rows as $row) $html .= '<tr><td>' . $this->e((string) $row['lesson_date']) . '</td><td>' . $this->e((string) $row['period_label']) . '</td><td>' . $this->e((string) $row['snapshot_class_code']) . '</td><td>' . $this->e((string) $row['snapshot_room_code']) . '</td><td>' . $this->e((string) (($row['requirements_text'] ?? '') ?: 'Not requisitioned')) . '</td></tr>';
        return $html . '</tbody></table></div>';
    }

    private function cell(array $occurrence): string
    {
        $text = trim((string) ($occurrence['requirements_text'] ?? '')); $label = $text === '' ? (($occurrence['state'] ?? '') === 'nothing_required' ? 'Nothing required' : 'Not requisitioned') : $text;
        return '<details class="technician-cell"><summary><span class="technician-cell-heading"><strong>' . $this->e((string) $occurrence['snapshot_class_code']) . '</strong><span>' . $this->e((string) ($occurrence['teacher_initials'] ?? $occurrence['teacher_name'])) . '</span></span><span class="technician-requisition">' . $this->e($label) . '</span></summary><div class="technician-detail"><strong>' . $this->e((string) $occurrence['teacher_name']) . '</strong> · ' . $this->e((string) $occurrence['snapshot_class_code']) . ' · ' . $this->e((string) $occurrence['snapshot_room_code']) . '<br>' . $this->e((string) $occurrence['lesson_date']) . ' · ' . $this->e((string) $occurrence['period_label']) . '<p>' . nl2br($this->e($text === '' ? 'No requisition has been entered.' : $text)) . '</p><details><summary>Lesson details</summary><p>Lesson outline: ' . nl2br($this->e((string) ($occurrence['planning_notes'] ?? 'Not entered.'))) . '</p><p>Risk assessment: ' . nl2br($this->e((string) ($occurrence['risk_assessment_text'] ?? 'Not entered.'))) . '</p></details></div></details>';
    }

    private function weekPrint(DateTimeImmutable $date, array $selected): string
    {
        $days = method_exists($this->store, 'workingDays') ? $this->store->workingDays($this->organisationId) : [1, 2, 3, 4, 5];
        $first = (int) ($days[0] ?? 1);
        $start = $date->modify('-' . ((int) $date->format('N') - $first + 7) % 7 . ' days');
        $html = '<main class="technician-week-print">';
        $rooms = $this->store->roomsForOrganisation($this->organisationId);
        foreach ($days as $day) { $dayDate = $start->modify('+' . (((int) $day - $first + 7) % 7) . ' days'); $data = $this->store->daily($this->organisationId, $dayDate, $selected); $html .= $this->grid($dayDate, $rooms, $selected, $data['slots'], $data['occurrences']); }
        return $html . '</main>';
    }

    private function date(string $value): DateTimeImmutable { if ($value === 'today' || $value === '') return new DateTimeImmutable('today'); $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date !== false && $date->format('Y-m-d') === $value ? $date : new DateTimeImmutable('today'); }
    private function weekStart(DateTimeImmutable $date): DateTimeImmutable { return $date->modify('-' . ((int) $date->format('N') - 1) . ' days'); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
