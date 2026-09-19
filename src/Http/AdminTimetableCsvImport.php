<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Timetable\TimetableCsvImportDraftStore;
use Reqsheet\Timetable\TimetableCsvImportException;
use Reqsheet\Timetable\TimetableCsvImportPreview;
use Reqsheet\Timetable\TimetableCsvImportPreviewService;
use Reqsheet\Timetable\TimetableCsvParser;

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
        try {
            $rows = $this->parser->parseUpload(is_array($files['csv_file'] ?? null) ? $files['csv_file'] : []);
            $preview = $this->previewService->preview($this->organisationId, $versionId, $rows, $this->allowConjoinedPeriods);
            $draft = $this->drafts->save($preview, (int) $this->user['id']);
            return new AdminTimetableCsvImportResponse(200, $this->preview($preview, (string) $draft['id']));
        } catch (TimetableCsvImportException $exception) {
            $this->drafts->discard();
            return new AdminTimetableCsvImportResponse(422, $this->errors($versionId, $exception->errors()));
        }
    }

    private function preview(TimetableCsvImportPreview $preview, string $draftId): string
    {
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable / CSV import</p><h1>Import preview</h1></div><a class="button secondary" href="/admin/timetable?version=' . $preview->versionId . '">Back to timetable</a></section>';
        $body .= '<section class="editor-section" data-import-draft="' . $this->e($draftId) . '"><div class="section-heading"><div><p class="eyebrow">Selected timetable</p><h2>' . $this->e($preview->versionName) . '</h2></div></div>';
        $body .= '<p class="notice"><strong>Nothing has been saved.</strong> This validated preview expires after 15 minutes. Importing does not activate the timetable or change the currently active timetable.</p>';
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
    private function errors(int $versionId, array $errors): string
    {
        $body = '<section class="page-header"><div><p class="eyebrow">Admin / Timetable / CSV import</p><h1>CSV needs attention</h1></div><a class="button secondary" href="/admin/timetable?version=' . $versionId . '">Back to timetable</a></section>';
        $body .= '<section class="editor-section"><p>Correct the CSV and upload it again. Nothing has been saved.</p><ul class="validation-errors">';
        foreach ($errors as $error) $body .= '<li>' . $this->e($error) . '</li>';
        $body .= '</ul>' . self::uploadForm($versionId) . '</section>';
        return PageLayout::render('Timetable CSV validation', $body, $this->user);
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
