<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

interface TimetableCsvImportStore extends ResourceTimetableStore
{
    /** Begins an import transaction and locks the source, activation state, and resources. */
    public function beginCsvImport(int $organisationId, int $versionId): bool;

    /** @return array{id:int,label:string,slot_map:array<int,int>} */
    public function createCsvImportVersion(int $organisationId, int $sourceVersionId, string $requestedLabel): array;

    /** @return array{id:int,created:bool} */
    public function ensureCsvImportClass(int $organisationId, string $code): array;

    public function activateCsvImportVersion(int $organisationId, int $versionId): void;

    public function insertCsvImportLesson(
        int $organisationId,
        int $versionId,
        int $teacherUserId,
        int $dayOfWeek,
        int $startSlotId,
        int $durationPeriods,
        int $classId,
        int $roomId,
    ): int;

    public function commitCsvImport(): void;

    public function rollbackCsvImport(): void;
}
