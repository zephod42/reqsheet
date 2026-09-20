<?php

declare(strict_types=1);

namespace Reqsheet\Monitor;

final readonly class MonitorSnapshot
{
    /**
     * @param array{organisations:int,users:int,requisitions:int,timetable_versions:int} $totals
     * @param list<array{name:string,short_code:string,users:int,timetables:int,requisitions:int}> $schools
     * @param array{registrations_7:?int,registrations_30:?int,requisitions_created_7:?int,requisitions_created_30:?int,requisitions_modified_7:?int,requisitions_modified_30:?int} $activity
     * @param list<array{version:string,applied_at:?string}> $migrations
     */
    public function __construct(
        public array $totals,
        public array $schools,
        public array $activity,
        public array $migrations,
    ) {
    }
}
