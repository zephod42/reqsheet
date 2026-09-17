<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

interface TimetableGenerationStore
{
    public function findVersion(int $organisationId, int $versionId): ?TimetableVersion;

    public function findTeacherOrganisation(int $teacherUserId): ?int;

    public function findSlot(int $slotId): ?TimetableSlot;

    /** @return list<TimetableSlot> */
    public function slotsForVersion(int $versionId): array;

    /** @return list<RecurringLesson> */
    public function lessonsForVersion(int $versionId): array;

    /** @return array<string, true> Keys are Y-m-d/lesson-id pairs. */
    public function existingOccurrenceKeys(int $organisationId, string $startDate, string $endDate): array;

    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function insertOccurrence(
        int $organisationId,
        RecurringLesson $lesson,
        TimetableSlot $startSlot,
        TimetableVersion $version,
        string $lessonDate,
    ): void;
}
