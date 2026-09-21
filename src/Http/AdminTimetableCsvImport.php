<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Timetable\TimetableCsvImportDraftStore;
use Reqsheet\Timetable\TimetableCsvImportException;
use Reqsheet\Timetable\TimetableCsvImportPreview;
use Reqsheet\Timetable\TimetableCsvImportPreviewService;
use Reqsheet\Timetable\TimetableCsvParser;
use Reqsheet\Timetable\ResourceTimetableStore;
use Reqsheet\Timetable\TimetableResourceService;

final class AdminTimetableCsvImport
{
    /** @param array<string, mixed> $user */
    public function __construct(
        private readonly TimetableCsvParser $parser,
        private readonly TimetableCsvImportPreviewService $previewService,
        private readonly TimetableCsvImportDraftStore $drafts,
        private readonly int $organisationId,
        private readonly array $user,
        private readonly bool $allowConjoinedPeriods,
        private readonly ?ResourceTimetableStore $resources = null,
        private readonly ?AccountService $accounts = null,
    ) {}

    /** @param array<string, mixed> $input @param array<string, mixed> $files */
    public function handle(array $input, array $files): AdminTimetableCsvImportResponse
    {
        $versionId = (int) ($input['version'] ?? 0);
        if (!SessionAuth::isAdmin($this->user)) {
            return new AdminTimetableCsvImportResponse(403, $this->errors($versionId, ['Administrator access is required.']));
        }
        if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
            return new AdminTimetableCsvImportResponse(403, $this->errors($versionId, ['The form expired. Return to the timetable builder and try again.']));
        }
        if (($input['action'] ?? '') === 'create_missing_resource') {
            return $this->createMissingResource($versionId, $input);
        }
        try {
            $rows = $this->parser->parseUpload(is_array($files['csv_file'] ?? null) ? $files['csv_file'] : []);
            $preview = $this->previewService->preview($this->organisationId, $versionId, $rows, $this->allowConjoinedPeriods);
            $draft = $this->drafts->save($preview, (int) $this->user['id']);
            return new AdminTimetableCsvImportResponse(200, $this->preview($preview, (string) $draft['id']));
        } catch (TimetableCsvImportException $exception) {
            $this->drafts->discard();
            return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, $exception->errors(), $exception->missingRooms(), $exception->missingTeachers()));
        }
    }

    /** @param array<string,mixed> $input */
    private function createMissingResource(int $versionId, array $input): AdminTimetableCsvImportResponse
    {
        if ($this->resources === null) return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, ['Resource creation is unavailable. Upload and validate the CSV again.']));
        $version = $this->resources->findVersion($versionId);
        if ($version === null || $version->organisationId !== $this->organisationId) return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, ['The selected timetable is not available for this organisation.']));
        $type = (string) ($input['resource_type'] ?? '');
        $code = trim((string) ($input['code'] ?? ''));
        try {
            $service = new TimetableResourceService($this->resources);
            if ($type === 'teacher' && $this->accounts !== null) {
                try {
                    $this->accounts->createPerson($this->organisationId, $code, ['teacher']);
                } catch (AccountValidationException | \PDOException $exception) {
                    if (!$this->teacherExists($code)) throw $exception;
                }
                $message = 'Teacher ' . $code . ' is ready. They can set a password on first login.';
            } elseif ($type === 'teacher') {
                $service->createTeacher($this->organisationId, $code);
                $message = 'Teacher ' . $code . ' is ready. They can set a password on first login.';
            } elseif ($type === 'room') {
                try { $service->createRoom($this->organisationId, $code); }
                catch (\PDOException $exception) { if (!$this->roomExists($code)) throw $exception; }
                $message = 'Room ' . $code . ' is ready.';
            } else {
                throw new \Reqsheet\Timetable\TimetableValidationException(['Resource type is invalid.']);
            }
            return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, [$message], [], [], true));
        } catch (AccountValidationException | \Reqsheet\Timetable\TimetableValidationException | \PDOException $exception) {
            $message = $exception instanceof AccountValidationException || $exception instanceof \Reqsheet\Timetable\TimetableValidationException ? implode(' ', $exception->errors()) : 'That resource already exists or could not be saved.';
            return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, [$message]));
        }
    }

    private function roomExists(string $code): bool
    {
        foreach ($this->resources?->roomsForOrganisation($this->organisationId) ?? [] as $room) if (strcasecmp((string) $room['code'], $code) === 0) return true;
        return false;
    }

    private function teacherExists(string $code): bool
    {
        foreach ($this->resources?->usersForOrganisation($this->organisationId) ?? [] as $teacher) if (strcasecmp((string) ($teacher['staff_identifier'] ?? ''), $code) === 0) return true;
        return false;
    }

    private function preview(TimetableCsvImportPreview $preview, string $draftId): string
    {
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable / CSV import</p><h1>Import preview</h1></div><a class="button secondary" href="/admin/timetable?version=' . $preview->versionId . '">Back to timetable</a></section>';
        $body .= '<section class="editor-section" data-import-draft="' . $this->e($draftId) . '"><div class="section-heading"><div><p class="eyebrow">Selected timetable</p><h2>' . $this->e($preview->versionName) . '</h2></div></div>';
        $body .= '<p class="notice"><strong>Nothing has been saved.</strong> Confirmation creates a new timetable, imports the retained lessons, and activates it immediately. The source timetable remains unchanged. This preview expires after 15 minutes.</p>';
        $body .= '<dl class="summary-list"><div><dt>Source timetable</dt><dd>' . $this->e($preview->versionName) . '</dd></div><div><dt>Proposed new timetable</dt><dd>' . $this->e($preview->proposedVersionName) . '</dd></div></dl>';
        if ($preview->newClassCodes !== []) $body .= '<p class="notice"><strong>New class codes to add:</strong> ' . $this->e(implode(', ', $preview->newClassCodes)) . '</p>';
        if ($preview->skippedRooms !== []) {
            $body .= '<div class="notice warning"><strong>Rooms to skip</strong><ul>';
            foreach ($preview->skippedRooms as $room) $body .= '<li>Room ' . $this->e($room['code']) . ' does not exist within the selected timetable template, so its lessons will not be imported (' . $room['row_count'] . ' row' . ($room['row_count'] === 1 ? '' : 's') . '). ' . $this->resourceButton($preview->versionId, 'room', (string) $room['code'], 'Add Room ' . (string) $room['code']) . '</li>';
            $body .= '</ul></div>';
        }
        $body .= '<dl class="summary-list"><div><dt>Proposed lessons</dt><dd>' . count($preview->assignments) . '</dd></div><div><dt>Occupied periods</dt><dd>' . $preview->occupiedPeriods . '</dd></div><div><dt>Free room/period slots</dt><dd>' . $preview->freeSlots . '</dd></div></dl>';
        if ($preview->assignments === []) {
            $body .= '<p class="message">The CSV is structurally valid and contains no lesson assignments.</p>';
        } else {
            $body .= '<div class="timetable-scroll"><table><thead><tr><th>Day</th><th>Period</th><th>Duration</th><th>Room</th><th>Class</th><th>Teacher</th></tr></thead><tbody>';
            foreach ($preview->assignments as $assignment) {
                $duration = $assignment['duration'] === 1 ? '1 period' : $assignment['duration'] . ' periods (multi-period)';
                $body .= '<tr><td>' . $this->e($assignment['day']) . '</td><td>' . $this->e(implode(' – ', $assignment['periods'])) . '</td><td>' . $duration . '</td><td>' . $this->e($assignment['room_code']) . '</td><td>' . $this->e($assignment['class_code']) . '</td><td>' . $this->e($assignment['teacher_name']) . ' [' . $this->e($assignment['teacher_code']) . ']</td></tr>';
            }
            $body .= '</tbody></table></div>';
        }
        $csrf = $this->e(CsrfToken::value());
        $draft = $this->e($draftId);
        $body .= '<div class="form-actions"><form method="post" action="/admin/timetable/import/confirm"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="draft_id" value="' . $draft . '"><input type="hidden" name="action" value="import"><button type="submit">Import Timetable</button></form><form method="post" action="/admin/timetable/import/confirm"><input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="draft_id" value="' . $draft . '"><input type="hidden" name="action" value="cancel"><button type="submit" class="secondary">Cancel</button></form></div>';
        return PageLayout::render('Timetable CSV import preview', $body . '</section>', $this->user);
    }

    /** @param list<string> $errors */
    private function errors(int $versionId, array $errors, array $missingRooms = [], array $missingTeachers = [], bool $resourceCreated = false): string
    {
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable / CSV import</p><h1>CSV needs attention</h1></div><a class="button secondary" href="/admin/timetable?version=' . $versionId . '">Back to timetable</a></section>';
        $body .= '<section class="editor-section"><p>Correct the CSV and upload it again. Nothing has been saved.</p>' . ($resourceCreated ? '<p class="notice">Resource created successfully. Validate the same CSV again when ready.</p>' : '') . '<ul class="validation-errors">';
        foreach ($errors as $error) {
            $actions = '';
            foreach (array_values(array_unique($missingRooms)) as $code) if (str_contains($error, 'Room ' . $code . ' does not exist')) $actions .= $this->resourceButton($versionId, 'room', (string) $code, 'Add Room ' . (string) $code);
            if (str_contains($error, 'following teachers do not exist')) foreach (array_values(array_unique($missingTeachers)) as $code) $actions .= $this->resourceButton($versionId, 'teacher', (string) $code, 'Add Teacher ' . (string) $code);
            $body .= '<li>' . $this->e($error) . ($actions === '' ? '' : ' <span class="contextual-resource-actions">' . $actions . '</span>') . '</li>';
        }
        $body .= '</ul>' . self::uploadForm($versionId) . '</section>';
        return PageLayout::render('Timetable CSV validation', $body, $this->user);
    }

    private function resourceButton(int $versionId, string $type, string $code, string $label): string
    {
        return '<form method="post" action="/admin/timetable/import"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="version" value="' . $versionId . '"><input type="hidden" name="action" value="create_missing_resource"><input type="hidden" name="resource_type" value="' . $this->e($type) . '"><input type="hidden" name="code" value="' . $this->e($code) . '"><button type="submit" class="secondary">' . $this->e($label) . '</button></form>';
    }

    public static function uploadForm(int $versionId): string
    {
        return '<form method="post" action="/admin/timetable/import" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="' . self::escape(CsrfToken::value()) . '"><input type="hidden" name="version" value="' . $versionId . '"><input type="hidden" name="MAX_FILE_SIZE" value="' . TimetableCsvParser::MAX_BYTES . '"><label>Completed Reqsheet CSV<input type="file" name="csv_file" accept=".csv,text/csv" required></label><p class="muted">Maximum 2 MiB and 20,000 data rows. Validation and preview do not save assignments.</p><button type="submit">Validate and Preview</button></form>';
    }

    private function e(string $value): string
    {
        return self::escape($value);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
