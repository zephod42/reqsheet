<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\AdminTimetableCsvExport;
use Reqsheet\Timetable\BlankTimetableCsvExporter;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\TimetableCsvExportException;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;

final class BlankTimetableCsvExporterTest
{
    public static function run(): void
    {
        $store = new ResourceConfigurationStore();
        $store->versions[1] = new TimetableVersion(1, 1, 'Autumn & Spring', new DateTimeImmutable('2026-09-01'), null, 7);
        $store->slots = [
            new TimetableSlot(201, 1, 1, 1, 'teaching', 1, 'Monday P1', '09:00:00', '10:00:00'),
            new TimetableSlot(101, 1, 7, 1, 'teaching', 1, 'Sunday P1', '09:00:00', '10:00:00'),
            new TimetableSlot(102, 1, 7, 2, 'break', null, 'Break', '10:00:00', '10:15:00'),
            new TimetableSlot(103, 1, 7, 3, 'teaching', 2, 'Period, "Two"', '10:15:00', '11:15:00'),
        ];
        $store->rooms = [
            ['id' => 401, 'code' => 'Lab, "A"', 'organisation_id' => 1],
            ['id' => 402, 'code' => 'Z2', 'organisation_id' => 1],
            ['id' => 499, 'code' => 'OTHER-TENANT', 'organisation_id' => 2],
        ];
        $admin = ['organisation_id' => 1, 'roles' => ['administrator'], 'is_admin' => true];
        $action = new AdminTimetableCsvExport(new BlankTimetableCsvExporter($store), 1, $admin);

        $store->lessonReads = 0;
        $empty = $action->create(1);
        assertSameValue(0, $store->lessonReads, 'Blank timetable CSV export inspected existing lesson assignments.');
        assertSameValue('reqsheet-autumn-spring-blank.csv', $empty->filename, 'Blank timetable CSV filename was not derived safely from the template name.');
        $rows = self::rows($empty->content);
        assertSameValue(['Day', 'Period', 'Room', 'Class', 'Teacher'], $rows[0], 'Blank timetable CSV headers changed.');
        assertSameValue(6, $empty->rowCount, 'Blank timetable CSV row count did not equal teaching slots multiplied by organisation rooms.');
        assertSameValue(7, count($rows), 'Blank timetable CSV contained an unexpected number of physical rows.');
        assertSameValue(['Sunday', 'Sunday P1', 'Lab, "A"', '', ''], $rows[1], 'First-day or room ordering was incorrect.');
        assertSameValue(['Sunday', 'Sunday P1', 'Z2', '', ''], $rows[2], 'Room ordering was not preserved.');
        assertSameValue(['Sunday', 'Period, "Two"', 'Lab, "A"', '', ''], $rows[3], 'Teaching-period ordering or CSV escaping was incorrect.');
        assertSameValue('Monday', $rows[5][0], 'Configured first-day ordering was not applied.');
        assertSameValue(false, str_contains($empty->content, 'Break'), 'A non-teaching separator was exported as an assignable period.');
        assertSameValue(false, str_contains($empty->content, 'OTHER-TENANT'), 'A room from another organisation was exported.');
        assertContainsValue('"Lab, ""A"""', $empty->content, 'Unusual room labels were not escaped as valid CSV.');
        foreach (array_slice($rows, 1) as $row) {
            assertSameValue('', $row[3], 'Blank timetable CSV exported a Class value.');
            assertSameValue('', $row[4], 'Blank timetable CSV exported a Teacher value.');
        }

        $store->lessons[] = new RecurringLesson(90, 1, 10, 7, 101, 2, 'SECRET-CLASS', 'Lab, "A"', 501, 401);
        $populated = $action->create(1);
        assertSameValue(0, $store->lessonReads, 'Populated timetable CSV export inspected existing lesson assignments.');
        assertSameValue($empty->content, $populated->content, 'Existing lesson assignments influenced the blank CSV export.');
        assertSameValue(false, str_contains($populated->content, 'SECRET-CLASS'), 'Existing class data leaked into the blank CSV export.');

        self::expectReason(
            TimetableCsvExportException::UNAUTHORISED,
            static fn () => (new AdminTimetableCsvExport(new BlankTimetableCsvExporter($store), 1, ['organisation_id' => 1, 'roles' => ['teacher']]))->create(1),
        );
        self::expectReason(
            TimetableCsvExportException::VERSION_UNAVAILABLE,
            static fn () => (new AdminTimetableCsvExport(new BlankTimetableCsvExporter($store), 2, ['organisation_id' => 2, 'roles' => ['administrator']]))->create(1),
        );

        $store->rooms = [['id' => 499, 'code' => 'OTHER-TENANT', 'organisation_id' => 2]];
        self::expectReason(TimetableCsvExportException::NO_ROOMS, static fn () => $action->create(1));
    }

    /** @return list<list<string>> */
    private static function rows(string $content): array
    {
        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) throw new \RuntimeException('Unable to create CSV test stream.');
        fwrite($stream, $content);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) $rows[] = array_map('strval', $row);
        fclose($stream);
        return $rows;
    }

    private static function expectReason(string $reason, callable $operation): void
    {
        try {
            $operation();
        } catch (TimetableCsvExportException $exception) {
            assertSameValue($reason, $exception->reason, 'CSV export failed for the wrong reason.');
            return;
        }
        throw new \RuntimeException('Invalid CSV export was accepted.');
    }
}
