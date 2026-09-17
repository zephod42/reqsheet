<?php

declare(strict_types=1);

namespace Reqsheet\Teacher;

use DateTimeImmutable;
use Reqsheet\Timetable\TimetableSlot;
use Reqsheet\Timetable\TimetableVersion;

final readonly class TeacherWeek
{
    /** @param list<array{date: DateTimeImmutable, version: ?TimetableVersion, slots: list<TimetableSlot>, occurrences: list<array<string, mixed>>}> $days */
    public function __construct(
        public DateTimeImmutable $start,
        public DateTimeImmutable $end,
        public array $days,
    ) {
    }
}
