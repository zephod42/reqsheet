<?php

declare(strict_types=1);

namespace Reqsheet\Timetable;

final class ClassTone
{
    public const COUNT = 8;

    public static function forCode(string $classCode): int
    {
        return abs(crc32($classCode)) % self::COUNT;
    }
}
