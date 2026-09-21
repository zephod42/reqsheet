<?php

declare(strict_types=1);

namespace Reqsheet\Date;

use DateTimeInterface;

final class DateDisplay
{
    public const DEFAULT_FORMAT = 'DD/MM/YYYY';
    public const FORMATS = [self::DEFAULT_FORMAT, 'MM/DD/YYYY', 'YYYY/MM/DD'];

    public function __construct(private readonly string $format = self::DEFAULT_FORMAT)
    {
    }

    public function format(DateTimeInterface $date): string
    {
        return match ($this->format) {
            'MM/DD/YYYY' => $date->format('m/d/Y'),
            'YYYY/MM/DD' => $date->format('Y/m/d'),
            default => $date->format('d/m/Y'),
        };
    }

    public function name(): string
    {
        return in_array($this->format, self::FORMATS, true) ? $this->format : self::DEFAULT_FORMAT;
    }
}
