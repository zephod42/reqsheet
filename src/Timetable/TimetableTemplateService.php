<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;

final class TimetableTemplateService
{
    public function __construct(private readonly TimetableConfigurationStore $store)
    {
    }

    /** @param array<string, mixed> $settings */
    public function create(int $organisationId, ?int $sourceVersionId, ?string $label, ?string $effectiveFrom, array $settings): int
    {
        if (!$this->store->organisationExists($organisationId)) throw new TimetableValidationException(['Organisation does not exist.']);
        $name = trim((string) $label);
        if ($name === '') throw new TimetableValidationException(['Timetable name is required.']);
        if (mb_strlen($name) > 255) throw new TimetableValidationException(['Timetable name must be 255 characters or fewer.']);
        foreach ($this->store->versionsForOrganisation($organisationId) as $existing) {
            if (strcasecmp((string) $existing->label, $name) === 0) throw new TimetableValidationException(['A timetable with this name already exists for this organisation.']);
        }
        $days = array_values(array_unique(array_filter(array_map('intval', (array) ($settings['working_days'] ?? [])), static fn (int $day): bool => $day >= 1 && $day <= 7)));
        sort($days);
        if ($days === []) throw new TimetableValidationException(['Select at least one working day.']);
        $firstDay = (int) ($settings['first_day_of_week'] ?? 0);
        if ($firstDay < 1 || $firstDay > 7 || !in_array($firstDay, $days, true)) throw new TimetableValidationException(['The first day of the week must be one of the selected working days.']);
        $periods = (int) ($settings['periods_per_day'] ?? 0);
        if ($periods < 1 || $periods > 20) throw new TimetableValidationException(['Periods per day must be a positive whole number between 1 and 20.']);
        // Legacy effective columns remain available for historical records. New
        // manually activated templates use a non-scheduling compatibility date.
        $internalDate = trim((string) ($effectiveFrom ?? '')) === '' ? '1000-01-01' : $effectiveFrom;
        $versionId = $this->store->insertVersion($organisationId, $name, new DateTimeImmutable($internalDate), null, $firstDay);
        try {
            $this->seed($versionId, array_replace($settings, ['working_days' => $days, 'periods_per_day' => $periods]));
        } catch (\Throwable $exception) {
            // A version with no slots is not a usable template, so surface the
            // original validation/storage failure rather than hiding it.
            throw $exception;
        }
        return $versionId;
    }

    /** @return array<string, mixed>|null */
    public function activeTemplate(int $organisationId): ?array
    {
        $id = $this->store->activeVersionId($organisationId);
        if ($id === null) return null;
        $active = $this->store->findVersion($id);
        if ($active === null || $active->organisationId !== $organisationId) return null;
        return ['version' => $active, 'slots' => $this->store->slotsForVersion($active->id)];
    }

    public function activate(int $organisationId, int $versionId): void
    {
        $this->store->activateVersion($organisationId, $versionId);
    }

    /** @param array<string, mixed> $settings */
    private function seed(int $versionId, array $settings): void
    {
        $days = array_values(array_filter(array_map('intval', (array) ($settings['working_days'] ?? [1, 2, 3, 4, 5])), static fn (int $day): bool => $day >= 1 && $day <= 7));
        $periods = max(1, min(20, (int) ($settings['periods_per_day'] ?? 6)));
        $defaultStart = (string) ($settings['start_time'] ?? '08:00');
        if (!preg_match('/^\d{2}:\d{2}$/D', $defaultStart)) $defaultStart = '08:00';
        $defaultLength = max(1, (int) ($settings['standard_period_minutes'] ?? 60));
        $customDays = (array) ($settings['custom_day_settings'] ?? []);
        $separators = [];
        foreach ((array) ($settings['separators'] ?? []) as $separator) {
            $after = (int) ($separator['after_period'] ?? 0);
            if ($after >= 1 && $after < $periods) $separators[$after] = $separator;
        }
        $service = new TimetableSlotService($this->store);
        foreach ($days as $day) {
            $daySettings = is_array($customDays[(string) $day] ?? null) ? $customDays[(string) $day] : [];
            $start = (string) ($daySettings['start_time'] ?? '') ?: $defaultStart;
            $length = max(1, (int) (($daySettings['period_minutes'] ?? '') ?: $defaultLength));
            $clock = DateTimeImmutable::createFromFormat('!H:i', $start) ?: new DateTimeImmutable('08:00');
            $sequence = 1;
            for ($period = 1; $period <= $periods; $period++) {
                $end = $clock->modify('+' . $length . ' minutes');
                $service->create($versionId, $day, $sequence++, 'teaching', $period, 'P' . $period, $clock->format('H:i'), $end->format('H:i'));
                $clock = $end;
                if (!isset($separators[$period])) continue;
                $separator = $separators[$period];
                $type = (string) ($separator['type'] ?? 'Other');
                $kind = $type === 'Lunchtime' ? 'lunch' : ($type === 'Break' ? 'break' : 'non_teaching');
                $label = trim((string) ($separator['label'] ?? '')) ?: ($type === 'Lunchtime' ? 'Lunch' : $type);
                $minutes = max(1, (int) ($separator['duration_minutes'] ?? 15));
                $separatorEnd = $clock->modify('+' . $minutes . ' minutes');
                $service->create($versionId, $day, $sequence++, $kind, null, $label, $clock->format('H:i'), $separatorEnd->format('H:i'));
                $clock = $separatorEnd;
            }
        }
    }
}
