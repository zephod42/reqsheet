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
    public function create(int $organisationId, ?int $sourceVersionId, ?string $label, string $effectiveFrom, array $settings): int
    {
        $versionService = new TimetableVersionService($this->store);
        $versionId = $sourceVersionId === null
            ? $versionService->create($organisationId, $label, $effectiveFrom, null)
            : $versionService->createSuccessor($organisationId, $sourceVersionId, $label, $effectiveFrom);
        try {
            $this->seed($versionId, $settings);
        } catch (\Throwable $exception) {
            // A version with no slots is not a usable template, so surface the
            // original validation/storage failure rather than hiding it.
            throw $exception;
        }
        return $versionId;
    }

    /** @return array<string, mixed>|null */
    public function activeTemplate(int $organisationId, ?DateTimeImmutable $date = null): ?array
    {
        $date ??= new DateTimeImmutable('today');
        $active = null;
        foreach ($this->store->versionsForOrganisation($organisationId) as $version) {
            if ($version->effectiveFrom > $date || ($version->effectiveTo !== null && $date >= $version->effectiveTo)) continue;
            if ($active === null || $version->effectiveFrom > $active->effectiveFrom || ($version->effectiveFrom == $active->effectiveFrom && $version->id > $active->id)) $active = $version;
        }
        if ($active === null) return null;
        return ['version' => $active, 'slots' => $this->store->slotsForVersion($active->id)];
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
