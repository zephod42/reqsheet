<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final readonly class TimetableCsvImportResult
{
    public function __construct(
        public int $versionId,
        public int $lessonCount,
        public int $occupiedPeriods,
        public int $freeSlots,
        public string $versionName = '',
        /** @var list<string> */
        public array $createdClassCodes = [],
        /** @var list<array{code:string,row_count:int}> */
        public array $skippedRooms = [],
    ) {}
}
