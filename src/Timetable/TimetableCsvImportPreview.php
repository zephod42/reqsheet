<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final readonly class TimetableCsvImportPreview
{
    /**
     * @param list<array{teacher_id:int,teacher_code:string,teacher_name:string,class_id:int,class_code:string,room_id:int,room_code:string,day_of_week:int,day:string,start_slot_id:int,periods:list<string>,duration:int,row_numbers:list<int>}> $assignments
     */
    public function __construct(
        public int $organisationId,
        public int $versionId,
        public string $versionName,
        public int $occupiedPeriods,
        public int $freeSlots,
        public array $assignments,
        public string $structureIdentity,
    ) {}
}
