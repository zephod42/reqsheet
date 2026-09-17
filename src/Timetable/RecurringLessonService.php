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

    public function update(int $lessonId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, string $classCode, string $roomCode): void
    {
        $existing = $this->store->findLesson($lessonId);
        if ($existing === null) {
            throw new TimetableValidationException(['Recurring lesson does not exist.']);
        }
        if ($this->store->occurrenceCountForLesson($lessonId) > 0) {
            throw new TimetableValidationException(['This lesson cannot be changed after historical occurrences have been generated.']);
        }
        $version = $this->store->findVersion($existing->timetableVersionId);
        if ($version === null) {
            throw new TimetableValidationException(['Timetable version does not exist.']);
        }
        $candidate = new RecurringLesson($lessonId, $existing->timetableVersionId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, $classCode, $roomCode);
        $lessons = array_values(array_filter(
            [...$this->store->lessonsForVersion($version->id)],
            static fn (RecurringLesson $lesson): bool => $lesson->id !== $lessonId,
        ));
        $result = TimetableRules::validateLessons(
            [...$lessons, $candidate],
            $version,
            $version->organisationId,
            $this->store->slotsForVersion($version->id),
            $this->store->findTeacherOrganisation(...),
        );
        $errors = array_values(array_unique($result['errors']));
        if ($errors !== []) {
            sort($errors);
            throw new TimetableValidationException($errors);
        }
        $this->store->updateLesson($lessonId, $teacherUserId, $dayOfWeek, $startSlotId, $durationPeriods, $classCode, $roomCode);
    }

    public function remove(int $lessonId): void
    {
        if ($this->store->findLesson($lessonId) === null) {
            throw new TimetableValidationException(['Recurring lesson does not exist.']);
        }
        if ($this->store->occurrenceCountForLesson($lessonId) > 0) {
            throw new TimetableValidationException(['This lesson cannot be removed after historical occurrences have been generated.']);
        }
        $this->store->deleteLesson($lessonId);
    }
}
