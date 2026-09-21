<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Account\AccountService;
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
        self::distinctNewClassesAreIndependent();
        self::structuralValidation();
        self::resourceAndConflictValidation();
        self::parserLimitsAndMalformedInput();
        self::emptyTimetableAndTenantBoundaries();
        self::httpPreviewSecurity();
        self::inlineResourceResolution();
        self::archivedRoomResolution();
        self::draftLifetimeAndIsolation();
        self::resourceReferenceCsv();
    }

    private static function distinctNewClassesAreIndependent(): void
    {
        $store = self::store();
        $content = file_get_contents(__DIR__ . '/fixtures/timetable-distinct-new-classes.csv');
        if ($content === false) throw new \RuntimeException('Unable to read the distinct-new-classes regression fixture.');

        $preview = (new TimetableCsvImportPreviewService($store))->preview(
            1,
            1,
            (new TimetableCsvParser())->parse($content),
            true,
        );

        assertSameValue(8, $preview->occupiedPeriods, 'Valid simultaneous lessons with distinct new classes were rejected.');
        assertSameValue(7, count($preview->assignments), 'New-class grouping changed the lesson proposal.');
        assertSameValue(['NEW-A', 'NEW-B', 'NEW-C', 'NEW-D'], $preview->newClassCodes, 'Distinct missing classes were not proposed independently.');
        assertSameValue(2, $preview->assignments[0]['duration'], 'A valid new-class multi-period lesson was not grouped.');
        assertSameValue(1, $preview->assignments[1]['duration'], 'A repeated class was grouped across a separator.');
        assertSameValue(['NEW-B', 'NEW-D'], [$preview->assignments[2]['class_code'], $preview->assignments[3]['class_code']], 'Consecutive distinct new classes taught by one teacher were merged.');
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
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::fill($blank, [
            'Monday|P1|R1' => ['NEW-A', 'AAA'], 'Monday|P1|R2' => ['NEW-A', 'BBB'],
        ])), true), 'Class NEW-A is assigned twice');
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::fill($blank, [
            'Monday|P1|R1' => ['C1', 'AAA'], 'Monday|P2|R1' => ['C1', 'AAA'],
            'Monday|P2|R2' => ['C1', 'BBB'],
        ])), true), 'Class C1 is assigned twice');
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::fill($blank, [
            'Monday|P1|R1' => ['C1', 'AAA'], 'Monday|P2|R1' => ['C1', 'AAA'],
            'Monday|P2|R2' => ['C2', 'AAA'],
        ])), true), 'Teacher AAA is assigned twice');

        $rows = self::csvRows($blank);
        $rows[] = ['Monday', 'P1', 'ROOM-35', 'IGNORED', 'NOT-A-TEACHER'];
        self::expectError(fn () => $service->preview(1, 1, $parser->parse(self::writeRows($rows)), true), 'Room ROOM-35 does not exist');
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

    private static function inlineResourceResolution(): void
    {
        $store = self::store();
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        $rows = self::csvRows($blank);
        foreach ($rows as $row) {
            if (($row[0] ?? '') === 'Day' || ($row[1] ?? '') === 'Break' || ($row[2] ?? '') !== 'R1') continue;
            $rows[] = [$row[0], $row[1], 'R3', $row[0] === 'Monday' && $row[1] === 'P1' ? 'C1' : '', $row[0] === 'Monday' && $row[1] === 'P1' ? 'AAA' : ''];
        }
        $drafts = new TimetableCsvImportDraftStore(); $drafts->discard();
        $admin = ['id' => 80, 'organisation_id' => 1, 'roles' => ['administrator'], 'is_admin' => true];
        $action = new AdminTimetableCsvImport(new TimetableCsvParser(), new TimetableCsvImportPreviewService($store), $drafts, 1, $admin, true, $store);
        $csv = self::writeRows($rows);
        $response = $action->handle(['version' => 1, 'csrf_token' => CsrfToken::value()], ['csv_file' => self::upload($csv)]);
        assertSameValue(422, $response->status, 'A missing room did not block validation.');
        assertContainsValue('retained for 15 minutes', $response->html, 'The failed validation did not retain the CSV.');
        assertContainsValue('Validate Again', $response->html, 'Failed validation omitted Validate Again.');
        assertSameValue(1, substr_count($response->html, 'action="/admin/timetable/import" data-resource-form'), 'The room action form was not rendered once.');
        assertContainsValue('fetch(endpoint', $response->html, 'Inline resource creation did not use the explicit endpoint variable.');
        assertContainsValue('endpoint=form.getAttribute("action")', $response->html, 'Inline resource creation did not read the form action attribute explicitly.');
        assertSameValue(false, str_contains($response->html, 'fetch(form.action'), 'Inline resource creation retained the ambiguous form.action lookup.');
        assertSameValue(false, str_contains($response->html, '[object HTMLInputElement]'), 'Inline resource markup exposed an element object as an endpoint.');
        $draft = $_SESSION['timetable_csv_import_draft'] ?? [];
        assertSameValue($csv, $draft['csv_content'] ?? null, 'The exact uploaded CSV was not retained.');
        $created = $action->handle([
            'version' => 1, 'csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'] ?? '',
            'action' => 'create_missing_resource', 'resource_type' => 'room', 'code' => 'R3',
        ], []);
        assertSameValue(200, $created->status, 'Inline room creation did not return success.');
        assertSameValue(true, $created->json, 'Inline room creation did not return JSON.');
        assertContainsValue('"success":true', $created->html, 'Inline room creation response was not successful.');
        $duplicate = $action->handle([
            'version' => 1, 'csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'] ?? '',
            'action' => 'create_missing_resource', 'resource_type' => 'room', 'code' => 'R3',
        ], []);
        assertSameValue(200, $duplicate->status, 'Concurrent/idempotent room creation was not treated as success.');
        $again = $action->handle(['version' => 1, 'csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'] ?? '', 'action' => 'validate_again'], []);
        assertSameValue(200, $again->status, 'Validate Again did not reuse the retained CSV.');
        assertContainsValue('Import Timetable', $again->html, 'Revalidation did not reach the existing confirmation flow.');
        assertSameValue(0, count($store->lessons), 'Resource resolution partially imported lessons.');

        $failed = $action->handle([
            'version' => 1, 'csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'] ?? '',
            'action' => 'create_missing_resource', 'resource_type' => 'room', 'code' => 'NOT-IN-CSV',
        ], []);
        assertSameValue(422, $failed->status, 'An unrelated inline resource was accepted.');
        assertSameValue(false, json_decode($failed->html, true, 512, JSON_THROW_ON_ERROR)['success'] ?? true, 'A failed inline resource request returned false success state.');

        $teacherStore = self::store();
        $teacherAccountsStore = new \Reqsheet\Tests\AccountStoreFake();
        $teacherAccountsStore->createOrganisationAdmin('Test School', 'ADM', 'teacher', password_hash('admin-pass', PASSWORD_DEFAULT), 'test-school');
        $teacherAction = new AdminTimetableCsvImport(new TimetableCsvParser(), new TimetableCsvImportPreviewService($teacherStore), new TimetableCsvImportDraftStore(), 1, $admin, true, $teacherStore, new AccountService($teacherAccountsStore));
        $teacherRows = self::csvRows($blank);
        $teacherRows[1][3] = 'C1'; $teacherRows[1][4] = 'ANO';
        $teacherCsv = self::writeRows($teacherRows);
        $teacherPreview = $teacherAction->handle(['version' => 1, 'csrf_token' => CsrfToken::value()], ['csv_file' => self::upload($teacherCsv)]);
        assertSameValue(422, $teacherPreview->status, 'A missing teacher did not block validation.');
        assertSameValue(1, substr_count($teacherPreview->html, 'action="/admin/timetable/import" data-resource-form'), 'The teacher action form was not rendered once.');
        assertContainsValue('✓ Added', $teacherPreview->html, 'The inline success confirmation text was not retained.');
        assertContainsValue('button.replaceWith(done)', $teacherPreview->html, 'The inline success confirmation did not replace the action button.');
        $teacherDraft = $_SESSION['timetable_csv_import_draft'] ?? [];
        $teacherCreated = $teacherAction->handle([
            'version' => 1, 'csrf_token' => CsrfToken::value(), 'draft_id' => $teacherDraft['id'] ?? '',
            'action' => 'create_missing_resource', 'resource_type' => 'teacher', 'code' => 'ANO',
        ], []);
        assertSameValue(200, $teacherCreated->status, 'Inline teacher creation did not return success.');
        assertSameValue(true, json_decode($teacherCreated->html, true, 512, JSON_THROW_ON_ERROR)['success'] ?? false, 'Inline teacher creation returned false success state.');
        $teacherAccount = $teacherAccountsStore->findLogin('ANO', 1);
        assertSameValue(['teacher'], $teacherAccount['roles'] ?? null, 'Inline teacher creation did not create a Teacher-only account.');
        assertSameValue('awaiting_first_login', $teacherAccount['account_state'] ?? null, 'Inline teacher creation did not preserve the first-login lifecycle.');
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

    private static function archivedRoomResolution(): void
    {
        $store = self::store();
        $store->rooms[] = ['id' => 403, 'code' => 'R3', 'organisation_id' => 1, 'archived' => true];
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        $rows = self::csvRows($blank);
        $originalRows = $rows; $addedSlots = [];
        foreach ($originalRows as $row) if (($row[0] ?? '') !== 'Day' && !isset($addedSlots[$row[0] . '|' . $row[1]])) { $rows[] = [$row[0], $row[1], 'R3', '', '']; $addedSlots[$row[0] . '|' . $row[1]] = true; }
        $csv = self::writeRows($rows);
        $drafts = new TimetableCsvImportDraftStore(); $drafts->discard();
        $admin = ['id' => 80, 'organisation_id' => 1, 'roles' => ['administrator'], 'is_admin' => true];
        $action = new AdminTimetableCsvImport(new TimetableCsvParser(), new TimetableCsvImportPreviewService($store), $drafts, 1, $admin, true, $store);
        $response = $action->handle(['version' => 1, 'csrf_token' => CsrfToken::value()], ['csv_file' => self::upload($csv)]);
        assertSameValue(422, $response->status, 'Archived room did not block CSV validation.');
        assertContainsValue('Room R3 is archived.', $response->html, 'Archived room did not have a distinct validation finding.');
        assertContainsValue('Restore Room R3', $response->html, 'Archived room did not expose inline restoration.');
        $draft = $_SESSION['timetable_csv_import_draft'] ?? [];
        assertSameValue(['R3'], $draft['archived_rooms'] ?? null, 'Archived room was not retained in the validation draft.');
        $restored = $action->handle(['version' => 1, 'csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'] ?? '', 'action' => 'create_missing_resource', 'resource_type' => 'room', 'operation' => 'restore', 'code' => 'R3'], []);
        assertSameValue(200, $restored->status, 'Inline archived-room restoration failed.');
        $payload = json_decode($restored->html, true, 512, JSON_THROW_ON_ERROR);
        assertSameValue(true, $payload['success'] ?? false, 'Inline archived-room restoration returned failure.');
        assertSameValue('✓ Restored', $payload['confirmation'] ?? null, 'Inline restoration returned the wrong confirmation.');
        $restoredRoom = array_values(array_filter($store->rooms, static fn (array $room): bool => (int) $room['id'] === 403))[0] ?? [];
        assertSameValue(false, $restoredRoom['archived'] ?? true, 'Inline restoration did not reactivate the original room.');
        assertSameValue(403, $restoredRoom['id'] ?? 0, 'Inline restoration did not preserve the room ID.');
        $again = $action->handle(['version' => 1, 'csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'] ?? '', 'action' => 'validate_again'], []);
        assertSameValue(200, $again->status, 'Validate Again did not reuse the retained CSV after restoration: ' . strip_tags($again->html));
        assertContainsValue('Import Timetable', $again->html, 'Restored room was not recognised by revalidation.');
        assertSameValue(1, count(array_filter($store->rooms, static fn (array $room): bool => $room['code'] === 'R3')), 'Restoration created a duplicate room.');
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
