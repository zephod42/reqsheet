<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Timetable\TimetableCsvImportDraftStore;
use Reqsheet\Timetable\TimetableCsvImportException;
use Reqsheet\Timetable\TimetableCsvImportService;

final class AdminTimetableCsvConfirm
{
    /** @param array<string, mixed> $user */
    public function __construct(
        private readonly TimetableCsvImportService $importer,
        private readonly TimetableCsvImportDraftStore $drafts,
        private readonly int $organisationId,
        private readonly array $user,
    ) {}

    /** @param array<string, mixed> $input */
    public function handle(array $input): AdminTimetableCsvImportResponse
    {
        if (!SessionAuth::isAdmin($this->user)) {
            return new AdminTimetableCsvImportResponse(403, $this->error(['Administrator access is required.']));
        }
        if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
            return new AdminTimetableCsvImportResponse(403, $this->error(['The form expired. Return to the timetable builder and try again.']));
        }
        $draftId = is_string($input['draft_id'] ?? null) ? $input['draft_id'] : '';
        $draft = $this->drafts->load($draftId, $this->organisationId, (int) $this->user['id']);
        if ($draft === null) {
            return new AdminTimetableCsvImportResponse(422, $this->error(['The import preview is missing or expired. Upload and validate the CSV again.']));
        }
        $versionId = (int) ($draft['version_id'] ?? 0);
        if (($input['action'] ?? null) === 'cancel') {
            $this->drafts->discardOwned($draftId, $this->organisationId, (int) $this->user['id']);
            return new AdminTimetableCsvImportResponse(303, '', '/admin/timetable?version=' . $versionId);
        }
        if (($input['action'] ?? null) !== 'import') {
            return new AdminTimetableCsvImportResponse(422, $this->error(['Choose Import Timetable or Cancel.'], $versionId));
        }
        try {
            $result = $this->importer->import($this->organisationId, $draft);
        } catch (TimetableCsvImportException $exception) {
            $this->drafts->discardOwned($draftId, $this->organisationId, (int) $this->user['id']);
            return new AdminTimetableCsvImportResponse(422, $this->error($exception->errors(), $versionId));
        }
        $this->drafts->discardOwned($draftId, $this->organisationId, (int) $this->user['id']);
        return new AdminTimetableCsvImportResponse(303, '', '/admin/timetable?version=' . $result->versionId . '&csv_imported=1');
    }

    /** @param list<string> $errors */
    public function error(array $errors, int $versionId = 0): string
    {
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable / CSV import</p><h1>Timetable not imported</h1></div><a class="button secondary" href="/admin/timetable' . ($versionId > 0 ? '?version=' . $versionId : '') . '">Back to timetable</a></section>';
        $body .= '<section class="editor-section"><p>No lessons were saved.</p><ul class="validation-errors">';
        foreach ($errors as $error) $body .= '<li>' . $this->e($error) . '</li>';
        return PageLayout::render('Timetable import not completed', $body . '</ul></section>', $this->user);
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
