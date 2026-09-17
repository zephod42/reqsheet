<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final readonly class TimetableSlot
{
    public function __construct(
        public int $id,
        public int $timetableVersionId,
        public int $dayOfWeek,
        public int $sequenceNumber,
        public string $kind,
        public ?int $teachingPeriodNumber,
    ) {
    }

    public function isTeaching(): bool
    {
        return $this->kind === 'teaching';
    }
}
