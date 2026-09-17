<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

use DateTimeImmutable;

final readonly class TimetableVersion
{
    public function __construct(
        public int $id,
        public int $organisationId,
        public ?string $label,
        public DateTimeImmutable $effectiveFrom,
        public ?DateTimeImmutable $effectiveTo,
    ) {
    }
}
