<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

interface ResourceTimetableStore extends TimetableConfigurationStore
{
    /** @return list<array{id:int,code:string}> */
    public function roomsForOrganisation(int $organisationId): array;

    /** @return list<array{id:int,code:string}> */
    public function classesForOrganisation(int $organisationId): array;

    public function createRoom(int $organisationId, string $code): int;

    public function createClass(int $organisationId, string $code): int;

    public function createTeacher(int $organisationId, string $code, string $displayName): int;

    public function roomBelongsToOrganisation(int $roomId, int $organisationId): bool;

    public function classBelongsToOrganisation(int $classId, int $organisationId): bool;

    public function roomCode(int $roomId): ?string;

    public function classCode(int $classId): ?string;

    public function insertResourceLesson(int $versionId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, int $classId, int $roomId): int;

    public function updateResourceLesson(int $lessonId, int $teacherUserId, int $dayOfWeek, int $startSlotId, int $durationPeriods, int $classId, int $roomId): void;
}
