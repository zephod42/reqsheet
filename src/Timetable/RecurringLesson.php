<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final readonly class RecurringLesson
{
    public function __construct(
        public int $id,
        public int $timetableVersionId,
        public int $teacherUserId,
        public int $dayOfWeek,
        public int $startSlotId,
        public int $durationPeriods,
        public string $classCode,
        public string $roomCode,
        public ?int $classId = null,
        public ?int $roomId = null,
    ) {
    }
}
