<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class RecurringLessonService
{
    public function __construct(private readonly TimetableConfigurationStore $store)
    {
    }

    public function create(
        int $versionId,
        int $teacherUserId,
        int $dayOfWeek,
        int $startSlotId,
        int $durationPeriods,
        string $classCode,
        string $roomCode,
    ): int {
        $version = $this->store->findVersion($versionId);
        if ($version === null) {
            throw new TimetableValidationException(['Timetable version does not exist.']);
        }
        if ($dayOfWeek < 1 || $dayOfWeek > 7) {
            throw new TimetableValidationException(['Day of week must be between 1 and 7.']);
        }
        if ($durationPeriods < 1) {
            throw new TimetableValidationException(['Lesson duration must be positive.']);
        }
        if (trim($classCode) === '') {
            throw new TimetableValidationException(['Class code must not be blank.']);
        }
        if (trim($roomCode) === '') {
            throw new TimetableValidationException(['Room code must not be blank.']);
        }

        $candidate = new RecurringLesson(0, $versionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, $classCode, $roomCode);
        $lessons = [...$this->store->lessonsForVersion($versionId), $candidate];
        $result = TimetableRules::validateLessons(
            $lessons,
            $version,
            $version->organisationId,
            $this->store->slotsForVersion($versionId),
            $this->store->findTeacherOrganisation(...),
        );
        $errors = array_values(array_unique($result['errors']));
        if ($errors !== []) {
            sort($errors);
            throw new TimetableValidationException($errors);
        }

        return $this->store->insertLesson($versionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, $classCode, $roomCode);
    }
}
