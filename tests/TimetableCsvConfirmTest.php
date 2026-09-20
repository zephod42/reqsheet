<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use DateTimeImmutable;
use Reqsheet\Http\AdminTimetableCsvConfirm;
use Reqsheet\Http\AdminTimetableCsvImport;
use Reqsheet\Http\AdminTimetablePage;
use Reqsheet\Http\CsrfToken;
use Reqsheet\Timetable\BlankTimetableCsvExporter;
use Reqsheet\Timetable\RecurringLesson;
use Reqsheet\Timetable\TimetableCsvImportDraftStore;
use Reqsheet\Timetable\TimetableCsvImportException;
use Reqsheet\Timetable\TimetableCsvImportPreview;
use Reqsheet\Timetable\TimetableCsvImportPreviewService;
use Reqsheet\Timetable\TimetableCsvImportService;
use Reqsheet\Timetable\TimetableCsvImportStore;
use Reqsheet\Timetable\TimetableCsvImportTechnicalException;
use Reqsheet\Timetable\TimetableCsvParser;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;

final class TimetableCsvConfirmTest
{
    public static function run(): void
    {
        self::successfulAtomicImport();
        self::distinctNewClassesSurviveRevalidation();
        self::stateChangesAreRejected();
        self::draftAndRequestProtection();
        self::rollbackOnInsertFailure();
    }

    private static function distinctNewClassesSurviveRevalidation(): void
    {
        $store = self::store();
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        $rows = (new TimetableCsvParser())->parse(self::fill($blank, [
            'Monday|P1|R1' => ['NEW-A', 'AAA'],
            'Monday|P1|R2' => ['NEW-B', 'BBB'],
        ]));
        $preview = (new TimetableCsvImportPreviewService($store))->preview(1, 1, $rows, true);
        $result = self::service($store)->import(1, [
            'version_id' => 1,
            'proposed_version_name' => $preview->proposedVersionName,
            'assignments' => $preview->assignments,
            'occupied_periods' => $preview->occupiedPeriods,
            'free_slots' => $preview->freeSlots,
            'structure_identity' => $preview->structureIdentity,
        ]);

        assertSameValue(2, $result->lessonCount, 'Transaction-time revalidation rejected valid simultaneous new classes.');
        assertSameValue(['NEW-A', 'NEW-B'], $result->createdClassCodes, 'Confirmed import did not create distinct missing classes.');
        $imported = array_values(array_filter($store->lessons, static fn (RecurringLesson $lesson): bool => $lesson->timetableVersionId === $result->versionId));
        assertSameValue(2, count($imported), 'Confirmed import did not retain both simultaneous lessons.');
        assertSameValue(false, $imported[0]->classId === $imported[1]->classId, 'Confirmed import collapsed distinct new classes into one resource.');
    }

    private static function successfulAtomicImport(): void
    {
        $store = self::store();
        $store->active[1] = 2;
        $store->occurrences = [900 => 4];
        $admin = self::admin();
        $drafts = new TimetableCsvImportDraftStore(); $drafts->discard();
        $uploadPage = new AdminTimetableCsvImport(new TimetableCsvParser(), new TimetableCsvImportPreviewService($store), $drafts, 1, $admin, true);
        $previewHtml = self::previewHtml($uploadPage, $store);
        assertContainsValue('Import Timetable', $previewHtml, 'Validated preview omitted the confirmation action.');
        assertContainsValue('activates it immediately', $previewHtml, 'Preview omitted the automatic activation boundary.');
        $draft = $drafts->save(self::preview($store), 80);

        $confirmation = self::confirmation($store, $drafts, $admin, 1);
        $response = $confirmation->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import']);
        assertSameValue(303, $response->status, 'Successful import did not use a post/redirect/get response.');
        assertSameValue('/admin/timetable?version=3&csv_imported=1', $response->location, 'Successful import returned to the wrong timetable.');
        assertSameValue(3, count($store->lessons), 'Imported lesson count was incorrect.');
        assertSameValue([2, 1, 1], array_map(static fn (RecurringLesson $lesson): int => $lesson->durationPeriods, $store->lessons), 'Single/multi-period grouping changed during import.');
        assertSameValue([10, 10, 11], array_map(static fn (RecurringLesson $lesson): int => $lesson->teacherUserId, $store->lessons), 'Imported teacher relationships were incorrect.');
        assertSameValue([501, 501, 502], array_map(static fn (RecurringLesson $lesson): ?int => $lesson->classId, $store->lessons), 'Imported class relationships were incorrect.');
        assertSameValue([401, 401, 402], array_map(static fn (RecurringLesson $lesson): ?int => $lesson->roomId, $store->lessons), 'Imported room relationships were incorrect.');
        assertSameValue(3, $store->active[1], 'CSV import did not activate the newly created timetable.');
        assertSameValue('Import target_imported_', substr((string) $store->versions[3]->label, 0, 23), 'Imported timetable did not receive an automatic name.');
        assertSameValue([900 => 4], $store->occurrences, 'CSV import changed historical occurrence state.');

        $page = (new AdminTimetablePage($store, 1, ['allow_double_periods' => true], $admin))->handle('GET', ['version' => 3, 'view' => 'teacher', 'resource' => 10, 'csv_imported' => '1'], []);
        assertContainsValue('Timetable imported successfully.', $page, 'Builder omitted the successful import message.');
        assertContainsValue('rowspan="2"', $page, 'Imported multi-period lesson was absent from the teacher projection.');
        $room = (new AdminTimetablePage($store, 1, ['allow_double_periods' => true], $admin))->handle('GET', ['version' => 3, 'view' => 'room', 'resource' => 401], []);
        assertContainsValue('C1', $room, 'Imported lesson was absent from the room projection.');
        $class = (new AdminTimetablePage($store, 1, ['allow_double_periods' => true], $admin))->handle('GET', ['version' => 3, 'view' => 'class', 'resource' => 502], []);
        assertContainsValue('Teacher BBB', $class, 'Imported lesson was absent from the class projection.');

        $repeat = $confirmation->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import']);
        assertSameValue(422, $repeat->status, 'Consumed draft was accepted a second time.');
        assertSameValue(3, count($store->lessons), 'Repeated confirmation duplicated imported lessons.');
    }

    private static function stateChangesAreRejected(): void
    {
        $populated = self::store();
        [$drafts, $draft] = self::draft($populated);
        $populated->insertResourceLesson(1, 11, 2, 201, 1, 502, 402);
        $before = $populated->lessons;
        $response = self::confirmation($populated, $drafts)->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import']);
        assertSameValue(303, $response->status, 'A populated source timetable prevented a new import.');
        assertSameValue(4, count($populated->lessons), 'Import into a new timetable did not retain the source lesson and add the proposed lessons.');
        assertSameValue($before[0], $populated->lessons[0], 'Import changed the populated source timetable.');

        foreach (['teacher', 'room', 'structure', 'setting'] as $change) {
            $store = self::store();
            [$drafts, $draft] = self::draft($store);
            if ($change === 'teacher') $store->users[0]['staff_identifier'] = 'NEW';
            if ($change === 'room') $store->rooms[0]['code'] = 'CHANGED';
            if ($change === 'structure') $store->slots[0] = new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'Changed P1');
            if ($change === 'setting') $store->allowConjoinedPeriods = false;
            $response = self::confirmation($store, $drafts)->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import']);
            assertSameValue(422, $response->status, ucfirst($change) . ' change after preview was accepted.');
            assertSameValue([], $store->lessons, ucfirst($change) . ' change rejection left imported lessons.');
        }
    }

    private static function draftAndRequestProtection(): void
    {
        $store = self::store();
        [$drafts, $draft] = self::draft($store);
        $confirmation = self::confirmation($store, $drafts);
        assertSameValue(403, $confirmation->handle(['draft_id' => $draft['id'], 'action' => 'import'])->status, 'Missing CSRF token was accepted.');
        assertSameValue(403, $confirmation->handle(['csrf_token' => 'invalid', 'draft_id' => $draft['id'], 'action' => 'import'])->status, 'Invalid CSRF token was accepted.');
        assertSameValue([], $store->lessons, 'Invalid CSRF request imported lessons.');
        $teacher = self::confirmation($store, $drafts, ['id' => 82, 'organisation_id' => 1, 'roles' => ['teacher'], 'is_admin' => false]);
        assertSameValue(403, $teacher->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import'])->status, 'Non-administrator confirmed an import.');

        $otherAdmin = self::confirmation($store, $drafts, ['id' => 81, 'organisation_id' => 1, 'roles' => ['administrator'], 'is_admin' => true]);
        assertSameValue(422, $otherAdmin->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import'])->status, 'Another administrator reused the draft.');
        $otherTenant = self::confirmation($store, $drafts, ['id' => 80, 'organisation_id' => 2, 'roles' => ['administrator'], 'is_admin' => true], 2);
        assertSameValue(422, $otherTenant->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import'])->status, 'Another tenant reused the draft.');

        $drafts->discard();
        assertSameValue(422, $confirmation->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'import'])->status, 'Missing draft was accepted.');
        $preview = self::preview($store);
        $expired = $drafts->save($preview, 80, 1000);
        assertSameValue(422, $confirmation->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $expired['id'], 'action' => 'import'])->status, 'Expired draft was accepted.');

        $store = self::store();
        [$drafts, $draft] = self::draft($store);
        $cancel = self::confirmation($store, $drafts)->handle(['csrf_token' => CsrfToken::value(), 'draft_id' => $draft['id'], 'action' => 'cancel']);
        assertSameValue(303, $cancel->status, 'Cancel did not return to the timetable builder.');
        assertSameValue(null, $drafts->load($draft['id'], 1, 80), 'Cancelled draft remained available.');
        assertSameValue([], $store->lessons, 'Cancelling a preview imported lessons.');
    }

    private static function rollbackOnInsertFailure(): void
    {
        $store = self::store();
        $store->failAfterInsert = 1;
        $draft = self::preview($store);
        $service = self::service($store);
        try {
            $service->import(1, [
                'version_id' => 1, 'assignments' => $draft->assignments,
                'occupied_periods' => $draft->occupiedPeriods, 'free_slots' => $draft->freeSlots,
                'structure_identity' => $draft->structureIdentity,
            ]);
        } catch (TimetableCsvImportTechnicalException) {
            assertSameValue([], $store->lessons, 'Partway insertion failure was not rolled back.');
            assertSameValue(1, $store->rollbacks, 'Partway insertion failure did not roll back exactly once.');
            return;
        }
        throw new \RuntimeException('Simulated partway database failure did not fail the import.');
    }

    /** @return array{TimetableCsvImportDraftStore,array<string,mixed>} */
    private static function draft(AtomicResourceConfigurationStore $store): array
    {
        $drafts = new TimetableCsvImportDraftStore(); $drafts->discard();
        return [$drafts, $drafts->save(self::preview($store), 80)];
    }

    private static function preview(AtomicResourceConfigurationStore $store): TimetableCsvImportPreview
    {
        $blank = (new BlankTimetableCsvExporter($store))->export(1, 1)->content;
        $rows = (new TimetableCsvParser())->parse(self::fill($blank, [
            'Monday|P1|R1' => ['C1', 'AAA'], 'Monday|P2|R1' => ['C1', 'AAA'],
            'Monday|P3|R1' => ['C1', 'AAA'], 'Tuesday|P1|R2' => ['C2', 'BBB'],
        ]));
        return (new TimetableCsvImportPreviewService($store))->preview(1, 1, $rows, true);
    }

    private static function confirmation(AtomicResourceConfigurationStore $store, TimetableCsvImportDraftStore $drafts, ?array $admin = null, int $organisationId = 1): AdminTimetableCsvConfirm
    {
        return new AdminTimetableCsvConfirm(self::service($store), $drafts, $organisationId, $admin ?? self::admin());
    }

    private static function service(AtomicResourceConfigurationStore $store): TimetableCsvImportService
    {
        return new TimetableCsvImportService($store, new BlankTimetableCsvExporter($store), new TimetableCsvParser(), new TimetableCsvImportPreviewService($store));
    }

    /** @return array<string,mixed> */
    private static function admin(): array { return ['id' => 80, 'organisation_id' => 1, 'roles' => ['administrator'], 'is_admin' => true]; }

    private static function store(): AtomicResourceConfigurationStore
    {
        $store = new AtomicResourceConfigurationStore();
        $store->versions[1] = new TimetableVersion(1, 1, 'Import target', new DateTimeImmutable('2026-09-01'), null, 1);
        $store->versions[2] = new TimetableVersion(2, 1, 'Active timetable', new DateTimeImmutable('2026-01-01'), null, 1);
        $store->slots = [new TimetableSlot(101, 1, 1, 1, 'teaching', 1, 'P1'), new TimetableSlot(102, 1, 1, 2, 'teaching', 2, 'P2'), new TimetableSlot(103, 1, 1, 3, 'break', null, 'Break'), new TimetableSlot(104, 1, 1, 4, 'teaching', 3, 'P3'), new TimetableSlot(201, 1, 2, 1, 'teaching', 1, 'P1')];
        $store->rooms = [['id' => 401, 'code' => 'R1', 'organisation_id' => 1], ['id' => 402, 'code' => 'R2', 'organisation_id' => 1]];
        $store->classes = [['id' => 501, 'code' => 'C1', 'organisation_id' => 1], ['id' => 502, 'code' => 'C2', 'organisation_id' => 1]];
        $store->users = [['id' => 10, 'staff_identifier' => 'AAA', 'is_active' => true, 'organisation_id' => 1], ['id' => 11, 'staff_identifier' => 'BBB', 'is_active' => true, 'organisation_id' => 1]];
        return $store;
    }

    private static function previewHtml(AdminTimetableCsvImport $page, AtomicResourceConfigurationStore $store): string
    {
        $content = self::fill((new BlankTimetableCsvExporter($store))->export(1, 1)->content, ['Monday|P1|R1' => ['C1', 'AAA']]);
        $path = tempnam(sys_get_temp_dir(), 'reqsheet-confirm-');
        file_put_contents((string) $path, $content);
        $response = $page->handle(['version' => 1, 'csrf_token' => CsrfToken::value()], ['csv_file' => ['error' => UPLOAD_ERR_OK, 'name' => 'timetable.csv', 'size' => strlen($content), 'tmp_name' => $path]]);
        @unlink((string) $path);
        return $response->html;
    }

    /** @param array<string,array{0:string,1:string}> $values */
    private static function fill(string $csv, array $values): string
    {
        $input = fopen('php://temp', 'w+b'); fwrite($input, $csv); rewind($input);
        $output = fopen('php://temp', 'w+b');
        while (($row = fgetcsv($input, null, ',', '"', '')) !== false) {
            if ($row[0] !== 'Day') { $key = $row[0] . '|' . $row[1] . '|' . $row[2]; if (isset($values[$key])) { $row[3] = $values[$key][0]; $row[4] = $values[$key][1]; } }
            fputcsv($output, $row, ',', '"', '', "\r\n");
        }
        rewind($output); $content = stream_get_contents($output); fclose($input); fclose($output); return (string) $content;
    }
}

final class AtomicResourceConfigurationStore extends ResourceConfigurationStore implements TimetableCsvImportStore
{
    public bool $allowConjoinedPeriods = true;
    public ?int $failAfterInsert = null;
    public int $rollbacks = 0;
    private bool $transaction = false;
    /** @var list<RecurringLesson> */
    private array $snapshot = [];
    private int $insertions = 0;

    public function beginCsvImport(int $organisationId, int $versionId): bool
    {
        if ($this->transaction) throw new \RuntimeException('Concurrent fake import attempted.');
        $version = $this->findVersion($versionId);
        if ($version === null || $version->organisationId !== $organisationId) throw new TimetableCsvImportException(['The selected timetable is not available for this organisation.']);
        $this->transaction = true; $this->snapshot = $this->lessons; $this->insertions = 0;
        return $this->allowConjoinedPeriods;
    }

    public function createCsvImportVersion(int $organisationId, int $sourceVersionId, string $requestedLabel): array
    {
        $source = $this->versions[$sourceVersionId] ?? null;
        if ($source === null || $source->organisationId !== $organisationId) throw new TimetableCsvImportException(['The source timetable is not available for this organisation.']);
        $label = $requestedLabel; $suffix = 1;
        while (in_array($label, array_map(static fn (TimetableVersion $version): string => (string) $version->label, $this->versions), true)) $label = $requestedLabel . '_' . (++$suffix);
        $id = max(array_keys($this->versions)) + 1;
        $this->versions[$id] = new TimetableVersion($id, $organisationId, $label, new DateTimeImmutable('1000-01-01'), null, $source->firstDayOfWeek);
        $map = [];
        foreach ($this->slotsForVersion($sourceVersionId) as $slot) {
            $slotId = max([0, ...array_map(static fn (TimetableSlot $item): int => $item->id, $this->slots)]) + 1;
            $this->slots[] = new TimetableSlot($slotId, $id, $slot->dayOfWeek, $slot->sequenceNumber, $slot->kind, $slot->teachingPeriodNumber, $slot->label, $slot->startsAt, $slot->endsAt);
            $map[$slot->id] = $slotId;
        }
        return ['id' => $id, 'label' => $label, 'slot_map' => $map];
    }

    public function ensureCsvImportClass(int $organisationId, string $code): array
    {
        foreach ($this->classes as $class) if ((int) ($class['organisation_id'] ?? $organisationId) === $organisationId && (string) $class['code'] === $code) return ['id' => (int) $class['id'], 'created' => false];
        $id = max([500, ...array_map(static fn (array $class): int => (int) $class['id'], $this->classes)]) + 1;
        $this->classes[] = ['id' => $id, 'code' => $code, 'organisation_id' => $organisationId];
        return ['id' => $id, 'created' => true];
    }

    public function activateCsvImportVersion(int $organisationId, int $versionId): void { $this->active[$organisationId] = $versionId; }

    public function insertCsvImportLesson(int $organisationId, int $versionId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, int $classId, int $roomId): int
    {
        if (!$this->transaction) throw new \RuntimeException('No fake import transaction.');
        $this->insertions++;
        if ($this->failAfterInsert !== null && $this->insertions > $this->failAfterInsert) throw new \RuntimeException('Simulated insert failure.');
        return $this->insertResourceLesson($versionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, $classId, $roomId);
    }

    public function commitCsvImport(): void { if (!$this->transaction) throw new \RuntimeException('No fake import transaction.'); $this->transaction = false; $this->snapshot = []; }
    public function rollbackCsvImport(): void { if (!$this->transaction) return; $this->lessons = $this->snapshot; $this->transaction = false; $this->snapshot = []; $this->rollbacks++; }
}
