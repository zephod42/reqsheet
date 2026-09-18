<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Settings\SettingsService;
use Reqsheet\Settings\SettingsValidationException;

final class SettingsPage
{
    public function __construct(private readonly SettingsService $settings, private readonly int $organisationId, private readonly array $user)
    {
    }

    /** @param array<string, mixed> $input */
    public function handle(string $method, array $input): string
    {
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
        return PageLayout::render('Settings', $this->form($data, $message), $this->user);
    }

    /** @param array<string, mixed> $data */
    private function form(array $data, ?string $message): string
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
        foreach ($separators as $separator) $separatorInputs .= $this->separatorRow((string) ($separator['type'] ?? ''), (int) ($separator['after_period'] ?? 0), (string) ($separator['duration_minutes'] ?? ''), $this->periodOptions((int) ($data['periods_per_day'] ?? 6), (int) ($separator['after_period'] ?? 0)));
        if ($separatorInputs === '') $separatorInputs = $this->separatorRow('', 0, '', $periodOptions);
        $separatorTemplate = $this->separatorRow('', 0, '', $periodOptions);
        return '<section class="content-narrow"><h1>Settings</h1><p>Complete the organisation settings before normal use.</p>' . $notice . '<form method="post"><div class="settings-section"><h2>Required</h2><label>School name<input name="school_name" value="' . $this->e((string) ($data['school_name'] ?? '')) . '" required></label><fieldset><legend>Working days</legend><div class="day-options">' . $dayInputs . '</div></fieldset><label>First day of working week<select name="first_day_of_week">' . $this->dayOptions($dayNames, (int) ($data['first_day_of_week'] ?? 1)) . '</select></label><label>Periods per day<input type="number" name="periods_per_day" min="1" max="20" value="' . (int) ($data['periods_per_day'] ?? 6) . '" required data-period-count></label><fieldset><legend>Rooms</legend><div class="room-list" data-rooms>' . $this->roomInputs((array) ($data['rooms'] ?? [])) . '</div><button type="button" data-add-room>Add room</button></fieldset></div><div class="settings-section"><h2>Optional timings</h2><label>Start time<input type="time" name="start_time" value="' . $this->e((string) ($data['start_time'] ?? '08:00')) . '"></label><label>Standard period length (minutes)<input type="number" min="1" name="standard_period_minutes" value="' . $this->e((string) ($data['standard_period_minutes'] ?? '')) . '"></label><details><summary>Custom day timings</summary><p>Set a different start time or period length for individual days.</p><div class="day-options">' . $customInputs . '</div></details><fieldset><legend>Breaks and separators</legend><p>Choose what occurs between periods. Duration is optional.</p><div class="separator-list" data-separators>' . $separatorInputs . '</div><button type="button" data-add-separator>Add separator</button></fieldset><label class="check-label"><input type="checkbox" name="allow_conjoined_periods" value="1"' . (!empty($data['allow_double_periods']) ? ' checked' : '') . '> Allow conjoined periods</label><p>When enabled, one lesson may span any number of adjoining teaching periods. A Break, Lunch, or Other separator always stops the span.</p></div><div class="form-actions"><button>Save settings</button></div></form></section><template id="room-template"><label>Room<input name="rooms[]"><button type="button" data-remove>Remove</button></label></template><template id="separator-template">' . $separatorTemplate . '</template><script>' . $this->script() . '</script>';
    }

    private function roomInputs(array $rooms): string
    {
        if ($rooms === []) $rooms = [''];
        return implode('', array_map(fn (mixed $room): string => '<label>Room<input name="rooms[]" value="' . $this->e((string) $room) . '"><button type="button" data-remove>Remove</button></label>', $rooms));
    }

    private function separatorRow(string $type = '', int $after = 0, string $duration = '', string $periodOptions = ''): string
    {
        return '<div><label>Type<select name="separator_type[]"><option value="">Select type</option><option' . ($type === 'Break' ? ' selected' : '') . '>Break</option><option' . ($type === 'Lunchtime' ? ' selected' : '') . '>Lunchtime</option><option' . ($type === 'Other' ? ' selected' : '') . '>Other</option></select></label><label>Position<select name="separator_after[]"><option value="">Select position</option>' . $periodOptions . '</select></label><label>Duration (minutes, optional)<input type="number" min="1" name="separator_duration[]" value="' . $this->e($duration) . '"></label><button type="button" data-remove>Remove</button></div>';
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
        return "function refreshSeparatorPositions(){var count=parseInt(document.querySelector('[data-period-count]').value,10)||1;document.querySelectorAll('[name=separator_after\\[\\]]').forEach(function(select){var selected=select.value;select.innerHTML='<option value=\"\">Select position</option>';for(var i=1;i<count;i++){var option=document.createElement('option');option.value=i;option.textContent='After period '+i;option.selected=String(i)===selected;select.appendChild(option);}});}document.addEventListener('input',function(event){if(event.target.matches('[data-period-count]'))refreshSeparatorPositions();});document.addEventListener('click',function(event){var target=event.target;if(target.matches('[data-add-room]')){var t=document.getElementById('room-template');document.querySelector('[data-rooms]').insertAdjacentHTML('beforeend',t.innerHTML);}if(target.matches('[data-add-separator]')){var t=document.getElementById('separator-template');document.querySelector('[data-separators]').insertAdjacentHTML('beforeend',t.innerHTML);refreshSeparatorPositions();}if(target.matches('[data-remove]')){var parent=target.closest('label')||target.closest('[data-separators] > div');if(parent)parent.remove();}});";
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
