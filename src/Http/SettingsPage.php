<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Settings\SettingsService;
use Reqsheet\Settings\SettingsValidationException;
use Reqsheet\Timetable\TimetableConfigurationStore;
use Reqsheet\Timetable\TimetableTemplateService;
use Reqsheet\Timetable\TimetableValidationException;
use Reqsheet\Http\CsrfToken;

final class SettingsPage
{
    public function __construct(private readonly SettingsService $settings, private readonly int $organisationId, private readonly array $user, private readonly ?TimetableConfigurationStore $timetable = null)
    {
    }

    /** @param array<string, mixed> $input */
    public function handle(string $method, array $input): string
    {
        if ($this->timetable !== null) return $this->templateHandle($method, $input);
        $message = null;
        if ($method === 'POST') {
            try {
                $this->settings->save($this->organisationId, $input);
                $message = 'Settings saved.';
            } catch (SettingsValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }
        $data = $this->settings->load($this->organisationId);
        return PageLayout::render('Settings', $this->form($data, $message) . $this->accountSection(), $this->user);
    }

    /** @param array<string, mixed> $input */
    private function templateHandle(string $method, array $input): string
    {
        $message = null;
        $editor = null;
        $targetVersionId = null;
        if ($method === 'POST') {
            $action = (string) ($input['action'] ?? '');
            if ($action === 'edit_template') { $editor = 'warning'; $targetVersionId = (int) ($input['source_version_id'] ?? 0); }
            elseif ($action === 'continue_edit_template') { $editor = 'edit'; $targetVersionId = (int) ($input['source_version_id'] ?? 0); }
            elseif ($action === 'create_template') $editor = 'create';
            elseif ($action === 'activate_template') {
                try {
                    if (!CsrfToken::valid($input['csrf_token'] ?? null)) throw new TimetableValidationException(['Your request expired. Please try again.']);
                    (new TimetableTemplateService($this->timetable))->activate($this->organisationId, (int) ($input['version_id'] ?? 0)); $message = 'Timetable activated.';
                }
                catch (TimetableValidationException $exception) { $message = implode(' ', $exception->errors()); }
            }
            elseif ($action === 'save_template') {
                try {
                    $source = (int) ($input['source_version_id'] ?? 0);
                    // Creation uses the authenticated organisation's existing
                    // settings and the template service's safe defaults.
                    $current = $this->settings->load($this->organisationId);
                    if ($source > 0) $this->settings->save($this->organisationId, array_replace($current, $input));
                    $data = $this->settings->load($this->organisationId);
                    $label = trim((string) ($input['template_label'] ?? ''));
                    if ($source === 0) $data = array_replace($data, ['working_days' => $input['working_days'] ?? [], 'first_day_of_week' => $input['first_day_of_week'] ?? 0, 'periods_per_day' => $input['periods_per_day'] ?? 0]);
                    $id = (new TimetableTemplateService($this->timetable))->create($this->organisationId, $source > 0 ? $source : null, $label, null, $data);
                    $message = 'Timetable template saved as version ' . $id . '.';
                } catch (SettingsValidationException | \Reqsheet\Timetable\TimetableValidationException $exception) {
                    $message = implode(' ', $exception->errors());
                    $editor = ((int) ($input['source_version_id'] ?? 0)) > 0 ? 'edit' : 'create';
                } catch (\RuntimeException $exception) {
                    $message = $exception instanceof \PDOException ? 'Existing room resources prevent this template change; preserve or manage those resources separately.' : ($exception->getMessage() !== '' ? $exception->getMessage() : 'The timetable template could not be saved.');
                    $editor = 'create';
                }
            }
        }
        $data = $this->settings->load($this->organisationId);
        return PageLayout::render('Settings', $this->templatePage($data, $message, $editor, $targetVersionId), $this->user);
    }

    /** @param array<string, mixed> $data */
    private function templatePage(array $data, ?string $message, ?string $editor, ?int $targetVersionId = null): string
    {
        /** @var TimetableConfigurationStore $timetable */
        $timetable = $this->timetable;
        $templateService = new TimetableTemplateService($timetable);
        $active = $templateService->activeTemplate($this->organisationId);
        $notice = $message === null ? '' : '<p class="notice ' . (str_starts_with($message, 'Timetable template saved') || $message === 'Timetable activated.' ? '' : 'error') . '">' . $this->e($message) . '</p>';
        $body = '<section class="content-narrow"><h1>Settings</h1><p>Organisation settings and timetable templates.</p>' . $notice;
        if ($editor === 'warning') {
            $target = $targetVersionId > 0 ? $timetable->findVersion($targetVersionId) : ($active['version'] ?? null);
            if ($target === null || $target->organisationId !== $this->organisationId) $target = null;
            $body .= '<section class="notice"><h2>Before editing the timetable template</h2><p>Changing this timetable template may affect lessons already entered. Changes to periods, days or breaks could cause existing timetable assignments to become invalid or appear in different places.</p><div class="form-actions"><form method="post"><input type="hidden" name="action" value="continue_edit_template"><input type="hidden" name="source_version_id" value="' . (int) ($target?->id ?? 0) . '"><button' . ($target === null ? ' disabled' : '') . '>Continue to edit template</button></form><form method="post"><input type="hidden" name="action" value="cancel_template"><button class="secondary">Cancel</button></form></div></section>';
        } elseif ($editor === 'edit' || $editor === 'create') {
            $source = $editor === 'edit' && $targetVersionId !== null ? $timetable->findVersion($targetVersionId) : null;
            if ($source !== null && $source->organisationId !== $this->organisationId) $source = null;
            $body .= $this->templateEditor($data, $editor, $source);
        } else {
            $body .= $this->templateSummary($active, $data);
            $body .= $this->templateList($timetable->versionsForOrganisation($this->organisationId), $active['version']->id ?? null);
            $body .= '<div class="form-actions"><form method="post"><input type="hidden" name="action" value="create_template"><button>Create new timetable template</button></form></div>';
        }
        return $body . $this->accountSection() . '</section>';
    }

    private function templateList(array $versions, ?int $activeId): string
    {
        if ($versions === []) return '';
        $html = '<section class="settings-section"><h2>Timetable templates</h2><ul class="plain-list">';
        foreach ($versions as $version) {
            $active = $version->id === $activeId;
            $html .= '<li><strong>' . $this->e((string) $version->label) . '</strong> ' . ($active ? '<span class="muted">(active)</span>' : '') . '<div class="form-actions"><form method="post"><input type="hidden" name="action" value="edit_template"><input type="hidden" name="source_version_id" value="' . $version->id . '"><button class="secondary">Edit timetable</button></form>' . ($active ? '' : '<form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="activate_template"><input type="hidden" name="version_id" value="' . $version->id . '"><button>Make active</button></form>') . '</div></li>';
        }
        return $html . '</ul></section>';
    }

    /** @param array<string, mixed>|null $active */
    private function templateSummary(?array $active, array $data): string
    {
        if ($active === null) return '<section class="settings-section"><h2>Current active timetable template</h2><p>No active timetable template exists yet. Create one before entering timetable assignments.</p></section>';
        $version = $active['version'];
        $slots = (array) ($active['slots'] ?? []);
        $days = [];
        foreach ($slots as $slot) $days[$slot->dayOfWeek] = true;
        ksort($days);
        $periods = 0; $separators = [];
        foreach ($slots as $slot) {
            if ($slot->isTeaching()) $periods = max($periods, (int) $slot->teachingPeriodNumber);
            else $separators[] = (string) $slot->label;
        }
        $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $dayLabels = implode(', ', array_map(static fn (int $day): string => $dayNames[$day], array_keys($days)));
        $separatorLabels = $separators === [] ? 'None' : implode(', ', array_unique($separators));
        $custom = (array) ($data['custom_day_settings'] ?? []);
        $customTiming = [];
        foreach ($custom as $day => $timing) if (is_array($timing) && (($timing['start_time'] ?? '') !== '' || ($timing['period_minutes'] ?? '') !== '')) $customTiming[] = $day . ': ' . ($timing['start_time'] ?? 'default') . ' / ' . ($timing['period_minutes'] ?? 'default') . ' min';
        $timing = (string) ($data['start_time'] ?? '08:00') . ', ' . ((string) ($data['standard_period_minutes'] ?? '') ?: 'standard') . ' min' . ($customTiming === [] ? '' : ' (' . implode('; ', $customTiming) . ')');
        $firstDay = $dayNames[(int) ($data['first_day_of_week'] ?? 1)] ?? 'Monday';
        return '<section class="settings-section template-summary"><h2>Current active timetable template</h2><p><strong>' . $this->e($version->label ?: 'Untitled timetable') . '</strong></p><dl><dt>Working days</dt><dd>' . $this->e($dayLabels) . '</dd><dt>First day of week</dt><dd>' . $this->e($dayNames[$version->firstDayOfWeek] ?? 'Monday') . '</dd><dt>Periods per day</dt><dd>' . $periods . '</dd><dt>Timings</dt><dd>' . $this->e($timing) . '</dd><dt>Separators</dt><dd>' . $this->e($separatorLabels) . '</dd><dt>Conjoined periods</dt><dd>' . (!empty($data['allow_double_periods']) ? 'Allowed' : 'Disabled') . '</dd></dl></section>';
    }

    private function templateEditor(array $data, string $mode, ?\Reqsheet\Timetable\TimetableVersion $source): string
    {
        $label = $mode === 'edit' && $source !== null ? (string) ($source->label ?? '') : '';
        if ($mode === 'create') {
            $days = (array) ($data['working_days'] ?? [1, 2, 3, 4, 5]);
            $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
            $selectedFirstDay = in_array((int) ($data['first_day_of_week'] ?? 0), $days, true) ? (int) $data['first_day_of_week'] : (int) ($days[0] ?? 1);
            $dayInputs = '';
            foreach ($dayNames as $number => $name) $dayInputs .= '<label class="check-label"><input type="checkbox" name="working_days[]" value="' . $number . '"' . (in_array($number, $days, true) ? ' checked' : '') . '> ' . $name . '</label>';
            return '<section class="settings-section"><h2>Create new timetable template</h2><form method="post"><input type="hidden" name="action" value="save_template"><label>Timetable name<input name="template_label" maxlength="255" required></label><fieldset><legend>Days of the week</legend><div class="day-options">' . $dayInputs . '</div><p class="muted">Select at least one day.</p></fieldset><label>First day of the week<select name="first_day_of_week" required>' . $this->dayOptions($dayNames, $selectedFirstDay) . '</select></label><label>Periods per day<input type="number" name="periods_per_day" min="1" max="20" value="' . (int) ($data['periods_per_day'] ?? 6) . '" required></label><div class="form-actions"><button>Create timetable template</button></div></form><form method="post"><input type="hidden" name="action" value="cancel_template"><button class="secondary">Cancel</button></form></section>';
        }
        $sourceInput = $source === null ? '' : '<input type="hidden" name="source_version_id" value="' . $source->id . '"><p class="muted">Saving creates a successor template and preserves the existing version.</p>';
        if ($source !== null) {
            $sourceSlots = $this->timetable?->slotsForVersion($source->id) ?? [];
            $sourceDays = array_values(array_unique(array_map(static fn ($slot): int => $slot->dayOfWeek, $sourceSlots)));
            sort($sourceDays);
            $sourcePeriods = 0;
            foreach ($sourceSlots as $slot) if ($slot->isTeaching()) $sourcePeriods = max($sourcePeriods, (int) $slot->teachingPeriodNumber);
            $data = array_replace($data, ['working_days' => $sourceDays, 'first_day_of_week' => $source->firstDayOfWeek, 'periods_per_day' => max(1, $sourcePeriods)]);
        }
        $form = $this->form($data, null, false);
        $replacement = '<form method="post"><input type="hidden" name="action" value="save_template">' . $sourceInput . '<label>Timetable name<input name="template_label" value="' . $this->e($label) . '" maxlength="255" required></label>';
        $count = 1;
        $form = str_replace('<form method="post">', $replacement, $form, $count);
        return '<section class="settings-section"><h2>' . ($mode === 'edit' ? 'Edit timetable template' : 'Create new timetable template') . '</h2>' . $form . '<form method="post"><input type="hidden" name="action" value="cancel_template"><button class="secondary">Cancel</button></form></section>';
    }

    /** @param array<string, mixed> $data */
    private function form(array $data, ?string $message, bool $includeSchoolName = true): string
    {
        $days = (array) ($data['working_days'] ?? []);
        $custom = (array) ($data['custom_day_settings'] ?? []);
        $separators = (array) ($data['separators'] ?? []);
        $notice = $message === null ? '' : '<p class="notice ' . ($message === 'Settings saved.' ? '' : 'error') . '">' . $this->e($message) . '</p>';
        $dayNames = [1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday', 7 => 'Sunday'];
        $dayInputs = '';
        foreach ($dayNames as $number => $name) $dayInputs .= '<label class="check-label"><input type="checkbox" name="working_days[]" value="' . $number . '"' . (in_array($number, $days, true) ? ' checked' : '') . '> ' . $name . '</label>';
        $customInputs = '';
        foreach ($dayNames as $number => $name) {
            $value = is_array($custom[(string) $number] ?? null) ? $custom[(string) $number] : [];
            $customInputs .= '<div><strong>' . $name . '</strong><label>Start time<input type="time" name="custom_day_start[' . $number . ']" value="' . $this->e((string) ($value['start_time'] ?? '')) . '"></label><label>Period length (minutes)<input type="number" min="1" name="custom_day_length[' . $number . ']" value="' . $this->e((string) ($value['period_minutes'] ?? '')) . '"></label></div>';
        }
        $separatorInputs = '';
        $periodOptions = '';
        for ($i = 1; $i < (int) ($data['periods_per_day'] ?? 6); $i++) $periodOptions .= '<option value="' . $i . '">After period ' . $i . '</option>';
        foreach ($separators as $separator) $separatorInputs .= $this->separatorRow((string) ($separator['type'] ?? ''), (string) ($separator['label'] ?? ''), (int) ($separator['after_period'] ?? 0), (string) ($separator['duration_minutes'] ?? ''), $this->periodOptions((int) ($data['periods_per_day'] ?? 6), (int) ($separator['after_period'] ?? 0)));
        if ($separatorInputs === '') $separatorInputs = $this->separatorRow('', '', 0, '', $periodOptions);
        $separatorTemplate = $this->separatorRow('', '', 0, '', $periodOptions);
        $schoolField = $includeSchoolName ? '<label>School name<input name="school_name" value="' . $this->e((string) ($data['school_name'] ?? '')) . '" required></label>' : '';
        $html = '<section class="content-narrow"><h1>Settings</h1><p>Complete the organisation settings before normal use.</p>' . $notice . '<form method="post"><div class="settings-section"><h2>Required</h2><label>School name<input name="school_name" value="' . $this->e((string) ($data['school_name'] ?? '')) . '" required></label><fieldset><legend>Working days</legend><div class="day-options">' . $dayInputs . '</div></fieldset><label>First day of working week<select name="first_day_of_week">' . $this->dayOptions($dayNames, (int) ($data['first_day_of_week'] ?? 1)) . '</select></label><label>Periods per day<input type="number" name="periods_per_day" min="1" max="20" value="' . (int) ($data['periods_per_day'] ?? 6) . '" required data-period-count></label></div><div class="settings-section"><h2>Optional timings</h2><label>Start time<input type="time" name="start_time" value="' . $this->e((string) ($data['start_time'] ?? '08:00')) . '"></label><label>Standard period length (minutes)<input type="number" min="1" name="standard_period_minutes" value="' . $this->e((string) ($data['standard_period_minutes'] ?? '')) . '"></label><details><summary>Custom day timings</summary><p>Set a different start time or period length for individual days.</p><div class="day-options">' . $customInputs . '</div></details><fieldset><legend>Breaks and separators</legend><p>Choose what occurs between periods. Duration is optional.</p><div class="separator-list" data-separators>' . $separatorInputs . '</div><button type="button" data-add-separator>Add separator</button></fieldset><label class="check-label"><input type="checkbox" name="allow_conjoined_periods" value="1"' . (!empty($data['allow_double_periods']) ? ' checked' : '') . '> Allow conjoined periods</label><p>When enabled, one lesson may span any number of adjoining teaching periods. A Break, Lunch, or Other separator always stops the span.</p></div><div class="form-actions"><button>Save settings</button></div></form></section><template id="separator-template">' . $separatorTemplate . '</template><script>' . $this->script() . '</script>';
        return $includeSchoolName ? $html : str_replace($schoolField === '' ? '<label>School name<input name="school_name" value="' . $this->e((string) ($data['school_name'] ?? '')) . '" required></label>' : '', '', $html);
    }

    private function separatorRow(string $type = '', string $label = '', int $after = 0, string $duration = '', string $periodOptions = ''): string
    {
        return '<div><label>Type<select name="separator_type[]"><option value="">Select type</option><option' . ($type === 'Break' ? ' selected' : '') . '>Break</option><option' . ($type === 'Lunchtime' ? ' selected' : '') . '>Lunchtime</option><option' . ($type === 'Other' ? ' selected' : '') . '>Other</option></select></label><label>Label (optional)<input name="separator_label[]" value="' . $this->e($label) . '" placeholder="For example Lunch or Assembly"></label><label>Position<select name="separator_after[]"><option value="">Select position</option>' . $periodOptions . '</select></label><label>Duration (minutes, optional)<input type="number" min="1" name="separator_duration[]" value="' . $this->e($duration) . '"></label><button type="button" data-remove>Remove</button></div>';
    }

    private function periodOptions(int $periods, int $selected): string
    {
        $options = '';
        for ($i = 1; $i < $periods; $i++) $options .= '<option value="' . $i . '"' . ($i === $selected ? ' selected' : '') . '>After period ' . $i . '</option>';
        return $options;
    }

    private function dayOptions(array $days, int $selected): string
    {
        $html = '';
        foreach ($days as $number => $name) $html .= '<option value="' . $number . '"' . ($selected === $number ? ' selected' : '') . '>' . $name . '</option>';
        return $html;
    }

    private function script(): string
    {
        return "function refreshSeparatorPositions(){var count=parseInt(document.querySelector('[data-period-count]').value,10)||1;document.querySelectorAll('[name=separator_after\\[\\]]').forEach(function(select){var selected=select.value;select.innerHTML='<option value=\"\">Select position</option>';for(var i=1;i<count;i++){var option=document.createElement('option');option.value=i;option.textContent='After period '+i;option.selected=String(i)===selected;select.appendChild(option);}});}document.addEventListener('input',function(event){if(event.target.matches('[data-period-count]'))refreshSeparatorPositions();});document.addEventListener('click',function(event){var target=event.target;if(target.matches('[data-add-separator]')){var t=document.getElementById('separator-template');document.querySelector('[data-separators]').insertAdjacentHTML('beforeend',t.innerHTML);refreshSeparatorPositions();}if(target.matches('[data-remove]')){var parent=target.closest('[data-separators] > div');if(parent)parent.remove();}});";
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

    private function accountSection(): string
    {
        return '<section class="settings-section account-management"><h2>Reqsheet account</h2><p>This school’s membership and account with the Reqsheet service.</p><p class="notice">Membership and payment management will be available here.</p></section>';
    }
}
