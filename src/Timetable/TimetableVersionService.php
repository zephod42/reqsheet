<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;

final class TimetableVersionService
{
    public function __construct(private readonly TimetableConfigurationStore $store)
    {
    }

    public function create(int $organisationId, ?string $label, string $effectiveFrom, ?string $effectiveTo): int
    {
        if (!$this->store->organisationExists($organisationId)) {
            throw new TimetableValidationException(['Organisation does not exist.']);
        }
        $from = self::date($effectiveFrom, 'effective-from');
        $to = $effectiveTo === null ? null : self::date($effectiveTo, 'effective-to');
        if ($to !== null && $to <= $from) {
            throw new TimetableValidationException(['effective-to must be after effective-from.']);
        }
        if ($label !== null && trim($label) === '') {
            throw new TimetableValidationException(['Timetable version label must not be blank.']);
        }

        foreach ($this->store->versionsForOrganisation($organisationId) as $existing) {
            $existingEndsAfterNewStart = $existing->effectiveTo === null || $existing->effectiveTo > $from;
            $newEndsAfterExistingStart = $to === null || $to > $existing->effectiveFrom;
            if ($existingEndsAfterNewStart && $newEndsAfterExistingStart) {
                throw new TimetableValidationException(['Timetable version effective dates overlap an existing version.']);
            }
        }

        return $this->store->insertVersion($organisationId, $label, $from, $to);
    }

    private static function date(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new TimetableValidationException([sprintf('Invalid %s date.', $label)]);
        }

        return $date;
    }
}
