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

    public function insertVersion(
        int $organisationId,
        ?string $label,
        DateTimeImmutable $effectiveFrom,
        ?DateTimeImmutable $effectiveTo,
    ): int;

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

    public function insertLesson(
        int $versionId,
        int $teacherUserId,
        int $dayOfWeek,
        int $startSlotId,
        int $durationPeriods,
        string $classCode,
        string $roomCode,
    ): int;
}
