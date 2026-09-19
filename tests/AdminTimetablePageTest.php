<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\AdminAccess;
use Reqsheet\Http\AdminTimetablePage;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\ResourceTimetableStore;

final class AdminTimetablePageTest
{
    public static function run(): void
    {
        assertSameValue(true, AdminAccess::configured(['REQSHEET_ADMIN_KEY' => 'key']), 'Admin gate was not recognised as configured.');
        assertSameValue(true, AdminAccess::allowed(['REQSHEET_ADMIN_KEY' => 'key'], ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'key']), 'Valid admin credentials were rejected.');
        assertSameValue(false, AdminAccess::allowed(['REQSHEET_ADMIN_KEY' => 'key'], ['PHP_AUTH_USER' => 'admin', 'PHP_AUTH_PW' => 'wrong']), 'Invalid admin credentials were accepted.');
        assertSameValue(12, AdminAccess::organisationId(['REQSHEET_ADMIN_ORGANISATION_ID' => '12']), 'Admin organisation was not parsed.');

        $store = new ConfigurationStore();
        $store->versions[1] = new TimetableVersion(1, 1, 'Pilot timetable', new DateTimeImmutable('2026-09-01'), null);
        $store->slots = [
            new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'Period One', '09:00:00', '10:00:00'),
            new TimetableSlot(102, 1, 1, 2, 'break', null, 'Break', '10:00:00', '10:15:00'),
            new TimetableSlot(103, 1, 1, 3, 'teaching', 2, 'P2', '10:15:00', '11:15:00'),
            new TimetableSlot(201, 1, 2, 1, 'teaching', 1, 'P1', '09:00:00', '10:00:00'),
        ];
        $page = new AdminTimetablePage($store, 1, [
            'working_days' => [1, 2], 'first_day_of_week' => 1, 'periods_per_day' => 3, 'allow_double_periods' => true,
        ]);
        $staff = $page->handle('GET', ['version' => 1, 'teacher' => 10], []);
        assertContains('Pilot timetable', $staff, 'Version context was not rendered.');
        assertContains('Teacher A', $staff, 'Teacher-first selection was not rendered.');
        assertContains('name="teacher"', $staff, 'Teacher selector was not rendered.');
        assertContains('Break', $staff, 'Configured separator was not rendered.');
        assertContains('Period One', $staff, 'Configured teaching-period label was not rendered.');
        assertContains('Add lesson', $staff, 'Available period was not rendered as an add target.');

        $emptyTeacher = $page->handle('GET', ['version' => 1, 'teacher' => 11], []);
        assertContains('Teacher B', $emptyTeacher, 'Second teacher was not selectable.');
        assertContains('Weekly view', $emptyTeacher, 'Empty teacher week was not rendered.');

        $created = $page->handle('POST', [], ['action' => 'create_lesson', 'version' => 1, 'teacher_user_id' => 10, 'day_of_week' => 1, 'start_slot_id' => 101, 'duration_periods' => 1, 'class_code' => '13PHY', 'room_code' => 'P1']);
        assertContains('Lesson created', $created, 'Lesson creation was not handled.');
        $lessonId = $store->lessons[0]->id;
        $saved = $page->handle('GET', ['version' => 1, 'teacher' => 10], []);
        assertContains('13PHY', $saved, 'Saved lesson was not rendered in the selected teacher week.');
        assertContains('P1', $saved, 'Saved lesson room was not rendered in the selected teacher week.');

        $updated = $page->handle('POST', [], ['action' => 'update_lesson', 'version' => 1, 'lesson_id' => $lessonId, 'teacher_user_id' => 10, 'day_of_week' => 1, 'start_slot_id' => 103, 'duration_periods' => 1, 'class_code' => '13PHY-UPDATED', 'room_code' => 'P2']);
        assertContains('Lesson updated', $updated, 'Lesson update was not handled.');
        $page->handle('POST', [], ['action' => 'delete_lesson', 'version' => 1, 'lesson_id' => $lessonId]);
        assertSameValue([], $store->lessons, 'Lesson removal was not handled.');

        $store->lessons[] = new RecurringLesson(99, 1, 10, 1, 101, 1, 'HISTORICAL', 'P1');
        $store->occurrences[99] = 1;
        $blocked = $page->handle('POST', [], ['action' => 'delete_lesson', 'version' => 1, 'lesson_id' => 99]);
        assertContains('cannot be removed after historical occurrences', $blocked, 'Historical lesson removal was not blocked.');

        $disabled = new AdminTimetablePage($store, 1, ['working_days' => [1], 'first_day_of_week' => 1, 'allow_double_periods' => false]);
        $disabledView = $disabled->handle('GET', ['version' => 1, 'teacher' => 10, 'edit' => 99], []);
        assertContains('Conjoined periods are disabled in Settings.', $disabledView, 'Disabled conjoined-period mode exposed the wrong editor state.');
        $blockedSpan = $disabled->handle('POST', [], ['action' => 'create_lesson', 'version' => 1, 'teacher_user_id' => 10, 'day_of_week' => 1, 'start_slot_id' => 101, 'duration_periods' => 2, 'class_code' => 'SPAN', 'room_code' => 'P1']);
        assertContains('Conjoined periods are disabled', $blockedSpan, 'Disabled conjoined-period mode permitted a span.');

        self::resourceBuilder();
    }

    private static function resourceBuilder(): void
    {
        $store = new ResourceConfigurationStore();
        $store->versions[1] = new TimetableVersion(1, 1, 'Resource timetable', new DateTimeImmutable('2026-09-01'), null);
        $store->rooms = [['id' => 401, 'code' => 'L1'], ['id' => 402, 'code' => 'L2']];
        $store->classes = [['id' => 501, 'code' => 'Y12Ph']];
        $store->slots = [
            new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'P1', '09:00:00', '10:00:00'),
            new TimetableSlot(102, 1, 1, 2, 'break', null, 'Break', '10:00:00', '10:15:00'),
            new TimetableSlot(103, 1, 1, 3, 'teaching', 2, 'P2', '10:15:00', '11:15:00'),
            new TimetableSlot(104, 1, 1, 4, 'lunch', null, 'Lunch', '11:15:00', '12:00:00'),
            new TimetableSlot(105, 1, 1, 5, 'non_teaching', null, 'Lab meeting', '12:00:00', '12:30:00'),
            new TimetableSlot(106, 1, 1, 6, 'teaching', 3, 'P3', '12:30:00', '13:30:00'),
            new TimetableSlot(201, 1, 2, 1, 'teaching', 1, 'P1', '09:00:00', '10:00:00'),
            new TimetableSlot(202, 1, 2, 2, 'teaching', 2, 'P2', '10:00:00', '11:00:00'),
            new TimetableSlot(203, 1, 2, 3, 'teaching', 3, 'P3', '11:00:00', '12:00:00'),
            new TimetableSlot(204, 1, 2, 4, 'lunch', null, 'Lunch', '12:00:00', '12:45:00'),
            new TimetableSlot(205, 1, 2, 5, 'teaching', 4, 'P4', '12:45:00', '13:45:00'),
        ];
        $page = new AdminTimetablePage($store, 1, ['working_days' => [1, 2], 'first_day_of_week' => 1, 'allow_double_periods' => true]);
        $grid = $page->handle('GET', ['version' => 1, 'view' => 'teacher', 'resource' => 10], []);
        assertContains('Break', $grid, 'Break separator label was not rendered.');
        assertContains('Lunch', $grid, 'Lunch separator label was not rendered.');
        assertContains('Lab meeting', $grid, 'Custom separator label was not rendered.');
        assertContains('admin-resource-grid', $grid, 'Resource grid was not rendered.');
        assertContains('class="empty-period"', $grid, 'Empty teaching cells were not clickable.');

        $teacherEditor = $page->handle('GET', ['version' => 1, 'view' => 'teacher', 'resource' => 10, 'day' => 1, 'start_slot' => 101], []);
        assertContains('id="assignment-editor"', $teacherEditor, 'Teacher cell editor did not open.');
        assertContains('Teacher TA', $teacherEditor, 'Teacher projection context was not rendered.');
        assertNotContains('<select id="teacher_user_id"', $teacherEditor, 'Teacher projection redundantly exposed a teacher selector.');
        assertContains('<select id="room_id"', $teacherEditor, 'Teacher projection omitted the room selector.');
        assertContains('<select id="class_id"', $teacherEditor, 'Teacher projection omitted the class selector.');
        assertContains('showModal', $teacherEditor, 'Teacher editor did not include modal initialization.');

        $roomEditor = $page->handle('GET', ['version' => 1, 'view' => 'room', 'resource' => 401, 'day' => 1, 'start_slot' => 101], []);
        assertContains('Room L1', $roomEditor, 'Room projection context was not rendered.');
        assertNotContains('<select id="room_id"', $roomEditor, 'Room projection redundantly exposed a room selector.');
        assertContains('<select id="teacher_user_id"', $roomEditor, 'Room projection omitted the teacher selector.');
        assertContains('<select id="class_id"', $roomEditor, 'Room projection omitted the class selector.');

        $classEditor = $page->handle('GET', ['version' => 1, 'view' => 'class', 'resource' => 501, 'day' => 1, 'start_slot' => 101], []);
        assertContains('Class Y12Ph', $classEditor, 'Class projection context was not rendered.');
        assertNotContains('<select id="class_id"', $classEditor, 'Class projection redundantly exposed a class selector.');
        assertContains('<select id="teacher_user_id"', $classEditor, 'Class projection omitted the teacher selector.');
        assertContains('<select id="room_id"', $classEditor, 'Class projection omitted the room selector.');

        $created = $page->handle('POST', [], ['action' => 'resource_save_lesson', 'version' => 1, 'view' => 'teacher', 'resource' => 10, 'teacher_user_id' => 10, 'day_of_week' => 1, 'start_slot_id' => 101, 'duration_periods' => 1, 'class_id' => 501, 'room_id' => 401]);
        assertContains('Lesson created.', $created, 'Resource assignment creation was not handled.');
        $lessonId = $store->lessons[0]->id;
        $updated = $page->handle('POST', [], ['action' => 'resource_save_lesson', 'version' => 1, 'view' => 'teacher', 'resource' => 10, 'lesson_id' => $lessonId, 'teacher_user_id' => 10, 'day_of_week' => 2, 'start_slot_id' => 201, 'duration_periods' => 3, 'class_id' => 501, 'room_id' => 402]);
        assertContains('Lesson updated.', $updated, 'Resource assignment update was not handled.');
        assertSameValue(3, $store->lessons[0]->durationPeriods, 'Conjoined span was not preserved in resource editing.');
        $spanned = $page->handle('GET', ['version' => 1, 'view' => 'teacher', 'resource' => 10], []);
        assertContains('rowspan="3"', $spanned, 'Conjoined lesson did not occupy its complete visual span.');
        assertContains('Teacher TA', $spanned, 'Lesson card leaked a teacher database ID instead of initials.');
        assertContains('class-tone-', $spanned, 'Admin lesson card did not receive a deterministic class colour.');
        $clash = $page->handle('POST', [], ['action' => 'resource_save_lesson', 'version' => 1, 'view' => 'teacher', 'resource' => 11, 'teacher_user_id' => 11, 'day_of_week' => 2, 'start_slot_id' => 201, 'duration_periods' => 1, 'class_id' => 501, 'room_id' => 401]);
        assertContains('conflict', strtolower($clash), 'Resource clash validation was not surfaced in the editor.');
        $deleted = $page->handle('POST', [], ['action' => 'resource_delete_lesson', 'version' => 1, 'view' => 'teacher', 'resource' => 10, 'lesson_id' => $lessonId]);
        assertContains('Lesson removed.', $deleted, 'Resource assignment deletion was not handled.');
        assertSameValue([], $store->lessons, 'Deleted resource assignment remained in the store.');
    }
}

final class ResourceConfigurationStore extends ConfigurationStore implements ResourceTimetableStore
{
    /** @var list<array{id:int,code:string}> */
    public array $rooms = [];
    /** @var list<array{id:int,code:string}> */
    public array $classes = [];

    public function roomsForOrganisation(int $organisationId): array { return $this->rooms; }
    public function classesForOrganisation(int $organisationId): array { return $this->classes; }
    public function createRoom(int $organisationId, string $code): int { $id = $this->nextResourceId(); $this->rooms[] = ['id' => $id, 'code' => trim($code)]; return $id; }
    public function createClass(int $organisationId, string $code): int { $id = $this->nextResourceId(); $this->classes[] = ['id' => $id, 'code' => trim($code)]; return $id; }
    public function createTeacher(int $organisationId, string $code, string $displayName): int { $id = $this->nextResourceId(); $this->users[] = ['id' => $id, 'display_name' => $displayName !== '' ? $displayName : $code, 'staff_identifier' => $code, 'is_active' => true]; $this->teachers[$id] = $organisationId; return $id; }
    public function roomBelongsToOrganisation(int $roomId, int $organisationId): bool { return $this->roomCode($roomId) !== null; }
    public function classBelongsToOrganisation(int $classId, int $organisationId): bool { return $this->classCode($classId) !== null; }
    public function roomCode(int $roomId): ?string { foreach ($this->rooms as $room) if ($room['id'] === $roomId) return $room['code']; return null; }
    public function classCode(int $classId): ?string { foreach ($this->classes as $class) if ($class['id'] === $classId) return $class['code']; return null; }
    public function insertResourceLesson(int $versionId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, int $classId, int $roomId): int
    {
        $id = $this->insertLesson($versionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, (string) $this->classCode($classId), (string) $this->roomCode($roomId));
        $this->lessons[array_key_last($this->lessons)] = new RecurringLesson($id, $versionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, (string) $this->classCode($classId), (string) $this->roomCode($roomId), $classId, $roomId);
        return $id;
    }
    public function updateResourceLesson(int $lessonId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, int $classId, int $roomId): void
    {
        foreach ($this->lessons as $index => $lesson) if ($lesson->id === $lessonId) $this->lessons[$index] = new RecurringLesson($lessonId, $lesson->timetableVersionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, (string) $this->classCode($classId), (string) $this->roomCode($roomId), $classId, $roomId);
    }
    private function nextResourceId(): int { return max(array_merge([500], array_column($this->rooms, 'id'), array_column($this->classes, 'id'), array_keys($this->teachers))) + 1; }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException($message);
    }
}

function assertNotContains(string $needle, string $haystack, string $message): void
{
    if (str_contains($haystack, $needle)) {
        throw new \RuntimeException($message);
    }
}
