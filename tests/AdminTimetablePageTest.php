<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\AdminAccess;
use Reqsheet\Http\AdminTimetablePage;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;
use Reqsheet\Timetable\RecurringLesson;

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
        assertContains('Double periods are disabled in Settings.', $disabledView, 'Disabled double-period mode exposed the wrong editor state.');
        $blockedSpan = $disabled->handle('POST', [], ['action' => 'create_lesson', 'version' => 1, 'teacher_user_id' => 10, 'day_of_week' => 1, 'start_slot_id' => 101, 'duration_periods' => 2, 'class_code' => 'SPAN', 'room_code' => 'P1']);
        assertContains('Double periods are disabled', $blockedSpan, 'Disabled double-period mode permitted a span.');
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException($message);
    }
}
