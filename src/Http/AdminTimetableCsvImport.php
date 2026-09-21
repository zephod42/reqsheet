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
        $action = (string) ($input['action'] ?? '');
        if (!SessionAuth::isAdmin($this->user)) {
            return $action === 'create_missing_resource' ? $this->jsonResourceResponse(403, 'Administrator access is required.') : new AdminTimetableCsvImportResponse(403, $this->errors($versionId, ['Administrator access is required.']));
        }
        if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
            return $action === 'create_missing_resource' ? $this->jsonResourceResponse(403, 'The form expired. Refresh the import page and try again.') : new AdminTimetableCsvImportResponse(403, $this->errors($versionId, ['The form expired. Return to the timetable builder and try again.']));
        }
        if ($action === 'create_missing_resource') {
            return $this->createMissingResource($versionId, $input);
        }
        if ($action === 'validate_again') {
            return $this->validateAgain($versionId, (string) ($input['draft_id'] ?? ''));
        }
        $csvContent = '';
        try {
            $csvContent = $this->parser->uploadContent(is_array($files['csv_file'] ?? null) ? $files['csv_file'] : []);
            $rows = $this->parser->parse($csvContent);
            $preview = $this->previewService->preview($this->organisationId, $versionId, $rows, $this->allowConjoinedPeriods);
            $draft = $this->drafts->save($preview, (int) $this->user['id'], $csvContent);
            return new AdminTimetableCsvImportResponse(200, $this->preview($preview, (string) $draft['id']));
        } catch (TimetableCsvImportException $exception) {
            if ($csvContent !== '') {
                $draft = $this->drafts->saveValidationFailure($this->organisationId, $versionId, (int) $this->user['id'], $csvContent, $exception->errors(), $exception->missingRooms(), $exception->missingTeachers());
                return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, $exception->errors(), $exception->missingRooms(), $exception->missingTeachers(), false, (string) $draft['id']));
            }
            $this->drafts->discard();
            return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, $exception->errors()));
        }
    }

    private function validateAgain(int $versionId, string $draftId): AdminTimetableCsvImportResponse
    {
        $draft = $this->drafts->load($draftId, $this->organisationId, (int) $this->user['id']);
        if ($draft === null || (int) ($draft['version_id'] ?? 0) !== $versionId || !is_string($draft['csv_content'] ?? null)) {
            return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, ['The retained CSV has expired. Upload it again to continue.']));
        }
        try {
            $preview = $this->previewService->preview($this->organisationId, $versionId, $this->parser->parse($draft['csv_content']), $this->allowConjoinedPeriods);
            $fresh = $this->drafts->save($preview, (int) $this->user['id'], $draft['csv_content']);
            return new AdminTimetableCsvImportResponse(200, $this->preview($preview, (string) $fresh['id']));
        } catch (TimetableCsvImportException $exception) {
            $fresh = $this->drafts->saveValidationFailure($this->organisationId, $versionId, (int) $this->user['id'], $draft['csv_content'], $exception->errors(), $exception->missingRooms(), $exception->missingTeachers());
            return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, $exception->errors(), $exception->missingRooms(), $exception->missingTeachers(), false, (string) $fresh['id']));
        }
    }

    /** @param array<string,mixed> $input */
    private function createMissingResource(int $versionId, array $input): AdminTimetableCsvImportResponse
    {
        $draftId = (string) ($input['draft_id'] ?? '');
        $draft = $this->drafts->load($draftId, $this->organisationId, (int) $this->user['id']);
        if ($draft === null || (int) ($draft['version_id'] ?? 0) !== $versionId) return $this->jsonResourceResponse(422, 'The retained CSV has expired. Upload it again to continue.');
        if ($this->resources === null) return $this->jsonResourceResponse(422, 'Resource creation is unavailable.');
        $version = $this->resources->findVersion($versionId);
        if ($version === null || $version->organisationId !== $this->organisationId) return $this->jsonResourceResponse(422, 'The selected timetable is not available for this organisation.');
        $type = (string) ($input['resource_type'] ?? '');
        $code = trim((string) ($input['code'] ?? ''));
        $allowedCodes = $type === 'room' ? ($draft['missing_rooms'] ?? []) : ($type === 'teacher' ? ($draft['missing_teachers'] ?? []) : []);
        if (!in_array($code, array_map('strval', is_array($allowedCodes) ? $allowedCodes : []), true)) {
            return $this->jsonResourceResponse(422, 'That resource is not part of this validation result. Validate the retained CSV again.');
        }
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
                catch (\Reqsheet\Timetable\TimetableValidationException | \PDOException $exception) { if (!$this->roomExists($code)) throw $exception; }
                $message = 'Room ' . $code . ' is ready.';
            } else {
                throw new \Reqsheet\Timetable\TimetableValidationException(['Resource type is invalid.']);
            }
            return $this->jsonResourceResponse(200, $message, true, $type, $code);
        } catch (AccountValidationException | \Reqsheet\Timetable\TimetableValidationException | \PDOException $exception) {
            $message = $exception instanceof AccountValidationException || $exception instanceof \Reqsheet\Timetable\TimetableValidationException ? implode(' ', $exception->errors()) : 'That resource already exists or could not be saved.';
            return $this->jsonResourceResponse(422, $message);
        }
    }

    private function jsonResourceResponse(int $status, string $message, bool $success = false, string $type = '', string $code = ''): AdminTimetableCsvImportResponse
    {
        return new AdminTimetableCsvImportResponse($status, json_encode(['success' => $success, 'message' => $message, 'type' => $type, 'code' => $code], JSON_THROW_ON_ERROR), null, true);
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
            foreach ($preview->skippedRooms as $room) $body .= '<li>Room ' . $this->e($room['code']) . ' does not exist within the selected timetable template, so its lessons will not be imported (' . $room['row_count'] . ' row' . ($room['row_count'] === 1 ? '' : 's') . '). ' . $this->resourceButton($preview->versionId, 'room', (string) $room['code'], 'Add Room ' . (string) $room['code'], $draftId) . '</li>';
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
    private function errors(int $versionId, array $errors, array $missingRooms = [], array $missingTeachers = [], bool $resourceCreated = false, string $draftId = ''): string
    {
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable / CSV import</p><h1>CSV needs attention</h1></div><a class="button secondary" href="/admin/timetable?version=' . $versionId . '">Back to timetable</a></section>';
        $body .= '<section class="editor-section" data-import-draft="' . $this->e($draftId) . '"><p>' . ($draftId !== '' ? 'The uploaded CSV is retained for 15 minutes. Resolve the items below, then validate it again.' : 'Correct the CSV and upload it again.') . ' Nothing has been saved.</p>' . ($resourceCreated ? '<p class="notice">Resource created successfully. Validate the same CSV again when ready.</p>' : '') . '<ul class="validation-errors">';
        foreach ($errors as $error) {
            $actions = '';
            foreach (array_values(array_unique($missingRooms)) as $code) if (str_contains($error, 'Room ' . $code . ' does not exist')) $actions .= $this->resourceButton($versionId, 'room', (string) $code, 'Add Room ' . (string) $code, $draftId);
            if (str_contains($error, 'following teachers do not exist')) foreach (array_values(array_unique($missingTeachers)) as $code) $actions .= $this->resourceButton($versionId, 'teacher', (string) $code, 'Add Teacher ' . (string) $code, $draftId);
            $body .= '<li>' . $this->e($error) . ($actions === '' ? '' : ' <span class="contextual-resource-actions">' . $actions . '</span>') . '</li>';
        }
        $body .= '</ul>' . ($draftId !== '' ? $this->validateAgainForm($versionId, $draftId) : '') . self::uploadForm($versionId) . '</section><script>' . self::resourceScript() . '</script>';
        return PageLayout::render('Timetable CSV validation', $body, $this->user);
    }

    private function resourceButton(int $versionId, string $type, string $code, string $label, string $draftId): string
    {
        return '<form method="post" action="/admin/timetable/import" data-resource-form><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="version" value="' . $versionId . '"><input type="hidden" name="draft_id" value="' . $this->e($draftId) . '"><input type="hidden" name="action" value="create_missing_resource"><input type="hidden" name="resource_type" value="' . $this->e($type) . '"><input type="hidden" name="code" value="' . $this->e($code) . '"><button type="submit" class="secondary">' . $this->e($label) . '</button><span class="resource-error" role="alert"></span></form>';
    }

    private function validateAgainForm(int $versionId, string $draftId): string
    {
        return '<form method="post" action="/admin/timetable/import" class="validate-again-form"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="version" value="' . $versionId . '"><input type="hidden" name="draft_id" value="' . $this->e($draftId) . '"><input type="hidden" name="action" value="validate_again"><button type="submit">Validate Again</button></form>';
    }

    private static function resourceScript(): string
    {
        return 'document.querySelectorAll("[data-resource-form]").forEach(function(form){form.addEventListener("submit",function(event){event.preventDefault();var button=form.querySelector("button"),error=form.querySelector(".resource-error"),endpoint=form.getAttribute("action");button.disabled=true;error.textContent="";fetch(endpoint,{method:"POST",body:new FormData(form),headers:{"Accept":"application/json"}}).then(function(response){return response.json().then(function(data){return {ok:response.ok,data:data};});}).then(function(result){if(result.ok&&result.data.success){var done=document.createElement("span");done.className="resource-added";done.setAttribute("role","status");done.textContent="✓ Added";button.replaceWith(done);}else{error.textContent=result.data.message||"Could not add this resource.";button.disabled=false;}}).catch(function(){error.textContent="Could not add this resource. Try again.";button.disabled=false;});});});';
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
