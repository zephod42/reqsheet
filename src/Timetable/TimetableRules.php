<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class TimetableRules
{
    /**
     * @param list<TimetableSlot> $slots
     * @param list<RecurringLesson> $lessons
     * @param callable(int): (?int) $teacherOrganisation
     * @return array{validated: array<int, array{lesson: RecurringLesson, slot: TimetableSlot, sequences: list<int>}>, errors: list<string>}
     */
    public static function validateLessons(
        array $lessons,
        TimetableVersion $version,
        int $organisationId,
        array $slots,
        callable $teacherOrganisation,
    ): array {
        [$slotsById, $slotsByDay] = self::indexSlots($slots);
        $validated = [];
        $errors = [];
        $teacherOrganisations = [];
        $cachedTeacherOrganisation = static function (int $teacherId) use ($teacherOrganisation, &$teacherOrganisations): ?int {
            if (!array_key_exists($teacherId, $teacherOrganisations)) {
                $teacherOrganisations[$teacherId] = $teacherOrganisation($teacherId);
            }
            return $teacherOrganisations[$teacherId];
        };

        foreach ($lessons as $lesson) {
            $lessonErrors = self::validateLesson($lesson, $version, $organisationId, $slotsById, $slotsByDay, $cachedTeacherOrganisation);
            if ($lessonErrors !== []) {
                $errors = [...$errors, ...$lessonErrors];
                continue;
            }

            $validated[$lesson->id] = [
                'lesson' => $lesson,
                'slot' => $slotsById[$lesson->startSlotId],
                'sequences' => self::occupiedSequences($slotsByDay[$lesson->dayOfWeek], $slotsById[$lesson->startSlotId], $lesson->durationPeriods),
            ];
        }

        return ['validated' => $validated, 'errors' => [...$errors, ...self::conflictErrors($validated)]];
    }

    /** @param list<TimetableSlot> $slots @return array{array<int, TimetableSlot>, array<int, list<TimetableSlot>>} */
    public static function indexSlots(array $slots): array
    {
        $byId = [];
        $byDay = [];
        foreach ($slots as $slot) {
            $byId[$slot->id] = $slot;
            $byDay[$slot->dayOfWeek][] = $slot;
        }
        foreach ($byDay as &$daySlots) {
            usort($daySlots, static fn (TimetableSlot $left, TimetableSlot $right): int => $left->sequenceNumber <=> $right->sequenceNumber);
        }
        unset($daySlots);

        return [$byId, $byDay];
    }

    /** @param array<int, TimetableSlot> $slotsById @param array<int, list<TimetableSlot>> $slotsByDay @param callable(int): (?int) $teacherOrganisation @return list<string> */
    private static function validateLesson(
        RecurringLesson $lesson,
        TimetableVersion $version,
        int $organisationId,
        array $slotsById,
        array $slotsByDay,
        callable $teacherOrganisation,
    ): array {
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
        if (trim($lesson->classCode) === '') {
            $errors[] = sprintf('Lesson %d class code must not be blank.', $lesson->id);
        }
        if (trim($lesson->roomCode) === '') {
            $errors[] = sprintf('Lesson %d room code must not be blank.', $lesson->id);
        }

        $teacherOrg = $teacherOrganisation($lesson->teacherUserId);
        if ($teacherOrg === null) {
            $errors[] = sprintf('Lesson %d references a missing teacher %d.', $lesson->id, $lesson->teacherUserId);
        } elseif ($teacherOrg !== $organisationId) {
            $errors[] = sprintf('Lesson %d teacher belongs to another organisation.', $lesson->id);
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
            $sequences = self::occupiedSequences($slotsByDay[$lesson->dayOfWeek] ?? [], $startSlot, $lesson->durationPeriods);
            if (count($sequences) !== $lesson->durationPeriods) {
                $errors[] = sprintf('Lesson %d duration crosses a break, non-teaching slot, or timetable day boundary.', $lesson->id);
            }
        }

        return $errors;
    }

    /** @param list<TimetableSlot> $daySlots @return list<int> */
    public static function occupiedSequences(array $daySlots, TimetableSlot $startSlot, ?int $duration = null): array
    {
        $startIndex = null;
        foreach ($daySlots as $index => $slot) {
            if ($slot->id === $startSlot->id) {
                $startIndex = $index;
                break;
            }
        }
        if ($startIndex === null) {
            return [];
        }

        $sequences = [];
        $limit = $duration ?? PHP_INT_MAX;
        for ($offset = 0; $offset < $limit; $offset++) {
            $slot = $daySlots[$startIndex + $offset] ?? null;
            $previous = $daySlots[$startIndex + $offset - 1] ?? null;
            if ($slot === null || ($previous !== null && $slot->sequenceNumber !== $previous->sequenceNumber + 1) || !$slot->isTeaching()) {
                break;
            }
            $sequences[] = $slot->sequenceNumber;
        }

        return $sequences;
    }

    /** @param array<int, array{lesson: RecurringLesson, slot: TimetableSlot, sequences: list<int>}> $validated @return list<string> */
    private static function conflictErrors(array $validated): array
    {
        $errors = [];
        $teacherOccupancy = [];
        $roomOccupancy = [];
        $classOccupancy = [];
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

                $class = strtoupper(trim($lesson->classCode));
                if ($class !== '') {
                    $classKey = $class . '/' . $lesson->dayOfWeek . '/' . $sequence;
                    if (isset($classOccupancy[$classKey])) {
                        $errors[] = sprintf('Class conflict between lessons %d and %d.', $classOccupancy[$classKey], $lesson->id);
                    } else {
                        $classOccupancy[$classKey] = $lesson->id;
                    }
                }
            }
        }

        return $errors;
    }
}
