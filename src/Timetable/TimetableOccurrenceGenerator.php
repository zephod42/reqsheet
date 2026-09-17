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
        $slotsById = [];
        $slotsByDay = [];
        foreach ($slots as $slot) {
            $slotsById[$slot->id] = $slot;
            $slotsByDay[$slot->dayOfWeek][] = $slot;
        }
        foreach ($slotsByDay as &$daySlots) {
            usort($daySlots, static fn (TimetableSlot $left, TimetableSlot $right): int => $left->sequenceNumber <=> $right->sequenceNumber);
        }
        unset($daySlots);

        $lessons = $this->store->lessonsForVersion($version->id);
        $validated = [];
        $errors = [];
        foreach ($lessons as $lesson) {
            $lessonErrors = $this->validateLesson($lesson, $version, $slotsById, $slotsByDay);
            if ($lessonErrors !== []) {
                $errors = [...$errors, ...$lessonErrors];
                continue;
            }

            $teacherOrganisation = $this->store->findTeacherOrganisation($lesson->teacherUserId);
            if ($teacherOrganisation === null) {
                $errors[] = sprintf('Lesson %d references a missing teacher %d.', $lesson->id, $lesson->teacherUserId);
            } elseif ($teacherOrganisation !== $organisationId) {
                $errors[] = sprintf('Lesson %d teacher belongs to another organisation.', $lesson->id);
            }

            $validated[$lesson->id] = [
                'lesson' => $lesson,
                'slot' => $slotsById[$lesson->startSlotId],
                'sequences' => $this->occupiedSequences($slotsByDay[$lesson->dayOfWeek], $slotsById[$lesson->startSlotId], $lesson->durationPeriods),
            ];
        }

        $errors = [...$errors, ...$this->conflictErrors($validated)];
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

    /** @param array<int, TimetableSlot> $slotsById @param array<int, list<TimetableSlot>> $slotsByDay @return list<string> */
    private function validateLesson(RecurringLesson $lesson, TimetableVersion $version, array $slotsById, array $slotsByDay): array
    {
        $errors = [];
        if ($lesson->timetableVersionId !== $version->id) {
            $errors[] = sprintf('Lesson %d belongs to another timetable version.', $lesson->id);
        }
        if ($lesson->dayOfWeek < 1 || $lesson->dayOfWeek > 7) {
            $errors[] = sprintf('Lesson %d has an invalid day.', $lesson->id);
        }
        if ($lesson->durationPeriods < 1) {
            $errors[] = sprintf('Lesson %d has an invalid duration.', $lesson->id);
        }

        $startSlot = $slotsById[$lesson->startSlotId] ?? null;
        if ($startSlot === null) {
            $errors[] = sprintf('Lesson %d references a missing or wrong-version start slot.', $lesson->id);
            return $errors;
        }
        if ($startSlot->timetableVersionId !== $version->id) {
            $errors[] = sprintf('Lesson %d start slot belongs to another timetable version.', $lesson->id);
        }
        if ($startSlot->dayOfWeek !== $lesson->dayOfWeek) {
            $errors[] = sprintf('Lesson %d start slot is on the wrong day.', $lesson->id);
        }
        if (!$startSlot->isTeaching()) {
            $errors[] = sprintf('Lesson %d starts on a non-teaching slot.', $lesson->id);
        }

        if ($errors === []) {
            $this->occupiedSequences($slotsByDay[$lesson->dayOfWeek] ?? [], $startSlot, $lesson->durationPeriods, $lesson->id, $errors);
        }

        return $errors;
    }

    /** @param list<TimetableSlot> $daySlots @param list<string> &$errors @return list<int> */
    private function occupiedSequences(array $daySlots, TimetableSlot $startSlot, int $duration, ?int $lessonId = null, array &$errors = []): array
    {
        $startIndex = null;
        foreach ($daySlots as $index => $slot) {
            if ($slot->id === $startSlot->id) {
                $startIndex = $index;
                break;
            }
        }
        if ($startIndex === null) {
            if ($lessonId !== null) {
                $errors[] = sprintf('Lesson %d start slot is not in its configured timetable day.', $lessonId);
            }
            return [];
        }

        $sequences = [];
        for ($offset = 0; $offset < $duration; $offset++) {
            $slot = $daySlots[$startIndex + $offset] ?? null;
            $previous = $daySlots[$startIndex + $offset - 1] ?? null;
            if ($slot === null || ($previous !== null && $slot->sequenceNumber !== $previous->sequenceNumber + 1) || !$slot->isTeaching()) {
                if ($lessonId !== null) {
                    $errors[] = sprintf('Lesson %d duration crosses a break, non-teaching slot, or timetable day boundary.', $lessonId);
                }
                return [];
            }
            $sequences[] = $slot->sequenceNumber;
        }

        return $sequences;
    }

    /** @param array<int, array{lesson: RecurringLesson, slot: TimetableSlot, sequences: list<int>}> $validated @return list<string> */
    private function conflictErrors(array $validated): array
    {
        $errors = [];
        $teacherOccupancy = [];
        $roomOccupancy = [];
        foreach ($validated as $item) {
            $lesson = $item['lesson'];
            foreach ($item['sequences'] as $sequence) {
                $teacherKey = $lesson->teacherUserId . '/' . $lesson->dayOfWeek . '/' . $sequence;
                if (isset($teacherOccupancy[$teacherKey])) {
                    $errors[] = sprintf('Teacher conflict between lessons %d and %d.', $teacherOccupancy[$teacherKey], $lesson->id);
                } else {
                    $teacherOccupancy[$teacherKey] = $lesson->id;
                }

                $room = strtoupper(trim($lesson->roomCode));
                if ($room !== '') {
                    $roomKey = $room . '/' . $lesson->dayOfWeek . '/' . $sequence;
                    if (isset($roomOccupancy[$roomKey])) {
                        $errors[] = sprintf('Room conflict between lessons %d and %d.', $roomOccupancy[$roomKey], $lesson->id);
                    } else {
                        $roomOccupancy[$roomKey] = $lesson->id;
                    }
                }
            }
        }

        return $errors;
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
