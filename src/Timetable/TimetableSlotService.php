<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;

final class TimetableSlotService
{
    private const KINDS = ['teaching', 'break', 'lunch', 'non_teaching'];

    public function __construct(private readonly TimetableConfigurationStore $store)
    {
    }

    public function create(
        int $versionId,
        int $dayOfWeek,
        int $sequenceNumber,
        string $kind,
        ?int $teachingPeriodNumber,
        string $label,
        string $startsAt,
        string $endsAt,
    ): int {
        $version = $this->store->findVersion($versionId);
        if ($version === null) {
            throw new TimetableValidationException(['Timetable version does not exist.']);
        }
        $errors = [];
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            $errors[] = 'Day of week must be between 1 and 7.';
        }
        if ($sequenceNumber < 1) {
            $errors[] = 'Sequence number must be positive.';
        }
        if (!in_array($kind, self::KINDS, true)) {
            $errors[] = 'Slot kind is invalid.';
        }
        if (trim($label) === '') {
            $errors[] = 'Slot label must not be blank.';
        }
        if ($kind === 'teaching' && ($teachingPeriodNumber === null || $teachingPeriodNumber < 1)) {
            $errors[] = 'Teaching slots require a positive teaching period number.';
        }
        if ($kind !== 'teaching' && $teachingPeriodNumber !== null) {
            $errors[] = 'Non-teaching slots must not have a teaching period number.';
        }
        $start = self::time($startsAt, 'start');
        $end = self::time($endsAt, 'end');
        if ($start !== null && $end !== null && $start >= $end) {
            $errors[] = 'Slot start time must be before end time.';
        }

        $daySlots = array_values(array_filter($this->store->slotsForVersion($versionId), static fn (TimetableSlot $slot): bool => $slot->dayOfWeek === $dayOfWeek));
        foreach ($daySlots as $slot) {
            if ($slot->sequenceNumber === $sequenceNumber) {
                $errors[] = 'Sequence number is already used on this day.';
            }
            if ($kind === 'teaching' && $slot->teachingPeriodNumber === $teachingPeriodNumber) {
                $errors[] = 'Teaching period number is already used on this day.';
            }
            if ($start !== null && $end !== null && $slot->startsAt !== null && $slot->endsAt !== null) {
                if ($start < $slot->endsAt && $end > $slot->startsAt) {
                    $errors[] = 'Slot times overlap an existing slot on this day.';
                }
                if ($sequenceNumber > $slot->sequenceNumber && $start < $slot->startsAt) {
                    $errors[] = 'Slot sequence and chronological order are inconsistent.';
                }
                if ($sequenceNumber < $slot->sequenceNumber && $start > $slot->startsAt) {
                    $errors[] = 'Slot sequence and chronological order are inconsistent.';
                }
            }
        }
        if ($errors !== []) {
            sort($errors);
            throw new TimetableValidationException(array_values(array_unique($errors)));
        }

        return $this->store->insertSlot($versionId, $dayOfWeek, $sequenceNumber, $kind, $teachingPeriodNumber, $label, $start, $end);
    }

    private static function time(string $value, string $label): ?string
    {
        foreach (['H:i:s', 'H:i'] as $format) {
            $time = DateTimeImmutable::createFromFormat('!' . $format, $value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($time !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0)) && $time->format($format) === $value) {
                return $time->format('H:i:s');
            }
        }
        throw new TimetableValidationException([sprintf('Invalid %s time.', $label)]);
    }
}
