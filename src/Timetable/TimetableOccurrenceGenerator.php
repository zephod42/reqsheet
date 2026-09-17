<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateInterval;
use DateTimeImmutable;

final class TimetableOccurrenceGenerator
{
    public function __construct(private readonly TimetableGenerationStore $store)
    {
    }

    /**
     * Generate occurrences for an inclusive calendar-date range.
     */
    public function generate(
        int $organisationId,
        int $versionId,
        string $startDate,
        string $endDate,
    ): GenerationResult {
        $requestedStart = self::parseDate($startDate, 'start date');
        $requestedEnd = self::parseDate($endDate, 'end date');
        if ($requestedStart > $requestedEnd) {
            throw new TimetableValidationException(['Start date must not be after end date.']);
        }

        $version = $this->store->findVersion($organisationId, $versionId);
        if ($version === null) {
            throw new TimetableValidationException(['Timetable version does not belong to the organisation.']);
        }

        $generationStart = $requestedStart > $version->effectiveFrom ? $requestedStart : $version->effectiveFrom;
        $generationEnd = $requestedEnd;
        if ($version->effectiveTo !== null) {
            $lastEffectiveDate = $version->effectiveTo->sub(new DateInterval('P1D'));
            $generationEnd = $generationEnd < $lastEffectiveDate ? $generationEnd : $lastEffectiveDate;
        }

        if ($generationStart > $generationEnd) {
            throw new TimetableValidationException(['Generation dates do not intersect the timetable version effective range.']);
        }

        $slots = $this->store->slotsForVersion($version->id);
        $lessons = $this->store->lessonsForVersion($version->id);
        $validation = TimetableRules::validateLessons(
            $lessons,
            $version,
            $organisationId,
            $slots,
            $this->store->findTeacherOrganisation(...),
        );
        $validated = $validation['validated'];
        $errors = $validation['errors'];
        if ($errors !== []) {
            sort($errors);
            throw new TimetableValidationException(array_values(array_unique($errors)));
        }

        $this->store->begin();
        try {
            $existing = $this->store->existingOccurrenceKeys(
                $organisationId,
                $generationStart->format('Y-m-d'),
                $generationEnd->format('Y-m-d'),
            );
            $generated = 0;
            $skipped = 0;
            for ($date = $generationStart; $date <= $generationEnd; $date = $date->add(new DateInterval('P1D'))) {
                $dayOfWeek = (int) $date->format('N');
                foreach ($validated as $item) {
                    /** @var RecurringLesson $lesson */
                    $lesson = $item['lesson'];
                    if ($lesson->dayOfWeek !== $dayOfWeek) {
                        continue;
                    }

                    $key = $date->format('Y-m-d') . '/' . $lesson->id;
                    if (isset($existing[$key])) {
                        $skipped++;
                        continue;
                    }

                    $this->store->insertOccurrence(
                        $organisationId,
                        $lesson,
                        $item['slot'],
                        $version,
                        $date->format('Y-m-d'),
                    );
                    $existing[$key] = true;
                    $generated++;
                }
            }
            $this->store->commit();
        } catch (\Throwable $exception) {
            $this->store->rollBack();
            throw $exception;
        }

        return new GenerationResult($generated, $skipped);
    }

    private static function parseDate(string $value, string $label): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            throw new TimetableValidationException([sprintf('Invalid %s.', $label)]);
        }

        return $date;
    }
}
