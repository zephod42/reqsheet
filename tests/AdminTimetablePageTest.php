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
            new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'P1', '09:00:00', '10:00:00'),
            new TimetableSlot(102, 1, 1, 2, 'break', null, 'Break', '10:00:00', '10:15:00'),
            new TimetableSlot(103, 1, 1, 3, 'teaching', 2, 'P2', '10:15:00', '11:15:00'),
            new TimetableSlot(201, 1, 2, 1, 'teaching', 1, 'P1', '09:00:00', '10:00:00'),
        ];
        $page = new AdminTimetablePage($store, 1);
        $staff = $page->handle('GET', ['version' => 1, 'mode' => 'staff', 'staff' => 10], []);
        assertContains('Pilot timetable', $staff, 'Version context was not rendered.');
        assertContains('Staff member timetable', $staff, 'Staff view was not rendered.');
        assertContains('Break', $staff, 'Configured separator was not rendered.');
        assertContains('Empty teaching cell', $staff, 'Available teaching cell was not rendered.');

        $created = $page->handle('POST', [], ['action' => 'create_lesson', 'version' => 1, 'teacher_user_id' => 10, 'day_of_week' => 1, 'start_slot_id' => 101, 'duration_periods' => 1, 'class_code' => '13PHY', 'room_code' => 'P1']);
        assertContains('Lesson created', $created, 'Lesson creation was not handled.');
        $lessonId = $store->lessons[0]->id;
        $room = $page->handle('GET', ['version' => 1, 'mode' => 'room', 'room' => 'P1'], []);
        assertContains('13PHY', $room, 'Room view did not use the shared lesson data.');
        $day = $page->handle('GET', ['version' => 1, 'mode' => 'day', 'day' => 1], []);
        assertContains('Monday overview', $day, 'Day view was not rendered.');

        $updated = $page->handle('POST', [], ['action' => 'update_lesson', 'version' => 1, 'lesson_id' => $lessonId, 'teacher_user_id' => 10, 'day_of_week' => 1, 'start_slot_id' => 103, 'duration_periods' => 1, 'class_code' => '13PHY-UPDATED', 'room_code' => 'P2']);
        assertContains('Lesson updated', $updated, 'Lesson update was not handled.');
        $page->handle('POST', [], ['action' => 'delete_lesson', 'version' => 1, 'lesson_id' => $lessonId]);
        assertSameValue([], $store->lessons, 'Lesson removal was not handled.');

        $store->lessons[] = new RecurringLesson(99, 1, 10, 1, 101, 1, 'HISTORICAL', 'P1');
        $store->occurrences[99] = 1;
        $blocked = $page->handle('POST', [], ['action' => 'delete_lesson', 'version' => 1, 'lesson_id' => 99]);
        assertContains('cannot be removed after historical occurrences', $blocked, 'Historical lesson removal was not blocked.');
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    if (!str_contains($haystack, $needle)) {
        throw new \RuntimeException($message);
    }
}
