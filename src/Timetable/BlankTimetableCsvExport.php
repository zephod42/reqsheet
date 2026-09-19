<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final readonly class BlankTimetableCsvExport
{
    public function __construct(public string $filename, public string $content, public int $rowCount) {}
}
