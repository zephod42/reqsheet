<?php

declare(strict_types=1);

namespace Reqsheet\Teacher;

use DateTimeImmutable;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;

interface TeacherPlanningStore
{
    public function teacherBelongsToOrganisation(int $teacherId, int $organisationId): bool;

    /** @return list<array{id:int,code:string}> */
    public function classesForTeacher(int $organisationId, int $teacherId): array;

    public function effectiveVersion(int $organisationId, DateTimeImmutable $date): ?TimetableVersion;

    public function activeFirstDayOfWeek(int $organisationId): int;

    public function ensureOccurrencesForWeek(int $organisationId, DateTimeImmutable $start, DateTimeImmutable $end): void;

    public function ensureOccurrencesForRange(
        int $organisationId,
        DateTimeImmutable $start,
        DateTimeImmutable $end,
        ?int $teacherId = null,
        ?int $classId = null,
    ): void;

    /** @return list<TimetableSlot> */
    public function slotsForVersion(int $versionId): array;

    /** @return list<array<string, mixed>> */
    public function occurrencesForTeacherDate(int $organisationId, int $teacherId, DateTimeImmutable $date): array;

    /** @return list<array<string,mixed>> */
    public function occurrencesForTeacherClass(int $organisationId, int $teacherId, int $classId, DateTimeImmutable $start, DateTimeImmutable $end, int $limit, bool $descending = false): array;

    /** @return array<string, mixed>|null */
    public function findOccurrenceForTeacher(int $organisationId, int $teacherId, int $occurrenceId): ?array;

    public function savePlanning(
        int $occurrenceId,
        string $state,
        string $lessonOutline,
        string $requisitions,
        string $riskAssessment,
    ): void;
}
