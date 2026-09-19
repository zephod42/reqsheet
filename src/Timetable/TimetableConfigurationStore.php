<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;

interface TimetableConfigurationStore
{
    public function organisationExists(int $organisationId): bool;

    public function findVersion(int $versionId): ?TimetableVersion;

    /** @return list<TimetableVersion> */
    public function versionsForOrganisation(int $organisationId): array;

    public function activeVersionId(int $organisationId): ?int;

    public function activateVersion(int $organisationId, int $versionId): void;

    /** @return list<array{id: int, display_name: string, staff_identifier: ?string, is_active: bool}> */
    public function usersForOrganisation(int $organisationId): array;

    /** @return list<string> */
    public function roomCodesForVersion(int $versionId): array;

    public function insertVersion(
        int $organisationId,
        ?string $label,
        DateTimeImmutable $effectiveFrom,
        ?DateTimeImmutable $effectiveTo,
        int $firstDayOfWeek = 1,
    ): int;

    public function createSuccessorVersion(
        int $organisationId,
        int $sourceVersionId,
        ?string $label,
        DateTimeImmutable $effectiveFrom,
    ): int;

    public function occurrenceCountForVersionFrom(int $versionId, DateTimeImmutable $date): int;

    /** @return list<TimetableSlot> */
    public function slotsForVersion(int $versionId): array;

    public function insertSlot(
        int $versionId,
        int $dayOfWeek,
        int $sequenceNumber,
        string $kind,
        ?int $teachingPeriodNumber,
        string $label,
        string $startsAt,
        string $endsAt,
    ): int;

    public function findTeacherOrganisation(int $teacherUserId): ?int;

    /** @return list<RecurringLesson> */
    public function lessonsForVersion(int $versionId): array;

    public function findLesson(int $lessonId): ?RecurringLesson;

    public function occurrenceCountForLesson(int $lessonId): int;

    public function insertLesson(
        int $versionId,
        int $teacherUserId,
        int $dayOfWeek,
        int $startSlotId,
        int $durationPeriods,
        string $classCode,
        string $roomCode,
    ): int;

    public function updateLesson(
        int $lessonId,
        int $teacherUserId,
        int $dayOfWeek,
        int $startSlotId,
        int $durationPeriods,
        string $classCode,
        string $roomCode,
    ): void;

    public function deleteLesson(int $lessonId): void;
}

interface EditableTimetableConfigurationStore extends TimetableConfigurationStore
{
    /** @param list<array{day:int,sequence:int,kind:string,period:?int,label:string,starts_at:string,ends_at:string}> $slots */
    public function updateVersion(int $organisationId, int $versionId, string $label, int $firstDayOfWeek, array $slots): void;
}
