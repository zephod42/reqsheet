<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final readonly class TimetableCsvImportPreview
{
    /**
     * @param list<array{teacher_id:int,teacher_code:string,teacher_name:string,class_id:int,class_code:string,room_id:int,room_code:string,day_of_week:int,day:string,start_slot_id:int,periods:list<string>,duration:int,row_numbers:list<int>}> $assignments
     * @param list<array{code:string,row_count:int}> $skippedRooms
     * @param list<string> $newClassCodes
     */
    public function __construct(
        public int $organisationId,
        public int $versionId,
        public string $versionName,
        public int $occupiedPeriods,
        public int $freeSlots,
        public array $assignments,
        public string $structureIdentity,
        public string $proposedVersionName = '',
        public array $skippedRooms = [],
        public array $newClassCodes = [],
    ) {}

    public static function proposedName(string $sourceName, ?\DateTimeImmutable $now = null): string
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $base = trim($sourceName) !== '' ? trim($sourceName) : 'Timetable';
        $suffix = '_imported_' . $now->format('Ymd_His');
        return mb_substr($base, 0, 255 - mb_strlen($suffix)) . $suffix;
    }
}
