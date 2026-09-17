<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Timetable\PdoTimetableConfigurationStore;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\RecurringLessonService;
use Reqsheet\Timetable\TimetableConfigurationStore;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableSlotService;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Timetable\TimetableVersion;
use Reqsheet\Timetable\TimetableVersionService;

final class AdminTimetablePage
{
    public function __construct(private readonly TimetableConfigurationStore $store, private readonly int $organisationId)
    {
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $input */
    public function handle(string $method, array $query, array $input): string
    {
        $message = null;
        if ($method === 'POST') {
            try {
                $message = $this->mutate($input);
            } catch (TimetableValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }

        $versions = $this->store->versionsForOrganisation($this->organisationId);
        $versionId = (int) ($query['version'] ?? $input['version'] ?? ($versions[0]->id ?? 0));
        $version = $this->store->findVersion($versionId);
        if ($version === null || $version->organisationId !== $this->organisationId) {
            $version = $versions[0] ?? null;
            $versionId = $version?->id ?? 0;
        }

        return $this->page($versions, $version, $query, $message);
    }

    /** @param array<string, mixed> $input */
    private function mutate(array $input): string
    {
        $action = (string) ($input['action'] ?? '');
        if ($action === 'create_version') {
            $id = (new TimetableVersionService($this->store))->create(
                $this->organisationId,
                $this->nullable($input['label'] ?? null),
                (string) ($input['effective_from'] ?? ''),
                $this->nullable($input['effective_to'] ?? null),
            );
            return 'Timetable version created: ' . $id;
        }

        $versionId = (int) ($input['version'] ?? 0);
        $version = $this->store->findVersion($versionId);
        if ($version === null || $version->organisationId !== $this->organisationId) {
            throw new TimetableValidationException(['Timetable version is not available for this organisation.']);
        }
        $teacher = (int) ($input['teacher_user_id'] ?? 0);
        $day = (int) ($input['day_of_week'] ?? 0);
        $slot = (int) ($input['start_slot_id'] ?? 0);
        $duration = (int) ($input['duration_periods'] ?? 0);
        $class = (string) ($input['class_code'] ?? '');
        $room = (string) ($input['room_code'] ?? '');
        $service = new RecurringLessonService($this->store);
        if ($action === 'create_lesson') {
            $id = $service->create($versionId, $teacher, $day, $slot, $duration, $class, $room);
            return 'Lesson created: ' . $id;
        }
        if ($action === 'update_lesson') {
            $existing = $this->store->findLesson((int) ($input['lesson_id'] ?? 0));
            if ($existing === null || $existing->timetableVersionId !== $versionId) {
                throw new TimetableValidationException(['Lesson is not part of the selected timetable version.']);
            }
            $service->update((int) ($input['lesson_id'] ?? 0), $teacher, $day, $slot, $duration, $class, $room);
            return 'Lesson updated.';
        }
        if ($action === 'delete_lesson') {
            $existing = $this->store->findLesson((int) ($input['lesson_id'] ?? 0));
            if ($existing === null || $existing->timetableVersionId !== $versionId) {
                throw new TimetableValidationException(['Lesson is not part of the selected timetable version.']);
            }
            $service->remove((int) ($input['lesson_id'] ?? 0));
            return 'Lesson removed.';
        }

        throw new TimetableValidationException(['Unknown timetable action.']);
    }

    /** @param list<TimetableVersion> $versions @param array<string, mixed> $query */
    private function page(array $versions, ?TimetableVersion $version, array $query, ?string $message): string
    {
        $requestedMode = (string) ($query['mode'] ?? 'staff');
        $mode = in_array($requestedMode, ['staff', 'room', 'day'], true) ? $requestedMode : 'staff';
        $users = $version === null ? [] : $this->store->usersForOrganisation($this->organisationId);
        $rooms = $version === null ? [] : $this->store->roomCodesForVersion($version->id);
        $selectedStaff = (int) ($query['staff'] ?? ($users[0]['id'] ?? 0));
        $selectedRoom = (string) ($query['room'] ?? ($rooms[0] ?? ''));
        $selectedDay = (int) ($query['day'] ?? 1);
        $body = '<h1>Admin timetable editor</h1>';
        $body .= '<p class="context">' . ($version === null ? 'No timetable version selected.' : $this->versionContext($version)) . '</p>';
        $body .= '<form class="toolbar" method="get"><label>Timetable version ' . $this->versionSelect($versions, $version?->id) . '</label><label>Mode ' . $this->select('mode', $mode, ['staff' => 'Staff member', 'room' => 'Room', 'day' => 'Day']) . '</label>';
        if ($mode === 'staff') $body .= '<label>Staff member ' . $this->selectFromRows('staff', $selectedStaff, $users) . '</label>';
        if ($mode === 'room') $body .= '<label>Room ' . $this->select('room', $selectedRoom, array_combine($rooms, $rooms)) . '</label>';
        if ($mode === 'day') {
            $dayOptions = [];
            if ($version !== null) foreach ($this->store->slotsForVersion($version->id) as $slot) $dayOptions[$slot->dayOfWeek] = $this->dayName($slot->dayOfWeek);
            $body .= '<label>Day ' . $this->select('day', (string) $selectedDay, $dayOptions) . '</label>';
        }
        $body .= '<button>View</button></form>';
        if ($message !== null) $body .= '<p class="message">' . $this->e($message) . '</p>';
        if ($version !== null) {
            $body .= $mode === 'day' ? $this->dayView($version, $selectedDay, $users, $query) : $this->gridView($version, $mode, $selectedStaff, $selectedRoom, $query);
            $body .= $this->lessonEditor($version, $users, $query);
        }
        $body .= '<p><a href="/admin/people">Manage people</a></p>' . $this->versionForm();
        return '<!doctype html><html><head><meta charset="utf-8"><title>Admin timetable</title><style>' . $this->css() . '</style></head><body><main>' . $body . '</main></body></html>';
    }

    /** @param list<TimetableVersion> $versions */
    private function versionSelect(array $versions, ?int $selected): string
    {
        $options = [];
        foreach ($versions as $version) $options[$version->id] = ($version->label ?: 'Untitled') . ' (' . $version->effectiveFrom->format('Y-m-d') . ')';
        return $this->select('version', (string) ($selected ?? 0), $options);
    }

    /** @param list<array{id:int,display_name:string,staff_identifier:?string,is_active:bool}> $rows */
    private function selectFromRows(string $name, int $selected, array $rows): string
    {
        $options = [];
        foreach ($rows as $row) $options[$row['id']] = $row['display_name'] . ($row['staff_identifier'] ? ' (' . $row['staff_identifier'] . ')' : '');
        return $this->select($name, (string) $selected, $options);
    }

    /** @param array<int|string, string> $options */
    private function select(string $name, string $selected, array $options): string
    {
        $html = '<select name="' . $this->e($name) . '">';
        foreach ($options as $value => $label) $html .= '<option value="' . $this->e((string) $value) . '"' . ((string) $value === $selected ? ' selected' : '') . '>' . $this->e($label) . '</option>';
        return $html . '</select>';
    }

    private function versionContext(TimetableVersion $version): string
    {
        $today = new \DateTimeImmutable('today');
        $state = $version->effectiveFrom > $today ? 'future' : ($version->effectiveTo !== null && $today >= $version->effectiveTo ? 'historical' : 'current');
        return 'Editing <strong>' . $this->e($version->label ?: 'Untitled timetable') . '</strong>, effective from ' . $version->effectiveFrom->format('Y-m-d') . ' (' . $state . ').';
    }

    /** @param array<string, mixed> $query */
    private function gridView(TimetableVersion $version, string $mode, int $staff, string $room, array $query): string
    {
        $slots = $this->store->slotsForVersion($version->id);
        $lessons = array_values(array_filter($this->store->lessonsForVersion($version->id), function (RecurringLesson $lesson) use ($mode, $staff, $room): bool {
            return $mode === 'staff' ? $lesson->teacherUserId === $staff : strtoupper(trim($lesson->roomCode)) === strtoupper(trim($room));
        }));
        $byDay = [];
        $columns = [];
        foreach ($slots as $slot) {
            $byDay[$slot->dayOfWeek][$slot->sequenceNumber] = $slot;
            $columns[$slot->sequenceNumber] = $slot;
        }
        ksort($byDay);
        ksort($columns);
        $html = '<section class="grid-view"><h2>' . ($mode === 'staff' ? 'Staff member timetable' : 'Room timetable') . '</h2>';
        $html .= '<table><tr><th>Day</th>';
        foreach ($columns as $slot) {
            $html .= '<th' . ($slot->isTeaching() ? '' : ' class="separator"') . '>' . $this->e($slot->isTeaching() ? $this->periodLabel($slot) : ($slot->label ?: ucfirst($slot->kind))) . '</th>';
        }
        $html .= '</tr>';
        foreach ($byDay as $day => $daySlots) {
            $html .= '<tr><th>' . $this->dayName($day) . '</th>';
            $sequenceNumbers = array_keys($columns);
            for ($index = 0; $index < count($sequenceNumbers); $index++) {
                $sequence = $sequenceNumbers[$index];
                $slot = $daySlots[$sequence] ?? null;
                if ($slot === null) { $html .= '<td></td>'; continue; }
                if (!$slot->isTeaching()) { $html .= '<td class="separator">' . $this->e($slot->label ?: ucfirst($slot->kind)) . '</td>'; continue; }
                $matching = array_values(array_filter($lessons, static fn (RecurringLesson $lesson): bool => $lesson->dayOfWeek === $day && $lesson->startSlotId === $slot->id));
                $lesson = $matching[0] ?? null;
                if ($lesson !== null) {
                    $content = $this->e($lesson->classCode) . ' · ' . $this->e($lesson->roomCode) . ' (' . $lesson->durationPeriods . ' periods)';
                    $html .= '<td colspan="' . $lesson->durationPeriods . '"><a href="?version=' . $version->id . '&mode=' . $mode . '&edit=' . $lesson->id . '&start_slot=' . $slot->id . '&day=' . $day . '">' . $content . '</a></td>';
                    $index += $lesson->durationPeriods - 1;
                } else {
                    $html .= '<td><a href="?version=' . $version->id . '&mode=' . $mode . '&edit=0&start_slot=' . $slot->id . '&day=' . $day . '">Empty teaching cell</a></td>';
                }
            }
            $html .= '</tr>';
        }
        return $html . '</table></section>';
    }

    /** @param list<array{id:int,display_name:string,staff_identifier:?string,is_active:bool}> $users @param array<string, mixed> $query */
    private function dayView(TimetableVersion $version, int $day, array $users, array $query): string
    {
        $slots = array_values(array_filter($this->store->slotsForVersion($version->id), static fn (TimetableSlot $slot): bool => $slot->dayOfWeek === $day));
        usort($slots, static fn (TimetableSlot $a, TimetableSlot $b): int => $a->sequenceNumber <=> $b->sequenceNumber);
        $lessons = $this->store->lessonsForVersion($version->id);
        $html = '<section class="grid-view"><h2>' . $this->dayName($day) . ' overview</h2><table><tr><th>Period</th><th>Lessons</th></tr>';
        foreach ($slots as $slot) {
            if (!$slot->isTeaching()) { $html .= '<tr class="separator"><th colspan="2">' . $this->e($slot->label ?: ucfirst($slot->kind)) . '</th></tr>'; continue; }
            $items = array_filter($lessons, static fn (RecurringLesson $lesson): bool => $lesson->dayOfWeek === $day && $lesson->startSlotId === $slot->id);
            $cells = [];
            foreach ($items as $lesson) $cells[] = '<a href="?version=' . $version->id . '&mode=day&day=' . $day . '&edit=' . $lesson->id . '">' . $this->e($lesson->classCode) . ' · ' . $this->e($lesson->roomCode) . '</a>';
            $html .= '<tr><th>' . $this->e($this->periodLabel($slot)) . '</th><td>' . implode('<br>', $cells) . '</td></tr>';
        }
        return $html . '</table></section>';
    }

    /** @param list<array{id:int,display_name:string,staff_identifier:?string,is_active:bool}> $users @param array<string, mixed> $query */
    private function lessonEditor(TimetableVersion $version, array $users, array $query): string
    {
        $lesson = null;
        $edit = (int) ($query['edit'] ?? 0);
        if ($edit > 0) $lesson = $this->store->findLesson($edit);
        $slots = array_filter($this->store->slotsForVersion($version->id), static fn (TimetableSlot $slot): bool => $slot->isTeaching());
        $dayOptions = [];
        foreach ($this->store->slotsForVersion($version->id) as $slot) $dayOptions[$slot->dayOfWeek] = $this->dayName($slot->dayOfWeek);
        $action = $lesson === null ? 'create_lesson' : 'update_lesson';
        $html = '<section class="editor"><h2>' . ($lesson === null ? 'Add lesson' : 'Edit lesson') . '</h2><form method="post"><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="version" value="' . $version->id . '">';
        if ($lesson !== null) $html .= '<input type="hidden" name="lesson_id" value="' . $lesson->id . '">';
        $html .= '<label>Teacher ' . $this->selectFromRows('teacher_user_id', $lesson?->teacherUserId ?? (int) ($users[0]['id'] ?? 0), $users) . '</label><label>Day ' . $this->select('day_of_week', (string) ($lesson?->dayOfWeek ?? array_key_first($dayOptions)), $dayOptions) . '</label><label>Starts ' . $this->slotSelect($slots, $lesson?->startSlotId ?? (int) ($query['start_slot'] ?? 0)) . '</label><label>Length / span <input type="number" min="1" name="duration_periods" value="' . ($lesson?->durationPeriods ?? 1) . '"></label><label>Class <input name="class_code" required value="' . $this->e($lesson?->classCode ?? '') . '"></label><label>Room <input name="room_code" required value="' . $this->e($lesson?->roomCode ?? '') . '"></label><button>Save lesson</button></form>';
        if ($lesson !== null) $html .= '<form method="post"><input type="hidden" name="action" value="delete_lesson"><input type="hidden" name="version" value="' . $version->id . '"><input type="hidden" name="lesson_id" value="' . $lesson->id . '"><button class="danger">Remove lesson</button></form>';
        return $html . '</section>';
    }

    /** @param array<int, TimetableSlot> $slots */
    private function slotSelect(array $slots, int $selected): string
    {
        $options = [];
        foreach ($slots as $slot) $options[$slot->id] = $this->dayName($slot->dayOfWeek) . ' ' . $this->periodLabel($slot);
        return $this->select('start_slot_id', (string) $selected, $options);
    }

    private function versionForm(): string
    {
        return '<section class="editor"><h2>Create timetable version</h2><form method="post"><input type="hidden" name="action" value="create_version"><label>Name <input name="label"></label><label>Effective from <input type="date" name="effective_from" required></label><label>Effective to <input type="date" name="effective_to"></label><button>Create version</button></form></section>';
    }

    private function dayName(int $day): string { return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day - 1] ?? 'Day ' . $day; }
    private function periodLabel(TimetableSlot $slot): string { return $slot->label !== '' ? $slot->label : 'P' . $slot->teachingPeriodNumber; }
    private function nullable(mixed $value): ?string { $value = is_string($value) ? trim($value) : ''; return $value === '' ? null : $value; }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
    private function css(): string { return 'body{font:16px system-ui,sans-serif;margin:0;background:#f5f5f5;color:#222}main{max-width:1100px;margin:2rem auto;background:white;padding:2rem}label{display:inline-flex;flex-direction:column;gap:.25rem;margin:.4rem}select,input,button{font:inherit;padding:.4rem}button{cursor:pointer}.toolbar{padding:1rem;background:#eee}.context{background:#eef5ff;padding:.8rem}.message{background:#fff3cd;padding:.8rem}.grid-view{overflow-x:auto}table{border-collapse:collapse;width:100%;margin-bottom:1.5rem}th,td{border:1px solid #bbb;padding:.6rem;text-align:left;vertical-align:top}.separator{background:#ddd;color:#555}.editor{border:1px solid #bbb;padding:1rem;margin-top:1rem}.danger{background:#fee}.editor form+form{margin-top:.7rem}a{color:#0645ad}'; }
}
