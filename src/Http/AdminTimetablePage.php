<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\RecurringLessonService;
use Reqsheet\Timetable\TimetableConfigurationStore;
use Reqsheet\Timetable\TimetableRules;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Timetable\TimetableVersion;
use Reqsheet\Timetable\TimetableSlotService;
use Reqsheet\Timetable\TimetableTemplateService;
use Reqsheet\Timetable\ResourceTimetableStore;
use Reqsheet\Timetable\TimetableResourceService;

final class AdminTimetablePage
{
    /** @param array<string, mixed> $settings @param array<string, mixed> $user */
    public function __construct(private readonly TimetableConfigurationStore $store, private readonly int $organisationId, private readonly array $settings = [], private readonly array $user = [])
    {
    }

    /** @param array<string, mixed> $query @param array<string, mixed> $input */
    public function handle(string $method, array $query, array $input): string
    {
        if ($this->store instanceof ResourceTimetableStore) return $this->resourceHandle($method, $query, $input, $this->store);
        $message = null;
        if ($method === 'POST') {
            try {
                $message = $this->mutate($input);
                $query = array_replace($query, ['version' => (int) ($input['version'] ?? 0), 'teacher' => (int) ($input['teacher_user_id'] ?? 0)]);
            } catch (TimetableValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }
        $versions = $this->store->versionsForOrganisation($this->organisationId);
        $versionId = (int) ($query['version'] ?? $input['version'] ?? ($versions[0]->id ?? 0));
        $version = $this->store->findVersion($versionId);
        if ($version !== null && $version->organisationId !== $this->organisationId) $version = null;
        return $this->page($versions, $version, $query, $message);
    }

    private function resourceHandle(string $method, array $query, array $input, ResourceTimetableStore $store): string
    {
        $message = null;
        if ($method === 'POST') {
            try {
                $action = (string) ($input['action'] ?? '');
                if ($action === 'create_resource') {
                    $resource = new TimetableResourceService($store);
                    $type = (string) ($input['resource_type'] ?? '');
                    $id = match ($type) {
                        'teacher' => $resource->createTeacher($this->organisationId, (string) ($input['code'] ?? ''), (string) ($input['display_name'] ?? '')),
                        'room' => $resource->createRoom($this->organisationId, (string) ($input['code'] ?? '')),
                        'class' => $resource->createClass($this->organisationId, (string) ($input['code'] ?? '')),
                        default => throw new TimetableValidationException(['Resource type is invalid.']),
                    };
                    $returnView = in_array(($input['return_view'] ?? ''), ['teacher', 'room', 'class'], true) ? (string) $input['return_view'] : $type;
                    $query = array_replace($query, [
                        'create' => '', 'view' => $returnView,
                        'resource' => (int) ($input['return_resource'] ?? $id),
                        'day' => (int) ($input['return_day'] ?? 0),
                        'start_slot' => (int) ($input['return_start_slot'] ?? 0),
                        'new_' . $type => $id,
                    ]);
                    $message = ucfirst($type) . ' added.';
                } elseif ($action === 'resource_save_lesson' || $action === 'resource_delete_lesson') {
                    $versionId = (int) ($input['version'] ?? 0);
                    $version = $store->findVersion($versionId);
                    if ($version === null || $version->organisationId !== $this->organisationId) throw new TimetableValidationException(['Timetable version is not available for this organisation.']);
                    $service = new RecurringLessonService($store);
                    if ($action === 'resource_delete_lesson') {
                        $service->remove((int) ($input['lesson_id'] ?? 0));
                        $message = 'Lesson removed.';
                    } else {
                        $lessonId = (int) ($input['lesson_id'] ?? 0);
                        if ($lessonId > 0) $service->updateResource($lessonId, (int) ($input['teacher_user_id'] ?? 0), (int) ($input['day_of_week'] ?? 0), (int) ($input['start_slot_id'] ?? 0), (int) ($input['duration_periods'] ?? 1), (int) ($input['class_id'] ?? 0), (int) ($input['room_id'] ?? 0));
                        else $service->createResource($versionId, (int) ($input['teacher_user_id'] ?? 0), (int) ($input['day_of_week'] ?? 0), (int) ($input['start_slot_id'] ?? 0), (int) ($input['duration_periods'] ?? 1), (int) ($input['class_id'] ?? 0), (int) ($input['room_id'] ?? 0));
                        $message = $lessonId > 0 ? 'Lesson updated.' : 'Lesson created.';
                    }
                    $query = array_replace($query, ['version' => $versionId, 'view' => (string) ($input['view'] ?? 'teacher'), 'resource' => (int) ($input['resource'] ?? 0), 'edit' => 0]);
                } elseif ($action === 'create_version') {
                    $id = (new TimetableTemplateService($store))->create($this->organisationId, null, $this->nullable($input['label'] ?? null), null, $this->settings);
                    $query = array_replace($query, ['version' => $id]);
                    $message = 'Timetable version created: ' . $id;
                }
            } catch (TimetableValidationException | \PDOException $exception) {
                $message = $exception instanceof TimetableValidationException ? implode(' ', $exception->errors()) : 'That resource already exists or could not be saved.';
            }
        }
        $versions = $store->versionsForOrganisation($this->organisationId);
        $versionId = array_key_exists('version', $query) ? (int) $query['version'] : ($this->activeVersion()?->id ?? 0);
        $version = $store->findVersion($versionId);
        if ($version !== null && $version->organisationId !== $this->organisationId) $version = null;
        return $this->resourcePage($store, $versions, $version, $query, $message);
    }

    private function resourcePage(ResourceTimetableStore $store, array $versions, ?TimetableVersion $version, array $query, ?string $message): string
    {
        $requestedView = (string) ($query['view'] ?? 'teacher');
        $view = in_array($requestedView, ['teacher', 'room', 'class'], true) ? $requestedView : 'teacher';
        $users = $store->usersForOrganisation($this->organisationId);
        $rooms = $store->roomsForOrganisation($this->organisationId);
        $classes = $store->classesForOrganisation($this->organisationId);
        $resource = (int) ($query['resource'] ?? 0);
        if ($resource < 1) $resource = $view === 'teacher' ? (int) ($users[0]['id'] ?? 0) : ($view === 'room' ? (int) ($rooms[0]['id'] ?? 0) : (int) ($classes[0]['id'] ?? 0));
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable</p><h1>Timetable builder</h1></div><a class="button" href="/admin/people">Manage people</a></section>';
        $body .= '<p class="context">' . ($version === null ? 'Create a timetable version to begin.' : $this->versionContext($version)) . '</p>';
        $body .= $this->resourceToolbar($versions, $version?->id, $view, $resource, $users, $rooms, $classes);
        if ($message !== null) $body .= '<p class="message">' . $this->e($message) . '</p>';
        if (!empty($query['create'])) $body .= $this->resourceCreationForm($version?->id, $view, $resource, (string) $query['create'], $query);
        elseif ($version !== null && $resource > 0) {
            $body .= $this->resourceGrid($store, $version, $view, $resource, $users);
            if ((int) ($query['edit'] ?? 0) > 0 || ((int) ($query['day'] ?? 0) > 0 && (int) ($query['start_slot'] ?? 0) > 0)) $body .= $this->resourceLessonEditor($store, $version, $view, $resource, (int) ($query['edit'] ?? 0), $query, $users, $rooms, $classes);
        } elseif ($version !== null) $body .= '<p class="notice">Add a teacher, room, or class to begin.</p>';
        return PageLayout::render('Admin timetable', $body, $this->user);
    }

    private function resourceToolbar(array $versions, ?int $version, string $view, int $resource, array $users, array $rooms, array $classes): string
    {
        $versionSelect = str_replace('<select name="version"', '<select name="version" onchange="this.form.submit()"', $this->versionSelect($versions, $version));
        $html = '<section class="timetable-toolbar"><form method="get"><label>Version ' . $versionSelect . '</label><label>Teacher <select name="teacher" onchange="location.href=this.value===\'__new__\'?\'/admin/timetable?version=' . (int) $version . '&view=teacher&create=teacher\':\'/admin/timetable?version=' . (int) $version . '&view=teacher&resource=\'+this.value"><option value="0">Select teacher</option>';
        foreach ($users as $row) if ($row['is_active']) $html .= '<option value="' . $row['id'] . '"' . ($view === 'teacher' && $resource === (int) $row['id'] ? ' selected' : '') . '>' . $this->e($row['staff_identifier'] ?: $row['display_name']) . '</option>';
        $html .= '<option value="__new__">Add new teacher...</option></select></label><a class="button secondary" href="/admin/timetable?version=' . (int) $version . '&view=teacher&create=teacher">Add teacher</a><label>Room <select name="room" onchange="location.href=this.value===\'__new__\'?\'/admin/timetable?version=' . (int) $version . '&view=room&create=room\':\'/admin/timetable?version=' . (int) $version . '&view=room&resource=\'+this.value"><option value="0">Select room</option>';
        foreach ($rooms as $row) $html .= '<option value="' . $row['id'] . '"' . ($view === 'room' && $resource === (int) $row['id'] ? ' selected' : '') . '>' . $this->e($row['code']) . '</option>';
        $html .= '<option value="__new__">Add new room...</option></select></label><a class="button secondary" href="/admin/timetable?version=' . (int) $version . '&view=room&create=room">Add room</a><label>Class <select name="class" onchange="location.href=this.value===\'__new__\'?\'/admin/timetable?version=' . (int) $version . '&view=class&create=class\':\'/admin/timetable?version=' . (int) $version . '&view=class&resource=\'+this.value"><option value="0">Select class</option>';
        foreach ($classes as $row) $html .= '<option value="' . $row['id'] . '"' . ($view === 'class' && $resource === (int) $row['id'] ? ' selected' : '') . '>' . $this->e($row['code']) . '</option>';
        return $html . '<option value="__new__">Add new class...</option></select></label><a class="button secondary" href="/admin/timetable?version=' . (int) $version . '&view=class&create=class">Add class code</a></form></section>';
    }

    private function resourceCreationForm(?int $version, string $view, int $resource, string $type, array $query = []): string
    {
        return '<dialog class="resource-dialog" open><form method="post"><a class="close" href="/admin/timetable?version=' . (int) $version . '&view=' . $this->e((string) ($query['return_view'] ?? $view)) . '&resource=' . (int) ($query['return_resource'] ?? $resource) . '&day=' . (int) ($query['return_day'] ?? 0) . '&start_slot=' . (int) ($query['return_start_slot'] ?? 0) . '">Cancel</a><p class="eyebrow">Add resource</p><h2>' . $this->e($type === 'teacher' ? 'Teacher' : ($type === 'class' ? 'Class code' : 'Room')) . '</h2><input type="hidden" name="action" value="create_resource"><input type="hidden" name="resource_type" value="' . $this->e($type) . '"><input type="hidden" name="version" value="' . (int) $version . '"><input type="hidden" name="return_view" value="' . $this->e((string) ($query['return_view'] ?? $view)) . '"><input type="hidden" name="return_resource" value="' . (int) ($query['return_resource'] ?? $resource) . '"><input type="hidden" name="return_day" value="' . (int) ($query['return_day'] ?? 0) . '"><input type="hidden" name="return_start_slot" value="' . (int) ($query['return_start_slot'] ?? 0) . '"><label>' . ($type === 'teacher' ? 'Initials/code' : ($type === 'class' ? 'Class code' : 'Room code')) . '<input name="code" required autofocus></label>' . ($type === 'teacher' ? '<label>Display name (optional)<input name="display_name"></label>' : '') . '<div class="form-actions"><button>Add</button></div></form></dialog><script>document.addEventListener("DOMContentLoaded",function(){var dialog=document.querySelector(".resource-dialog");if(!dialog)return;if(typeof dialog.showModal==="function"){dialog.close();dialog.showModal();}var field=dialog.querySelector("[autofocus]");if(field)field.focus();});</script>';
    }

    private function resourceGrid(ResourceTimetableStore $store, TimetableVersion $version, string $view, int $resource, array $users): string
    {
        $days = $this->workingDays($store->slotsForVersion($version->id), $version->firstDayOfWeek);
        $slots = $store->slotsForVersion($version->id);
        $rows = [];
        foreach ($slots as $slot) $rows[$slot->sequenceNumber] = $slot->sequenceNumber;
        ksort($rows);
        $lessons = $store->lessonsForVersion($version->id);
        $label = $this->resourceName($view, $resource, $users, $store->roomsForOrganisation($this->organisationId), $store->classesForOrganisation($this->organisationId));
        $html = '<section class="editor-section admin-resource-grid"><div class="section-heading"><div><p class="eyebrow">' . $this->e(ucfirst($view)) . ' timetable</p><h2>' . $this->e($label) . '</h2></div><p class="muted">Click an empty period to assign a lesson.</p></div><div class="timetable-scroll"><table><caption class="visually-hidden">Timetable grid</caption><thead><tr><th>Period</th>';
        foreach ($days as $day) $html .= '<th>' . $this->e($this->dayName($day)) . '</th>';
        $html .= '</tr></thead><tbody>';
        $spanned = [];
        foreach ($rows as $sequence) {
            $first = null; foreach ($slots as $slot) if ($slot->sequenceNumber === $sequence) { $first = $slot; break; }
            $separator = $first !== null && !$first->isTeaching();
            $html .= '<tr' . ($separator ? ' class="timetable-separator"' : '') . '><th>' . $this->e($first?->isTeaching() ? $this->periodLabel($first) : ($first ? $this->separatorLabel($first) : 'P' . $sequence)) . '</th>';
            foreach ($days as $day) {
                if (!empty($spanned[$day][$sequence])) continue;
                $slot = null; foreach ($slots as $candidate) if ($candidate->dayOfWeek === $day && $candidate->sequenceNumber === $sequence) { $slot = $candidate; break; }
                if ($slot === null || !$slot->isTeaching()) { $html .= '<td class="' . ($slot && !$slot->isTeaching() ? 'separator-cell' : 'empty-cell') . '">' . ($slot ? $this->e($this->separatorLabel($slot)) : '') . '</td>'; continue; }
                $lesson = null; foreach ($lessons as $candidate) if ($candidate->dayOfWeek === $day && $candidate->startSlotId === $slot->id && (($view === 'teacher' && $candidate->teacherUserId === $resource) || ($view === 'room' && $candidate->roomId === $resource) || ($view === 'class' && $candidate->classId === $resource))) { $lesson = $candidate; break; }
                $href = '/admin/timetable?version=' . $version->id . '&view=' . $view . '&resource=' . $resource . '&day=' . $day . '&start_slot=' . $slot->id . ($lesson ? '&edit=' . $lesson->id : '');
                if ($lesson) {
                    $daySlots = array_values(array_filter($slots, static fn (TimetableSlot $candidate): bool => $candidate->dayOfWeek === $day));
                    $span = count(TimetableRules::occupiedSequences($daySlots, $slot, $lesson->durationPeriods));
                    $span = max(1, $span);
                    foreach ($daySlots as $candidate) if ($candidate->sequenceNumber !== $sequence && $candidate->sequenceNumber > $sequence && $candidate->sequenceNumber < $sequence + $span) $spanned[$day][$candidate->sequenceNumber] = true;
                    $html .= '<td rowspan="' . $span . '"><a class="admin-lesson class-tone-' . $this->classTone($lesson->classCode) . '" data-cell="' . $day . '-' . $slot->id . '" href="' . $href . '"><strong>' . $this->e($lesson->classCode) . '</strong><span>' . $this->e($lesson->roomCode) . '</span><small>Teacher ' . $this->e($this->teacherCode($lesson->teacherUserId, $users)) . ' · ' . $lesson->durationPeriods . ' period' . ($lesson->durationPeriods === 1 ? '' : 's') . '</small></a></td>';
                } else $html .= '<td class="empty-cell"><a class="empty-period" data-cell="' . $day . '-' . $slot->id . '" href="' . $href . '" aria-label="Add lesson on ' . $this->e($this->dayName($day)) . ' ' . $this->e($this->periodLabel($slot)) . '"><span>+</span><small>Add lesson</small></a></td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    private function resourceLessonEditor(ResourceTimetableStore $store, TimetableVersion $version, string $view, int $resource, int $edit, array $query, array $users, array $rooms, array $classes): string
    {
        $lesson = $edit > 0 ? $store->findLesson($edit) : null;
        $day = $lesson?->dayOfWeek ?? (int) ($query['day'] ?? 1);
        $slot = $lesson?->startSlotId ?? (int) ($query['start_slot'] ?? 0);
        $fixed = $view === 'teacher' ? 'teacher_user_id' : ($view === 'room' ? 'room_id' : 'class_id');
        $fixedValue = $view === 'teacher' ? ($lesson?->teacherUserId ?? $resource) : ($view === 'room' ? ($lesson?->roomId ?? $resource) : ($lesson?->classId ?? $resource));
        $teacherValue = $lesson?->teacherUserId ?? (int) ($query['new_teacher'] ?? 0);
        $roomValue = $lesson?->roomId ?? (int) ($query['new_room'] ?? 0);
        $classValue = $lesson?->classId ?? (int) ($query['new_class'] ?? 0);
        $fixedLabel = $this->resourceName($view, $fixedValue, $users, $rooms, $classes);
        $periodLabel = $this->slotLabelById($store->slotsForVersion($version->id), $slot);
        $cancel = '/admin/timetable?version=' . $version->id . '&view=' . $view . '&resource=' . $resource;
        $html = '<dialog id="assignment-editor" class="assignment-dialog" open aria-labelledby="assignment-editor-title"><form method="post">';
        $html .= '<a class="close" href="' . $cancel . '">Cancel</a><p class="eyebrow">Version ' . $this->e($version->label ?: 'Untitled timetable') . '</p><h2 id="assignment-editor-title">' . ($lesson ? 'Edit assignment' : 'Add assignment') . '</h2>';
        $html .= '<p class="lesson-context"><strong>' . $this->e(ucfirst($view) . ' ' . $fixedLabel) . '</strong> · ' . $this->e($this->dayName($day)) . ' · ' . $this->e($periodLabel) . '</p>';
        $html .= '<input type="hidden" name="action" value="resource_save_lesson"><input type="hidden" name="version" value="' . $version->id . '"><input type="hidden" name="view" value="' . $view . '"><input type="hidden" name="resource" value="' . $resource . '"><input type="hidden" name="day_of_week" value="' . $day . '"><input type="hidden" name="start_slot_id" value="' . $slot . '">' . ($lesson ? '<input type="hidden" name="lesson_id" value="' . $lesson->id . '">' : '');
        $html .= '<input type="hidden" name="' . $fixed . '" value="' . $fixedValue . '"><div class="fixed-resource"><span>' . $this->e(ucfirst($view)) . ' (fixed)</span><strong>' . $this->e($fixedLabel) . '</strong></div>';
        if ($view !== 'teacher') $html .= '<label for="teacher_user_id">Teacher' . $this->resourceSelect('teacher_user_id', $teacherValue, $users, 'Add new teacher...') . '</label>';
        if ($view !== 'room') $html .= '<label for="room_id">Room' . $this->resourceSelect('room_id', $roomValue, $rooms, 'Add new room...') . '</label>';
        if ($view !== 'class') $html .= '<label for="class_id">Class code' . $this->resourceSelect('class_id', $classValue, $classes, 'Add new class...') . '</label>';
        if ($this->conjoinedPeriodsAllowed()) {
            $html .= '<label for="duration_periods">Length / span<select id="duration_periods" name="duration_periods">';
            $maxDuration = $this->maxDuration($version->id, $day, $slot);
            for ($duration = 1; $duration <= $maxDuration; $duration++) $html .= '<option value="' . $duration . '"' . ($duration === ($lesson?->durationPeriods ?? 1) ? ' selected' : '') . '>' . $duration . ' period' . ($duration === 1 ? '' : 's') . '</option>';
            $html .= '</select></label>';
        } else $html .= '<input type="hidden" name="duration_periods" value="1"><p class="muted">Conjoined periods are disabled in Settings.</p>';
        $html .= '<div class="form-actions"><button>Save assignment</button><a class="button secondary" href="' . $cancel . '">Cancel</a></div></form>';
        if ($lesson) $html .= '<form method="post" class="delete-form"><input type="hidden" name="action" value="resource_delete_lesson"><input type="hidden" name="version" value="' . $version->id . '"><input type="hidden" name="view" value="' . $view . '"><input type="hidden" name="resource" value="' . $resource . '"><input type="hidden" name="lesson_id" value="' . $lesson->id . '"><button class="danger">Remove assignment</button></form>';
        $createUrl = '/admin/timetable?version=' . $version->id . '&view=' . $view . '&resource=' . $resource . '&return_view=' . $view . '&return_resource=' . $resource . '&return_day=' . $day . '&return_start_slot=' . $slot . '&create=';
        return $html . '</dialog><script>document.addEventListener("DOMContentLoaded",function(){var dialog=document.getElementById("assignment-editor");if(!dialog)return;var origin=document.querySelector("[data-cell=\"' . $day . '-' . $slot . '\"]");if(typeof dialog.showModal==="function"){dialog.close();dialog.showModal();}var first=dialog.querySelector("select, input:not([type=hidden]), button");if(first)first.focus();dialog.addEventListener("close",function(){if(origin)origin.focus();});});document.addEventListener("change",function(e){if(e.target.value!=="__new__")return;var type=e.target.name==="teacher_user_id"?"teacher":(e.target.name==="room_id"?"room":"class");location.href="' . $createUrl . '"+type;});</script>';
    }

    private function legacyResourceLessonEditor(ResourceTimetableStore $store, TimetableVersion $version, string $view, int $resource, int $edit, array $query, array $users, array $rooms, array $classes): string
    {
        $lesson = $edit > 0 ? $store->findLesson($edit) : null;
        $day = $lesson?->dayOfWeek ?? (int) ($query['day'] ?? 1);
        $slot = $lesson?->startSlotId ?? (int) ($query['start_slot'] ?? 0);
        $maxDuration = $this->maxDuration($version->id, $day, $slot);
        $lengthOptions = '';
        for ($duration = 1; $duration <= $maxDuration; $duration++) $lengthOptions .= '<option value="' . $duration . '"' . ($duration === ($lesson?->durationPeriods ?? 1) ? ' selected' : '') . '>' . $duration . ' period' . ($duration === 1 ? '' : 's') . '</option>';
        $fixed = $view === 'teacher' ? 'teacher_user_id' : ($view === 'room' ? 'room_id' : 'class_id');
        $fixedValue = $view === 'teacher' ? ($lesson?->teacherUserId ?? $resource) : ($view === 'room' ? ($lesson?->roomId ?? $resource) : ($lesson?->classId ?? $resource));
        $html = '<dialog class="lesson-dialog" open><form method="post"><a class="close" href="/admin/timetable?version=' . $version->id . '&view=' . $view . '&resource=' . $resource . '">Close</a><p class="eyebrow">' . $this->e($this->dayName($day)) . ' · version ' . $version->id . '</p><h2>' . ($lesson ? 'Edit assignment' : 'Add assignment') . '</h2><p class="muted">The ' . $this->e($view) . ' is fixed for this projection.</p><input type="hidden" name="action" value="resource_save_lesson"><input type="hidden" name="version" value="' . $version->id . '"><input type="hidden" name="view" value="' . $view . '"><input type="hidden" name="resource" value="' . $resource . '"><input type="hidden" name="day_of_week" value="' . $day . '"><input type="hidden" name="start_slot_id" value="' . $slot . '">' . ($lesson ? '<input type="hidden" name="lesson_id" value="' . $lesson->id . '">' : '');
        $html .= '<input type="hidden" name="' . $fixed . '" value="' . $fixedValue . '"><label>Teacher ' . ($view === 'teacher' ? '<strong>fixed</strong>' : $this->resourceSelect('teacher_user_id', $lesson?->teacherUserId ?? 0, $users, 'Add new teacher...')) . '</label><label>Room ' . ($view === 'room' ? '<strong>fixed</strong>' : $this->resourceSelect('room_id', $lesson?->roomId ?? 0, $rooms, 'Add new room...')) . '</label><label>Class ' . ($view === 'class' ? '<strong>fixed</strong>' : $this->resourceSelect('class_id', $lesson?->classId ?? 0, $classes, 'Add new class...')) . '</label><label>Length<select name="duration_periods\"><option value="1">1 period</option></select></label><div class="form-actions"><button>Save assignment</button><a class="button secondary" href="/admin/timetable?version=' . $version->id . '&view=' . $view . '&resource=' . $resource . '">Cancel</a></div></form>';
        $html = str_replace('<option value="1">1 period</option>', $lengthOptions, $html);
        if ($lesson) $html .= '<form method="post" class="delete-form"><input type="hidden" name="action" value="resource_delete_lesson"><input type="hidden" name="version" value="' . $version->id . '"><input type="hidden" name="view" value="' . $view . '"><input type="hidden" name="resource" value="' . $resource . '"><input type="hidden" name="lesson_id" value="' . $lesson->id . '"><button class="danger">Remove assignment</button></form>';
        return $html . '</dialog><script>document.addEventListener("change",function(e){if(e.target.value!=="__new__")return;var type=e.target.name==="teacher_user_id"?"teacher":(e.target.name==="room_id"?"room":"class");location.href="/admin/timetable?version=' . $version->id . '&view=' . $view . '&resource=' . $resource . '&create="+type;});</script>';
    }

    private function resourceSelect(string $name, int $selected, array $rows, string $addLabel): string
    {
        $html = '<select id="' . $name . '" name="' . $name . '"><option value="0">Select...</option>';
        foreach ($rows as $row) { $id = (int) $row['id']; $label = $row['code'] ?? ($row['staff_identifier'] ?: $row['display_name']); $html .= '<option value="' . $id . '"' . ($id === $selected ? ' selected' : '') . '>' . $this->e((string) $label) . '</option>'; }
        return $html . '<option value="__new__">' . $this->e($addLabel) . '</option></select>';
    }

    private function resourceName(string $view, int $resource, array $users, array $rooms, array $classes): string
    {
        $rows = $view === 'teacher' ? $users : ($view === 'room' ? $rooms : $classes);
        foreach ($rows as $row) if ((int) $row['id'] === $resource) return $view === 'teacher' ? (string) $row['display_name'] : (string) ($row['code'] ?? '');
        return '';
    }

    /** @param list<TimetableSlot> $slots */
    private function slotLabelById(array $slots, int $slotId): string
    {
        foreach ($slots as $slot) if ($slot->id === $slotId) return $slot->isTeaching() ? $this->periodLabel($slot) : $this->separatorLabel($slot);
        return 'period';
    }

    private function separatorLabel(TimetableSlot $slot): string
    {
        $label = trim((string) ($slot->label ?? ''));
        if ($slot->kind === 'lunch' && strtolower($label) === 'lunchtime') return 'Lunch';
        if ($label !== '') return $label;
        return match ($slot->kind) {
            'break' => 'Break',
            'lunch' => 'Lunch',
            default => 'Other',
        };
    }

    private function teacherCode(int $teacherId, array $users): string
    {
        foreach ($users as $user) if ((int) $user['id'] === $teacherId) return trim((string) ($user['staff_identifier'] ?? '')) ?: (string) $user['display_name'];
        return 'Teacher';
    }

    private function classTone(string $class): int { return abs(crc32($class)) % 6; }

    private function seedVersionStructure(ResourceTimetableStore $store, int $versionId): void
    {
        if ($store->slotsForVersion($versionId) !== []) return;
        $days = array_values(array_filter(array_map('intval', (array) ($this->settings['working_days'] ?? [1, 2, 3, 4, 5])), static fn (int $day): bool => $day >= 1 && $day <= 7));
        $periods = max(1, min(20, (int) ($this->settings['periods_per_day'] ?? 6)));
        $start = (string) ($this->settings['start_time'] ?? '08:00');
        if (!preg_match('/^\d{2}:\d{2}$/D', $start)) $start = '08:00';
        $length = max(1, (int) ($this->settings['standard_period_minutes'] ?? 60));
        $separators = [];
        foreach ((array) ($this->settings['separators'] ?? []) as $separator) {
            $after = (int) ($separator['after_period'] ?? 0);
            if ($after >= 1 && $after < $periods) $separators[$after] = $separator;
        }
        $service = new TimetableSlotService($store);
        foreach ($days as $day) {
            $sequence = 1;
            $clock = \DateTimeImmutable::createFromFormat('!H:i', $start) ?: new \DateTimeImmutable('08:00');
            for ($period = 1; $period <= $periods; $period++) {
                $end = $clock->modify('+' . $length . ' minutes');
                $service->create($versionId, $day, $sequence++, 'teaching', $period, 'P' . $period, $clock->format('H:i'), $end->format('H:i'));
                $clock = $end;
                if (isset($separators[$period])) {
                    $separator = $separators[$period];
                    $type = (string) ($separator['type'] ?? 'Other');
                    $kind = $type === 'Lunchtime' ? 'lunch' : ($type === 'Break' ? 'break' : 'non_teaching');
                    $label = trim((string) ($separator['label'] ?? '')) ?: ($type === 'Lunchtime' ? 'Lunch' : $type);
                    $minutes = max(1, (int) ($separator['duration_minutes'] ?? 15));
                    $separatorEnd = $clock->modify('+' . $minutes . ' minutes');
                    $service->create($versionId, $day, $sequence++, $kind, null, $label, $clock->format('H:i'), $separatorEnd->format('H:i'));
                    $clock = $separatorEnd;
                }
            }
        }
    }

    /** @param array<string, mixed> $input */
    private function mutate(array $input): string
    {
        $action = (string) ($input['action'] ?? '');
        if ($action === 'create_version') {
            $id = (new TimetableTemplateService($this->store))->create($this->organisationId, null, $this->nullable($input['label'] ?? null), null, $this->settings);
            return 'Timetable version created: ' . $id;
        }
        $versionId = (int) ($input['version'] ?? 0);
        $version = $this->store->findVersion($versionId);
        if ($version === null || $version->organisationId !== $this->organisationId) throw new TimetableValidationException(['Timetable version is not available for this organisation.']);
        $teacher = (int) ($input['teacher_user_id'] ?? 0);
        $day = (int) ($input['day_of_week'] ?? 0);
        $slot = (int) ($input['start_slot_id'] ?? 0);
        $duration = max(1, (int) ($input['duration_periods'] ?? 1));
        if (!$this->conjoinedPeriodsAllowed() && $duration !== 1) throw new TimetableValidationException(['Conjoined periods are disabled in organisation settings.']);
        $class = (string) ($input['class_code'] ?? '');
        $room = (string) ($input['room_code'] ?? '');
        $service = new RecurringLessonService($this->store);
        if ($action === 'create_lesson') return 'Lesson created: ' . $service->create($versionId, $teacher, $day, $slot, $duration, $class, $room);
        $lessonId = (int) ($input['lesson_id'] ?? 0);
        $existing = $this->store->findLesson($lessonId);
        if ($existing === null || $existing->timetableVersionId !== $versionId) throw new TimetableValidationException(['Lesson is not part of the selected timetable version.']);
        if ($action === 'update_lesson') {
            $service->update($lessonId, $teacher, $day, $slot, $duration, $class, $room);
            return 'Lesson updated.';
        }
        if ($action === 'delete_lesson') {
            $service->remove($lessonId);
            return 'Lesson removed.';
        }
        throw new TimetableValidationException(['Unknown timetable action.']);
    }

    /** @param list<TimetableVersion> $versions @param array<string, mixed> $query */
    private function page(array $versions, ?TimetableVersion $version, array $query, ?string $message): string
    {
        $users = $version === null ? [] : $this->store->usersForOrganisation($this->organisationId);
        $selectedTeacher = (int) ($query['teacher'] ?? $query['staff'] ?? ($users[0]['id'] ?? 0));
        $selectedTeacher = $this->validTeacher($selectedTeacher, $users) ? $selectedTeacher : (int) ($users[0]['id'] ?? 0);
        $selectedEdit = (int) ($query['edit'] ?? 0);
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable</p><h1>Timetable editor</h1></div><a class="button" href="/admin/people">Manage people</a></section>';
        $body .= '<p class="context">' . ($version === null ? 'Create a timetable version to begin.' : $this->versionContext($version)) . '</p>';
        $body .= '<form class="toolbar" method="get"><label>Timetable version ' . $this->versionSelect($versions, $version?->id) . '</label><label>Teacher / staff member ' . $this->selectFromRows('teacher', $selectedTeacher, $users) . '</label><button>Show week</button></form>';
        if ($message !== null) $body .= '<p class="message">' . $this->e($message) . '</p>';
        if ($version !== null && $selectedTeacher > 0) {
            $body .= $this->weekGrid($version, $selectedTeacher, $users);
            $body .= $this->lessonEditor($version, $selectedTeacher, $selectedEdit, $query);
        } elseif ($version !== null) {
            $body .= '<p class="notice">Add a teacher in People before populating a timetable. A teacher may remain empty.</p>';
        }
        return PageLayout::render('Admin timetable', $body, $this->user);
    }

    /** @param list<array{id:int,display_name:string,staff_identifier:?string,is_active:bool}> $users */
    private function validTeacher(int $selected, array $users): bool
    {
        foreach ($users as $user) if ((int) $user['id'] === $selected && (bool) $user['is_active']) return true;
        return false;
    }

    /** @param list<TimetableVersion> $versions */
    private function versionSelect(array $versions, ?int $selected): string
    {
        $options = [];
        foreach ($versions as $version) $options[$version->id] = ($version->label ?: 'Untitled') . ($version->id === $this->activeVersion()?->id ? ' (active)' : '');
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
        $state = $version->id === $this->activeVersion()?->id ? 'active' : 'inactive';
        return 'Editing <strong>' . $this->e($version->label ?: 'Untitled timetable') . '</strong> (' . $state . ').';
    }

    /** @param list<TimetableVersion> $versions */
    private function activeVersion(): ?TimetableVersion
    {
        $id = $this->store->activeVersionId($this->organisationId);
        return $id === null ? null : $this->store->findVersion($id);
    }

    /** @param list<array{id:int,display_name:string,staff_identifier:?string,is_active:bool}> $users */
    private function weekGrid(TimetableVersion $version, int $teacher, array $users): string
    {
        $slots = $this->store->slotsForVersion($version->id);
        $lessons = array_values(array_filter($this->store->lessonsForVersion($version->id), static fn (RecurringLesson $lesson): bool => $lesson->teacherUserId === $teacher));
        $days = $this->workingDays($slots, $version->firstDayOfWeek);
        $byDay = [];
        $columns = [];
        foreach ($slots as $slot) {
            if (!in_array($slot->dayOfWeek, $days, true)) continue;
            $byDay[$slot->dayOfWeek][$slot->sequenceNumber] = $slot;
            if (!isset($columns[$slot->sequenceNumber])) $columns[$slot->sequenceNumber] = $slot;
        }
        ksort($columns);
        $teacherName = $this->teacherName($teacher, $users);
        $html = '<section class="editor-section"><div class="section-heading"><div><p class="eyebrow">Weekly view</p><h2>' . $this->e($teacherName) . '</h2></div><p class="muted">Click an empty period to add a lesson. Click a lesson to edit it.</p></div><div class="timetable-scroll"><table class="admin-week-grid"><thead><tr><th>Day</th>';
        foreach ($columns as $slot) $html .= '<th' . ($slot->isTeaching() ? '' : ' class="separator-column"') . '>' . $this->e($slot->isTeaching() ? $this->periodLabel($slot) : $this->separatorLabel($slot)) . '</th>';
        $html .= '</tr></thead><tbody>';
        foreach ($days as $day) {
            $html .= '<tr><th class="day-label">' . $this->e($this->dayName($day)) . '</th>';
            $sequenceNumbers = array_keys($columns);
            for ($index = 0; $index < count($sequenceNumbers); $index++) {
                $sequence = $sequenceNumbers[$index];
                $slot = $byDay[$day][$sequence] ?? null;
                if ($slot === null) { $html .= '<td class="empty-cell">—</td>'; continue; }
                if (!$slot->isTeaching()) { $html .= '<td class="separator-cell">' . $this->e($this->separatorLabel($slot)) . '</td>'; continue; }
                $lesson = null;
                foreach ($lessons as $candidate) if ($candidate->dayOfWeek === $day && $candidate->startSlotId === $slot->id) { $lesson = $candidate; break; }
                if ($lesson !== null) {
                    $span = min($lesson->durationPeriods, count($sequenceNumbers) - $index);
                    $html .= '<td colspan="' . $span . '"><a class="admin-lesson" href="' . $this->cellUrl($version->id, $teacher, $lesson->id, $day, $slot->id) . '"><strong>' . $this->e($lesson->classCode) . '</strong><span>' . $this->e($lesson->roomCode) . '</span><small>' . $lesson->durationPeriods . ' period' . ($lesson->durationPeriods === 1 ? '' : 's') . '</small></a></td>';
                    $index += $span - 1;
                } else {
                    $html .= '<td class="empty-cell"><a class="empty-period" href="' . $this->cellUrl($version->id, $teacher, 0, $day, $slot->id) . '"><span>+</span><small>Add lesson</small></a></td>';
                }
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div></section>';
    }

    /** @param list<TimetableSlot> $slots @return list<int> */
    private function workingDays(array $slots, ?int $templateFirstDay = null): array
    {
        $days = array_values(array_filter(array_map('intval', (array) ($this->settings['working_days'] ?? [])), static fn (int $day): bool => $day >= 1 && $day <= 7));
        if ($days === []) $days = array_values(array_unique(array_map(static fn (TimetableSlot $slot): int => $slot->dayOfWeek, $slots)));
        $first = $templateFirstDay ?? (int) ($this->settings['first_day_of_week'] ?? ($days[0] ?? 1));
        usort($days, static fn (int $a, int $b): int => (($a - $first + 7) % 7) <=> (($b - $first + 7) % 7));
        return $days;
    }

    private function cellUrl(int $version, int $teacher, int $edit, int $day, int $slot): string
    {
        return '/admin/timetable?version=' . $version . '&teacher=' . $teacher . '&edit=' . $edit . '&day=' . $day . '&start_slot=' . $slot;
    }

    /** @param array<string, mixed> $query */
    private function lessonEditor(TimetableVersion $version, int $teacher, int $edit, array $query): string
    {
        $lesson = $edit > 0 ? $this->store->findLesson($edit) : null;
        if ($lesson !== null && ($lesson->timetableVersionId !== $version->id || $lesson->teacherUserId !== $teacher)) $lesson = null;
        $day = $lesson?->dayOfWeek ?? (int) ($query['day'] ?? 1);
        $slotId = $lesson?->startSlotId ?? (int) ($query['start_slot'] ?? 0);
        $slots = array_values(array_filter($this->store->slotsForVersion($version->id), static fn (TimetableSlot $slot): bool => $slot->dayOfWeek === $day && $slot->isTeaching()));
        $maxDuration = $this->maxDuration($version->id, $day, $slotId);
        $action = $lesson === null ? 'create_lesson' : 'update_lesson';
        $title = $lesson === null ? 'Add lesson' : 'Edit lesson';
        $html = '<dialog class="lesson-dialog" open><form method="post"><a class="close" href="/admin/timetable?version=' . $version->id . '&teacher=' . $teacher . '">Close</a><p class="eyebrow">' . $this->e($this->dayName($day)) . ' · ' . $this->e($this->slotLabel($slots, $slotId)) . '</p><h2>' . $title . '</h2><p class="muted">Editing ' . $this->e($this->teacherName($teacher)) . '. The teacher is fixed by this week view.</p><input type="hidden" name="action" value="' . $action . '"><input type="hidden" name="version" value="' . $version->id . '"><input type="hidden" name="teacher_user_id" value="' . $teacher . '"><input type="hidden" name="day_of_week" value="' . $day . '"><input type="hidden" name="start_slot_id" value="' . $slotId . '">';
        if ($lesson !== null) $html .= '<input type="hidden" name="lesson_id" value="' . $lesson->id . '">';
        $html .= '<label>Class code<input name="class_code" required value="' . $this->e($lesson?->classCode ?? '') . '"></label><label>Room<input name="room_code" required value="' . $this->e($lesson?->roomCode ?? '') . '"></label>';
        if ($this->conjoinedPeriodsAllowed()) {
            $html .= '<label>Length / span<select name="duration_periods">';
            for ($duration = 1; $duration <= $maxDuration; $duration++) $html .= '<option value="' . $duration . '"' . ($duration === ($lesson?->durationPeriods ?? 1) ? ' selected' : '') . '>' . $duration . ' period' . ($duration === 1 ? '' : 's') . '</option>';
            $html .= '</select></label>';
        } else $html .= '<input type="hidden" name="duration_periods" value="1"><p class="muted">Conjoined periods are disabled in Settings.</p>';
        $html .= '<div class="form-actions"><button>Save lesson</button><a class="button secondary" href="/admin/timetable?version=' . $version->id . '&teacher=' . $teacher . '">Cancel</a></div></form>';
        if ($lesson !== null) $html .= '<form method="post" class="delete-form"><input type="hidden" name="action" value="delete_lesson"><input type="hidden" name="version" value="' . $version->id . '"><input type="hidden" name="teacher_user_id" value="' . $teacher . '"><input type="hidden" name="lesson_id" value="' . $lesson->id . '"><button class="danger">Remove lesson</button></form>';
        return $html . '</dialog>';
    }

    /** @param list<TimetableSlot> $slots */
    private function slotLabel(array $slots, int $slotId): string
    {
        foreach ($slots as $slot) if ($slot->id === $slotId) return $this->periodLabel($slot);
        return 'period';
    }

    /** @param list<array{id:int,display_name:string,staff_identifier:?string,is_active:bool}> $users */
    private function teacherName(int $teacher, array $users = []): string
    {
        if ($users === []) $users = $this->store->usersForOrganisation($this->organisationId);
        foreach ($users as $user) if ((int) $user['id'] === $teacher) return $user['display_name'];
        return 'selected teacher';
    }

    private function maxDuration(int $versionId, int $day, int $slotId): int
    {
        foreach ($this->store->slotsForVersion($versionId) as $slot) if ($slot->id === $slotId) {
            $daySlots = array_values(array_filter($this->store->slotsForVersion($versionId), static fn (TimetableSlot $candidate): bool => $candidate->dayOfWeek === $day));
            return max(1, count(TimetableRules::occupiedSequences($daySlots, $slot)));
        }
        return 1;
    }

    private function conjoinedPeriodsAllowed(): bool { return (bool) ($this->settings['allow_double_periods'] ?? true); }

    private function dayName(int $day): string { return ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'][$day - 1] ?? 'Day ' . $day; }
    private function periodLabel(TimetableSlot $slot): string { return $slot->label !== '' ? $slot->label : 'P' . $slot->teachingPeriodNumber; }
    private function nullable(mixed $value): ?string { $value = is_string($value) ? trim($value) : ''; return $value === '' ? null : $value; }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
