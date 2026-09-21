<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use DateInterval;
use DateTimeImmutable;
use Reqsheet\Technician\TechnicianPlanningStore;
use Reqsheet\Timetable\ClassTone;

final class TechnicianPage
{
    public function __construct(private readonly TechnicianPlanningStore $store, private readonly int $organisationId, private readonly int $userId, private readonly array $user = []) {}

    public function handle(string $method, array $query, array $input): string
    {
        if (!$this->store->technicianBelongsToOrganisation($this->userId, $this->organisationId)) { http_response_code(403); return PageLayout::render('Technician', '<p class="error">Technician access is not available.</p>', $this->user); }
        $message = null;
        $date = $this->date((string) ($query['date'] ?? ($input['date'] ?? 'today')));
        if ($method === 'POST' && (string) ($input['action'] ?? '') === 'set_prepared') {
            if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
                http_response_code(403);
                return PageLayout::render('Technician', '<p class="error">The form expired. Please try again.</p>', $this->user);
            }
            $occurrenceId = filter_var($input['occurrence_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $state = (string) ($input['prepared'] ?? '');
            if ($occurrenceId === false || !in_array($state, ['yes', 'no'], true) || !$this->store->setPrepared($this->organisationId, $this->userId, (int) $occurrenceId, $state === 'yes')) {
                $message = 'That dated lesson could not be updated.';
            } else {
                $message = $state === 'yes' ? 'Lesson marked as prepped.' : 'Lesson marked as not prepped.';
            }
        }
        if (isset($query['teacher']) || isset($query['room'])) {
            $rows = isset($query['teacher']) ? $this->store->weekForTeacher($this->organisationId, (int) $query['teacher'], $this->weekStart($date)) : $this->store->weekForRoom($this->organisationId, (int) $query['room'], $this->weekStart($date));
            return PageLayout::render('Technician inspection', $this->inspectionPage($date, $rows), $this->user);
        }
        $rooms = $this->store->roomsForOrganisation($this->organisationId);
        $availableRoomIds = array_map('intval', array_column($rooms, 'id'));
        $hasSelection = (string) ($query['room_selection'] ?? '') === '1' || (string) ($query['rooms'] ?? '') === 'custom';
        $requestedRoomIds = array_map('intval', (array) ($query['room_ids'] ?? []));
        $selected = $hasSelection ? $this->orderedRoomIds($availableRoomIds, $requestedRoomIds) : $availableRoomIds;
        if (($query['print'] ?? '') === 'week') return PageLayout::render('Technician print view', $this->weekPrint($date, $selected), $this->user);
        $data = $this->store->daily($this->organisationId, $date, $selected);
        $roomQuery = $this->roomQuery($selected);
        $body = '<section class="page-header"><div><p class="eyebrow">Technician</p><h1>Day View</h1></div><div class="form-actions"><button type="button" onclick="window.print()">Print Selected Day</button><a class="button secondary" target="_blank" rel="noopener" href="/technician?date=' . $date->format('Y-m-d') . $roomQuery . '&print=week">Print Selected Week</a></div></section>';
        $body .= '<div class="technician-controls"><a class="week-arrow" href="/technician?date=' . $date->modify('-1 day')->format('Y-m-d') . $roomQuery . '">‹</a><strong class="technician-date">' . $this->e($date->format('l j F Y')) . '</strong><a class="week-arrow" href="/technician?date=' . $date->modify('+1 day')->format('Y-m-d') . $roomQuery . '">›</a><a class="button secondary" href="/technician?date=' . (new DateTimeImmutable('today'))->format('Y-m-d') . $roomQuery . '">Today</a></div>';
        $body .= $message === null ? '' : '<p class="notice">' . $this->e($message) . '</p>';
        if ($rooms === []) $body .= '<p class="notice">No rooms have been configured for this school yet. Add rooms in the timetable settings to use the technician grid.</p>';
        elseif (($data['version'] ?? null) === null) $body .= '<p class="notice">No active timetable is configured for this school yet.</p>';
        $body .= $this->roomControls($rooms, $selected, $hasSelection, $date);
        $body .= $this->grid($date, $rooms, $selected, $data['slots'], $data['occurrences']);
        if ($rooms !== [] && $selected === []) $body .= '<p class="notice">No rooms selected. Select one or more rooms above to display the technician timetable.</p>';
        $body .= $this->inspectionLinks($date);
        return PageLayout::render('Technician daily preparation', $body, $this->user);
    }

    private function roomControls(array $rooms, array $selected, bool $hasSelection, DateTimeImmutable $date): string
    {
        $key = 'reqsheet:technician-rooms:' . $this->organisationId . ':' . $this->userId;
        $html = '<section class="technician-room-controls" data-room-preference-key="' . $this->e($key) . '"><form method="get"><input type="hidden" name="date" value="' . $this->e($date->format('Y-m-d')) . '"><input type="hidden" name="room_selection" value="1"><fieldset><legend>Rooms</legend><div class="technician-room-actions"><button type="button" class="secondary" data-room-select-all>Select all</button><button type="button" class="secondary" data-room-clear-all>Clear all</button></div><div class="technician-room-list">';
        foreach ($rooms as $room) $html .= '<label class="check-label"><input type="checkbox" name="room_ids[]" value="' . (int) $room['id'] . '"' . (in_array((int) $room['id'], $selected, true) ? ' checked' : '') . '> ' . $this->e($room['code']) . '</label>';
        $html .= '</div></fieldset><button class="secondary">Apply display</button></form></section>';
        if ($hasSelection) return $html . $this->roomPreferenceScript($key, false) ;
        return $html . $this->roomPreferenceScript($key, true);
    }

    private function grid(DateTimeImmutable $date, array $rooms, array $selected, array $slots, array $occurrences, bool $readOnly = false): string
    {
        $rooms = array_values(array_filter($rooms, static fn (array $room): bool => in_array((int) $room['id'], $selected, true)));
        $byRoom = []; foreach ($occurrences as $occurrence) $byRoom[(string) $occurrence['snapshot_room_code']][] = $occurrence;
        $html = '<section class="technician-sheet"><h2 class="print-date">Day View · ' . $this->e($date->format('l j F Y')) . '</h2><div class="timetable-scroll"><table class="technician-grid"><thead><tr><th>P</th>'; foreach ($rooms as $room) $html .= '<th>' . $this->e($room['code']) . '</th>'; $html .= '</tr></thead><tbody>';
        foreach ($slots as $slot) { $separator = ($slot['kind'] ?? '') !== 'teaching'; $html .= '<tr' . ($separator ? ' class="technician-separator"' : '') . '><th>' . $this->e((string) $slot['label']) . '</th>'; foreach ($rooms as $room) { $occurrence = $separator ? null : $this->occurrenceForSlot($byRoom[$room['code']] ?? [], $slot, $slots); $html .= '<td>' . ($separator ? '' : ($occurrence === null ? '<span class="room-free">ROOM FREE</span>' : $this->cell($occurrence, $readOnly))) . '</td>'; } $html .= '</tr>'; }
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
        foreach ($rows as $row) $html .= '<tr><td>' . $this->e($this->dateLabel((string) $row['lesson_date'])) . '</td><td>' . $this->e((string) $row['period_label']) . '</td><td>' . $this->e((string) $row['snapshot_class_code']) . '</td><td>' . $this->e((string) $row['snapshot_room_code']) . '</td><td>' . $this->e((string) (($row['requirements_text'] ?? '') ?: 'Not requisitioned')) . '</td></tr>';
        return $html . '</tbody></table></div>';
    }

    private function cell(array $occurrence, bool $readOnly = false): string
    {
        $text = trim((string) ($occurrence['requirements_text'] ?? '')); $label = $text === '' ? (($occurrence['state'] ?? '') === 'nothing_required' ? 'Nothing required' : 'Not requisitioned') : $text;
        $prepared = !empty($occurrence['prepared_at']);
        $marker = $prepared ? '<span class="technician-prepped" aria-label="Prepared" title="Prepared">✓</span>' : '<span class="technician-prepped technician-prepped-empty" aria-hidden="true"></span>';
        $csrf = $this->e(CsrfToken::value());
        $toggle = $readOnly ? '' : '<form method="post" class="technician-prep-form"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="action" value="set_prepared"><input type="hidden" name="occurrence_id" value="' . (int) $occurrence['id'] . '"><input type="hidden" name="prepared" value="' . ($prepared ? 'no' : 'yes') . '"><input type="hidden" name="date" value="' . $this->e((string) $occurrence['lesson_date']) . '"><button type="submit" class="secondary">' . ($prepared ? 'Mark as Not Prepped' : 'Mark as Prepped') . '</button></form>';
        return '<details class="technician-cell class-tone-' . ClassTone::forCode((string) $occurrence['snapshot_class_code']) . '"><summary><span class="technician-cell-heading"><strong>' . $this->e((string) $occurrence['snapshot_class_code']) . '</strong>' . $marker . '<span>' . $this->e((string) ($occurrence['teacher_initials'] ?? $occurrence['teacher_name'])) . '</span></span><span class="technician-requisition" title="' . $this->e($label) . '">' . $this->e($label) . '</span></summary><div class="technician-detail"><strong>' . $this->e((string) $occurrence['teacher_name']) . '</strong> · ' . $this->e((string) $occurrence['snapshot_class_code']) . ' · ' . $this->e((string) $occurrence['snapshot_room_code']) . '<br>' . $this->e((string) $occurrence['lesson_date']) . ' · ' . $this->e((string) ($occurrence['period_label'] ?? '')) . '<p>' . nl2br($this->e($text === '' ? 'No requisition has been entered.' : $text)) . '</p>' . $toggle . '<details><summary>Lesson details</summary><p>Lesson outline: ' . nl2br($this->e((string) ($occurrence['planning_notes'] ?? 'Not entered.'))) . '</p><p>Risk assessment: ' . nl2br($this->e((string) ($occurrence['risk_assessment_text'] ?? 'Not entered.'))) . '</p></details></div></details>';
    }

    private function weekPrint(DateTimeImmutable $date, array $selected): string
    {
        $days = method_exists($this->store, 'workingDays') ? $this->store->workingDays($this->organisationId) : [1, 2, 3, 4, 5];
        $first = (int) ($days[0] ?? 1);
        $start = method_exists($this->store, 'workingWeekStart') ? $this->store->workingWeekStart($this->organisationId, $date) : $date->modify('-' . (((int) $date->format('N') - $first + 7) % 7) . ' days');
        $backUrl = '/technician?date=' . $this->e($date->format('Y-m-d')) . $this->roomQuery($selected);
        $html = '<main class="technician-week-print"><header class="page-header technician-print-header"><div><p class="eyebrow">Technician</p><h1>Print View</h1></div><a class="button secondary" href="' . $backUrl . '">Back to Technician View</a></header>';
        $rooms = $this->store->roomsForOrganisation($this->organisationId);
        foreach ($days as $day) { $dayDate = $start->modify('+' . (((int) $day - $first + 7) % 7) . ' days'); $data = $this->store->daily($this->organisationId, $dayDate, $selected); $html .= $this->grid($dayDate, $rooms, $selected, $data['slots'], $data['occurrences'], true); }
        return $html . '</main><script>window.addEventListener("load",function(){if(window.__reqsheetWeekPrint)return;window.__reqsheetWeekPrint=true;window.print();});</script>';
    }

    private function roomQuery(array $selected): string
    {
        $query = '&room_selection=1';
        foreach (array_values(array_unique(array_map('intval', $selected))) as $roomId) if ($roomId > 0) $query .= '&room_ids[]=' . $roomId;
        return $query;
    }

    private function orderedRoomIds(array $availableRoomIds, array $requestedRoomIds): array
    {
        $requested = array_fill_keys(array_values(array_unique($requestedRoomIds)), true);
        return array_values(array_filter($availableRoomIds, static fn (int $roomId): bool => isset($requested[$roomId])));
    }

    private function roomPreferenceScript(string $key, bool $restore): string
    {
        $restoreFlag = $restore ? 'true' : 'false';
        return '<script>(function(){var box=document.querySelector("[data-room-preference-key]");if(!box)return;var form=box.querySelector("form"),key=box.dataset.roomPreferenceKey,checks=Array.from(box.querySelectorAll("input[name=\\"room_ids[]\\"]"));function ids(){return checks.filter(function(input){return input.checked;}).map(function(input){return Number(input.value);});}function save(){try{localStorage.setItem(key,JSON.stringify(ids()));}catch(error){}}checks.forEach(function(input){input.addEventListener("change",save);});box.querySelector("[data-room-select-all]").addEventListener("click",function(){checks.forEach(function(input){input.checked=true;});save();});box.querySelector("[data-room-clear-all]").addEventListener("click",function(){checks.forEach(function(input){input.checked=false;});save();});if(' . $restoreFlag . '){try{var saved=JSON.parse(localStorage.getItem(key));if(Array.isArray(saved)){var allowed=checks.map(function(input){return Number(input.value);});var valid=saved.filter(function(id){return allowed.indexOf(Number(id))!==-1;}).map(Number);checks.forEach(function(input){input.checked=valid.indexOf(Number(input.value))!==-1;});form.submit();}}catch(error){}}})();</script>';
    }

    private function date(string $value): DateTimeImmutable { if ($value === 'today' || $value === '') return new DateTimeImmutable('today'); $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date !== false && $date->format('Y-m-d') === $value ? $date : new DateTimeImmutable('today'); }
    private function dateLabel(string $value): string { $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value); return $date === false ? $value : $date->format('d-m-Y D'); }
    private function weekStart(DateTimeImmutable $date): DateTimeImmutable { return $date->modify('-' . ((int) $date->format('N') - 1) . ' days'); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
