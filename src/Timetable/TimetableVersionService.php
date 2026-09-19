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

    public function createSuccessor(int $organisationId, int $sourceVersionId, ?string $label, string $effectiveFrom): int
    {
        $source = $this->store->findVersion($sourceVersionId);
        if ($source === null || $source->organisationId !== $organisationId) {
            throw new TimetableValidationException(['The source timetable template is not available for this organisation.']);
        }
        $from = self::date($effectiveFrom, 'effective-from');
        if ($from <= $source->effectiveFrom || ($source->effectiveTo !== null && $from >= $source->effectiveTo)) {
            throw new TimetableValidationException(['A successor template must start inside the current template period, after its start date.']);
        }
        if ($label !== null && trim($label) === '') {
            throw new TimetableValidationException(['Timetable version label must not be blank.']);
        }
        if ($this->store->occurrenceCountForVersionFrom($source->id, $from) > 0) {
            throw new TimetableValidationException(['This template already has historical lesson occurrences on or after the successor date; create the successor after the protected historical range.']);
        }
        foreach ($this->store->versionsForOrganisation($organisationId) as $existing) {
            if ($existing->id === $source->id) continue;
            $existingEndsAfterNewStart = $existing->effectiveTo === null || $existing->effectiveTo > $from;
            $newEndsAfterExistingStart = $source->effectiveTo === null || $source->effectiveTo > $existing->effectiveFrom;
            if ($existingEndsAfterNewStart && $newEndsAfterExistingStart) {
                throw new TimetableValidationException(['The successor template overlaps another timetable version.']);
            }
        }

        return $this->store->createSuccessorVersion($organisationId, $source->id, $label, $from);
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
