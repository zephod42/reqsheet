<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

interface TimetableCsvImportStore extends ResourceTimetableStore
{
    /**
     * Begins an import transaction, locks the organisation-owned version and all
     * resources used by validation, and proves the version is still empty.
     * Returns the current organisation setting for conjoined periods.
     */
    public function beginCsvImport(int $organisationId, int $versionId): bool;

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
