<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\AdminTimetableCsvImport;
use Reqsheet\Http\AdminTimetableResourceCsvExport;
use Reqsheet\Http\CsrfToken;
use Reqsheet\Timetable\BlankTimetableCsvExporter;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\TimetableCsvExportException;
use Reqsheet\Timetable\TimetableCsvImportDraftStore;
use Reqsheet\Timetable\TimetableCsvImportException;
use Reqsheet\Timetable\TimetableCsvImportPreview;
use Reqsheet\Timetable\TimetableCsvImportPreviewService;
use Reqsheet\Timetable\TimetableCsvParser;
use Reqsheet\Timetable\TimetableResourceCsvExporter;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;

final class TimetableCsvImportTest
{
    public static function run(): void
    {
        self::validPreviewsAndGrouping();
        self::structuralValidation();
        self::resourceAndConflictValidation();
        self::parserLimitsAndMalformedInput();
        self::emptyTimetableAndTenantBoundaries();
        self::httpPreviewSecurity();
        self::draftLifetimeAndIsolation();
        self::resourceReferenceCsv();
    }

    private static function validPreviewsAndGrouping(): void
    {
        $store = self::store();
        $service = new TimetableCsvImportPreviewService($store);
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        $empty = $service->preview(1, 1, (new TimetableCsvParser())->parse($blank), true);
        assertSameValue(0, count($empty->assignments), 'Structurally complete empty CSV proposed lessons.');
        assertSameValue(8, $empty->freeSlots, 'Empty CSV free-slot count was incorrect.');

        $populated = self::fill($blank, [
            'Monday|P1|R1' => ['C1', 'AAA'], 'Monday|P2|R1' => ['C1', 'AAA'],
            'Monday|P3|R1' => ['C1', 'AAA'], 'Tuesday|P1|R2' => ['C2', 'BBB'],
        ]);
        $before = $store->lessons;
        $preview = $service->preview(1, 1, (new TimetableCsvParser())->parse($populated), true);
        assertSameValue($before, $store->lessons, 'Upload or preview wrote timetable lessons.');
        assertSameValue(3, count($preview->assignments), 'Separator-aware lesson grouping was incorrect.');
        assertSameValue(4, $preview->occupiedPeriods, 'Occupied-period count was incorrect.');
        assertSameValue(4, $preview->freeSlots, 'Free-slot count was incorrect.');
        assertSameValue(2, $preview->assignments[0]['duration'], 'Consecutive periods were not grouped.');
        assertSameValue(1, $preview->assignments[1]['duration'], 'Entries were grouped across Break.');

        $rows = (new TimetableCsvParser())->parse($populated);
        $reordered = "Day,Period,Room,Class,Teacher\r\n" . implode('', array_reverse(array_map(self::rowCsv(...), $rows)));
        $again = $service->preview(1, 1, (new TimetableCsvParser())->parse($reordered), true);
        assertSameValue($preview->occupiedPeriods, $again->occupiedPeriods, 'Reordered valid CSV changed the proposal.');
        self::expectError(fn () => $service->preview(1, 1, (new TimetableCsvParser())->parse($populated), false), 'conjoined periods are disabled');
    }

    private static function structuralValidation(): void
    {
        $store = self::store();
        $service = new TimetableCsvImportPreviewService($store);
        $parser = new TimetableCsvParser();
        $rows = self::csvRows((new BlankTimetableCsvExporter($store))->export(1, 1)->content);
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::writeRows(array_merge([$rows[0]], array_slice($rows, 2)))), true), 'Missing CSV slot');
        $duplicate = $rows; $duplicate[] = $rows[1];
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::writeRows($duplicate)), true), 'duplicates the slot');
        $extra = $rows; $extra[1][2] = 'UNKNOWN';
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::writeRows($extra)), true), 'Missing CSV slot');
    }

    private static function resourceAndConflictValidation(): void
    {
        $store = self::store();
        $service = new TimetableCsvImportPreviewService($store);
        $parser = new TimetableCsvParser();
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::fill($blank, ['Monday|P1|R1' => ['C1', 'ZZZ']])), true), 'following teachers do not exist');
        $newClassPreview = $service->preview(1, 1, $parser->parse(self::fill($blank, ['Monday|P1|R1' => ['NOPE', 'AAA']])), true);
        assertSameValue(['NOPE'], $newClassPreview->newClassCodes, 'Unknown class code was not proposed for creation.');
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::fill($blank, ['Monday|P1|R1' => ['C1', '']])), true), 'provide both Class and Teacher');
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::fill($blank, [
            'Monday|P1|R1' => ['C1', 'AAA'], 'Monday|P1|R2' => ['C2', 'AAA'],
        ])), true), 'Teacher AAA is assigned twice');
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::fill($blank, [
            'Monday|P1|R1' => ['C1', 'AAA'], 'Monday|P1|R2' => ['C1', 'BBB'],
        ])), true), 'Class C1 is assigned twice');

        $rows = self::csvRows($blank);
        $rows[] = ['Monday', 'P1', 'ROOM-35', 'IGNORED', 'NOT-A-TEACHER'];
        $skipped = $service->preview(1, 1, $parser->parse(self::writeRows($rows)), true);
        assertSameValue([['code' => 'ROOM-35', 'row_count' => 1]], $skipped->skippedRooms, 'Unknown room rows were not reported as skipped.');
        assertSameValue([], $skipped->newClassCodes, 'A discarded room row proposed a class.');
    }

    private static function parserLimitsAndMalformedInput(): void
    {
        $parser = new TimetableCsvParser();
        self::expectError(fn () => $parser->parse(''), 'empty');
        self::expectError(fn () => $parser->parse("Wrong,Period,Room,Class,Teacher\n"), 'must contain exactly');
        self::expectError(fn () => $parser->parse("Day,Period,Room,Class,Teacher\nMonday,P1,R1,\"broken,AAA"), 'unterminated');
        self::expectError(fn () => $parser->parse("Day,Period,Room,Class,Teacher\nMonday,P1,R1,\xC3\x28,AAA"), 'valid UTF-8');
        self::expectError(fn () => $parser->parse(str_repeat('x', TimetableCsvParser::MAX_BYTES + 1)), '2 MiB');
        self::expectError(fn () => $parser->parse("Day,Period,Room,Class,Teacher\n" . str_repeat("Monday,P1,R1,,\n", TimetableCsvParser::MAX_DATA_ROWS + 1)), '20,000');
        self::expectError(fn () => $parser->parseUpload([]), 'Choose a CSV');
        self::expectError(fn () => $parser->parseUpload(['error' => UPLOAD_ERR_OK, 'name' => 'table.txt', 'size' => 1, 'tmp_name' => '/unused']), '.csv extension');
    }

    private static function emptyTimetableAndTenantBoundaries(): void
    {
        $store = self::store();
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        $rows = (new TimetableCsvParser())->parse($blank);
        $service = new TimetableCsvImportPreviewService($store);
        $store->lessons[] = new RecurringLesson(99, 1, 10, 1, 101, 1, 'C1', 'R1', 501, 401);
        $populatedPreview = $service->preview(1, 1, $rows, true);
        assertSameValue(0, count($populatedPreview->assignments), 'A populated source timetable prevented a new import preview.');
        $store->lessons = [];
        self::expectError(fn () => $service->preview(2, 1, $rows, true), 'not available for this organisation');
        self::expectError(fn () => $service->preview(1, 1, (new TimetableCsvParser())->parse(self::fill($blank, ['Monday|P1|R1' => ['C1', 'XXX']])), true), 'following teachers do not exist');
    }

    private static function httpPreviewSecurity(): void
    {
        $store = self::store();
        $store->classes[] = ['id' => 503, 'code' => '<script>alert(1)</script>', 'organisation_id' => 1];
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        $file = self::upload(self::fill($blank, ['Monday|P1|R1' => ['<script>alert(1)</script>', 'AAA']]));
        $drafts = new TimetableCsvImportDraftStore(); $drafts->discard();
        $admin = ['id' => 80, 'organisation_id' => 1, 'roles' => ['administrator'], 'is_admin' => true];
        $action = new AdminTimetableCsvImport(new TimetableCsvParser(), new TimetableCsvImportPreviewService($store), $drafts, 1, $admin, true);
        $response = $action->handle(['version' => 1, 'csrf_token' => CsrfToken::value()], ['csv_file' => $file]);
        @unlink((string) $file['tmp_name']);
        assertSameValue(200, $response->status, 'Valid upload did not produce a preview.');
        assertSameValue(false, str_contains($response->html, '<script>alert(1)</script>'), 'Preview rendered raw uploaded HTML.');
        assertContainsValue('&lt;script&gt;alert(1)&lt;/script&gt;', $response->html, 'Escaped uploaded value was absent.');
        assertContainsValue('Nothing has been saved.', $response->html, 'Preview omitted its read-only status.');
        assertSameValue([], $store->lessons, 'HTTP preview wrote timetable data.');
        $invalidUpload = $action->handle(['version' => 1, 'csrf_token' => 'invalid'], []);
        assertSameValue(403, $invalidUpload->status, 'CSV upload accepted invalid CSRF.');
        assertSameValue(false, str_contains($invalidUpload->html, 'Import Timetable'), 'Invalid upload exposed a working import confirmation action.');
        $nonAdmin = new AdminTimetableCsvImport(new TimetableCsvParser(), new TimetableCsvImportPreviewService($store), $drafts, 1, ['id' => 81, 'roles' => ['teacher']], true);
        assertSameValue(403, $nonAdmin->handle([], [])->status, 'CSV upload accepted an unauthorised user.');
    }

    private static function draftLifetimeAndIsolation(): void
    {
        $drafts = new TimetableCsvImportDraftStore(); $drafts->discard();
        $preview = new TimetableCsvImportPreview(1, 1, 'Autumn', 1, 7, [], 'digest');
        $first = $drafts->save($preview, 80, 1000);
        assertSameValue(null, $drafts->load((string) $first['id'], 2, 80, 1001), 'Another organisation reused a draft.');
        assertSameValue(null, $drafts->load((string) $first['id'], 1, 81, 1001), 'Another user reused a draft.');
        assertSameValue(1, $drafts->load((string) $first['id'], 1, 80, 1001)['version_id'] ?? null, 'Owner could not load a current draft.');
        $second = $drafts->save($preview, 80, 1002);
        assertSameValue(null, $drafts->load((string) $first['id'], 1, 80, 1003), 'Superseded draft remained reusable.');
        assertSameValue(null, $drafts->load((string) $second['id'], 1, 80, 1902), 'Expired draft remained available.');
    }

    private static function resourceReferenceCsv(): void
    {
        $store = self::store();
        $export = (new AdminTimetableResourceCsvExport(new TimetableResourceCsvExporter($store), 1, ['roles' => ['administrator'], 'is_admin' => true]))->create();
        $rows = self::csvRows($export->content);
        assertSameValue(['Resource Type', 'Code', 'Name'], $rows[0], 'Resource reference headers changed.');
        foreach ([['Teacher', 'AAA', 'AAA'], ['Class', 'C1', 'C1'], ['Room', 'R1', 'R1']] as $value) {
            assertSameValue(true, in_array($value, $rows, true), 'Resource reference omitted ' . implode('/', $value));
        }
        foreach (['XXX', 'OTHER'] as $value) assertSameValue(false, str_contains($export->content, $value), 'Resource reference exposed cross-tenant data.');
        try {
            (new AdminTimetableResourceCsvExport(new TimetableResourceCsvExporter($store), 1, ['roles' => ['teacher']]))->create();
        } catch (TimetableCsvExportException $exception) {
            assertSameValue(TimetableCsvExportException::UNAUTHORISED, $exception->reason, 'Unauthorised reference export failed incorrectly.');
            return;
        }
        throw new \RuntimeException('Resource reference accepted unauthorised access.');
    }

    private static function store(): ResourceConfigurationStore
    {
        $store = new ResourceConfigurationStore();
        $store->versions[1] = new TimetableVersion(1, 1, 'Autumn', new DateTimeImmutable('2026-09-01'), null, 1);
        $store->versions[2] = new TimetableVersion(2, 2, 'Other', new DateTimeImmutable('2026-09-01'), null, 1);
        $store->slots = [new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'P1'), new TimetableSlot(102, 1, 1, 2, 'teaching', 2, 'P2'), new TimetableSlot(103, 1, 1, 3, 'break', null, 'Break'), new TimetableSlot(104, 1, 1, 4, 'teaching', 3, 'P3'), new TimetableSlot(201, 1, 2, 1, 'teaching', 1, 'P1')];
        $store->rooms = [['id' => 401, 'code' => 'R1', 'organisation_id' => 1], ['id' => 402, 'code' => 'R2', 'organisation_id' => 1], ['id' => 499, 'code' => 'OTHER', 'organisation_id' => 2]];
        $store->classes = [['id' => 501, 'code' => 'C1', 'organisation_id' => 1], ['id' => 502, 'code' => 'C2', 'organisation_id' => 1], ['id' => 599, 'code' => 'OTHER', 'organisation_id' => 2]];
        $store->users = [['id' => 10, 'staff_identifier' => 'AAA', 'is_active' => true, 'organisation_id' => 1], ['id' => 11, 'staff_identifier' => 'BBB', 'is_active' => true, 'organisation_id' => 1], ['id' => 20, 'staff_identifier' => 'XXX', 'is_active' => true, 'organisation_id' => 2]];
        return $store;
    }

    /** @param array<string, array{0:string,1:string}> $values */
    private static function fill(string $csv, array $values): string
    {
        $rows = self::csvRows($csv);
        foreach (array_slice($rows, 1) as $offset => $row) {
            $key = $row[0] . '|' . $row[1] . '|' . $row[2];
            if (isset($values[$key])) { $rows[$offset + 1][3] = $values[$key][0]; $rows[$offset + 1][4] = $values[$key][1]; }
        }
        return self::writeRows($rows);
    }

    /** @return list<list<string>> */
    private static function csvRows(string $content): array
    {
        $stream = fopen('php://temp', 'w+b'); fwrite($stream, $content); rewind($stream); $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) $rows[] = array_map('strval', $row);
        fclose($stream); return $rows;
    }

    /** @param list<list<string>> $rows */
    private static function writeRows(array $rows): string
    {
        $stream = fopen('php://temp', 'w+b'); foreach ($rows as $row) fputcsv($stream, $row, ',', '"', '', "\r\n");
        rewind($stream); $content = stream_get_contents($stream); fclose($stream); return (string) $content;
    }

    /** @param array{row:int,day:string,period:string,room:string,class:string,teacher:string} $row */
    private static function rowCsv(array $row): string { return self::writeRows([[$row['day'], $row['period'], $row['room'], $row['class'], $row['teacher']]]); }

    /** @return array<string, mixed> */
    private static function upload(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'reqsheet-csv-');
        if ($path === false) throw new \RuntimeException('Unable to create temporary test file.');
        file_put_contents($path, $content);
        return ['error' => UPLOAD_ERR_OK, 'name' => 'timetable.csv', 'size' => strlen($content), 'tmp_name' => $path, 'type' => 'text/csv'];
    }

    private static function expectError(callable $operation, string $fragment): void
    {
        try { $operation(); } catch (TimetableCsvImportException $exception) {
            $message = implode(' ', $exception->errors());
            if (!str_contains($message, $fragment)) throw new \RuntimeException('Expected CSV error containing "' . $fragment . '", got: ' . $message);
            return;
        }
        throw new \RuntimeException('Invalid timetable CSV was accepted; expected: ' . $fragment);
    }
}
