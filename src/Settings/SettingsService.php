<?php

declare(strict_types=1);

namespace Reqsheet\Settings;

use DateTimeImmutable;
use Reqsheet\Date\DateDisplay;

final class SettingsService
{
    private const DAYS = [1, 2, 3, 4, 5, 6, 7];
    private const SEPARATOR_TYPES = ['Break', 'Lunchtime', 'Other'];

    public function __construct(private readonly OrganisationSettingsStore $store)
    {
    }

    /** @return array<string, mixed> */
    public function load(int $organisationId): array
    {
        $settings = $this->store->find($organisationId);
        if (trim((string) ($settings['start_time'] ?? '')) === '') $settings['start_time'] = '08:00';
        if (!in_array((string) ($settings['date_format'] ?? ''), DateDisplay::FORMATS, true)) $settings['date_format'] = DateDisplay::DEFAULT_FORMAT;
        return $settings;
    }

    /** @param array<string, mixed> $input */
    public function save(int $organisationId, array $input): void
    {
        $errors = [];
        $schoolName = trim((string) ($input['school_name'] ?? ''));
        if ($schoolName === '') $errors[] = 'School name must not be blank.';
        $days = array_values(array_unique(array_map('intval', is_array($input['working_days'] ?? null) ? $input['working_days'] : [])));
        sort($days);
        if ($days === [] || array_diff($days, self::DAYS) !== []) $errors[] = 'Select at least one valid working day.';
        $firstDay = (int) ($input['first_day_of_week'] ?? 0);
        if (!in_array($firstDay, $days, true)) $errors[] = 'The first day of the working week must be a working day.';
        $periods = (int) ($input['periods_per_day'] ?? 0);
        if ($periods < 1 || $periods > 20) $errors[] = 'Periods per day must be between 1 and 20.';

        $startTime = trim((string) ($input['start_time'] ?? ''));
        if ($startTime !== '' && !$this->validTime($startTime)) $errors[] = 'Start time is invalid.';
        $periodLength = trim((string) ($input['standard_period_minutes'] ?? ''));
        if ($periodLength !== '' && ((int) $periodLength < 1 || (string) (int) $periodLength !== $periodLength)) $errors[] = 'Standard period length must be a positive number of minutes.';

        $current = $this->store->find($organisationId);
        $dateFormat = array_key_exists('date_format', $input)
            ? trim((string) $input['date_format'])
            : (string) ($current['date_format'] ?? DateDisplay::DEFAULT_FORMAT);
        if (!in_array($dateFormat, DateDisplay::FORMATS, true)) $errors[] = 'Date format is invalid.';

        $rooms = null;
        if (array_key_exists('rooms', $input)) {
            $rooms = [];
            foreach ((array) $input['rooms'] as $room) {
                $room = trim((string) $room);
                if ($room !== '' && !in_array(strtolower($room), array_map('strtolower', $rooms), true)) $rooms[] = $room;
            }
        }

        // Keep legacy per-day values intact when the simplified settings form
        // is saved. Existing timetable slots remain authoritative for history.
        $customDays = (array) ($this->store->find($organisationId)['custom_day_settings'] ?? []);
        if (array_key_exists('custom_day_start', $input) || array_key_exists('custom_day_length', $input)) {
            $customDays = [];
            foreach (self::DAYS as $day) {
                $customStart = trim((string) (($input['custom_day_start'] ?? [])[$day] ?? ''));
                $customLength = trim((string) (($input['custom_day_length'] ?? [])[$day] ?? ''));
                if ($customStart === '' && $customLength === '') continue;
                if ($customStart !== '' && !$this->validTime($customStart)) $errors[] = 'Custom day start times must be valid.';
                if ($customLength !== '' && (int) $customLength < 1) $errors[] = 'Custom day period lengths must be positive.';
                $customDays[(string) $day] = ['start_time' => $customStart, 'period_minutes' => $customLength];
            }
        }

        $separators = [];
        $types = (array) ($input['separator_type'] ?? []);
        $after = (array) ($input['separator_after'] ?? []);
        $duration = (array) ($input['separator_duration'] ?? []);
        $labels = (array) ($input['separator_label'] ?? []);
        foreach ($types as $index => $type) {
            $type = trim((string) $type);
            $period = (int) ($after[$index] ?? 0);
            $minutes = trim((string) ($duration[$index] ?? ''));
            $label = trim((string) ($labels[$index] ?? ''));
            if ($type === '' && $period === 0 && $minutes === '') continue;
            if (!in_array($type, self::SEPARATOR_TYPES, true)) $errors[] = 'Separator type is invalid.';
            if ($period < 1 || $period >= $periods) $errors[] = 'Separators must be placed between valid periods.';
            if ($minutes !== '' && (int) $minutes < 1) $errors[] = 'Separator duration must be positive when supplied.';
            $separators[] = ['type' => $type, 'label' => $label, 'after_period' => $period, 'duration_minutes' => $minutes];
        }
        if ($errors !== []) throw new SettingsValidationException(array_values(array_unique($errors)));

        $this->store->save($organisationId, [
            'school_name' => $schoolName, 'working_days' => $days, 'first_day_of_week' => $firstDay,
            'periods_per_day' => $periods, 'start_time' => $startTime,
            'standard_period_minutes' => $periodLength, 'custom_day_settings' => $customDays,
            'separators' => $separators, 'allow_double_periods' => isset($input['allow_conjoined_periods']) || isset($input['allow_double_periods']),
            'date_format' => $dateFormat,
        ], $rooms);
    }

    private function validTime(string $value): bool
    {
        $time = DateTimeImmutable::createFromFormat('!H:i', $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $time->format('H:i') === $value;
    }
}
