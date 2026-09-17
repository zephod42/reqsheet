<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final readonly class GenerationResult
{
    public function __construct(
        public int $generated,
        public int $skippedExisting,
    ) {
    }
}
