<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Date\DateDisplay;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Http\TeacherDayPage;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Timetable\TimetableSlot;

final class TeacherDisplayTest
{
    public static function run(): void
    {
        $store = new TeacherStore();
        $store->slots[] = new TimetableSlot(106, 1, 1, 6, 'non_teaching', null, 'Assembly');
        $week = (new TeacherWeekPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-09'), ['staff_identifier' => 'NEV']))->handle('GET', ['date' => '2026-09-09'], []);
        assertContainsValue('class="separator-row"', $week, 'Non-teaching teacher rows were not marked compact.');
        assertSameValue(3, substr_count($week, 'class="separator-row"'), 'Break, Lunch and custom separators were not all compact rows.');
        assertContainsValue('Assembly', $week, 'Custom separator was not rendered.');
        assertContainsValue('rowspan="2"', $week, 'Normal multi-period lesson tiles changed while compacting separators.');

        $day = (new TeacherDayPage(new TeacherPlanningService($store), 1, 10, new DateTimeImmutable('2026-09-07'), ['roles' => ['teacher']], 'MM/DD/YYYY'))->handle('GET', ['date' => '2026-09-07']);
        assertContainsValue('09/07/2026', $day, 'Configured date format was not displayed in Teacher Day View.');
        assertContainsValue('value="2026-09-07"', $day, 'Teacher Day View changed the ISO date input value.');
        assertContainsValue('/teacher/day?date=2026-09-06', $day, 'Date navigation no longer uses ISO dates.');

        $date = new DateTimeImmutable('2026-09-07');
        assertSameValue('07/09/2026', (new DateDisplay('DD/MM/YYYY'))->format($date), 'DD/MM/YYYY formatting is incorrect.');
        assertSameValue('09/07/2026', (new DateDisplay('MM/DD/YYYY'))->format($date), 'MM/DD/YYYY formatting is incorrect.');
        assertSameValue('2026/09/07', (new DateDisplay('YYYY/MM/DD'))->format($date), 'YYYY/MM/DD formatting is incorrect.');
    }
}
