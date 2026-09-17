<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class TeacherAccess
{
    /** @param array<string, mixed> $environment @param array<string, mixed> $server */
    public static function allowed(array $environment, array $server): bool
    {
        $key = $environment['REQSHEET_TEACHER_KEY'] ?? null;
        return is_string($key) && trim($key) !== ''
            && ($server['PHP_AUTH_USER'] ?? null) === 'teacher'
            && is_string($server['PHP_AUTH_PW'] ?? null)
            && hash_equals($key, $server['PHP_AUTH_PW']);
    }

    /** @param array<string, mixed> $environment */
    public static function configured(array $environment): bool
    {
        return self::teacherId($environment) > 0 && is_string($environment['REQSHEET_TEACHER_KEY'] ?? null)
            && trim($environment['REQSHEET_TEACHER_KEY']) !== '';
    }

    /** @param array<string, mixed> $environment */
    public static function teacherId(array $environment): int
    {
        $value = filter_var($environment['REQSHEET_TEACHER_ID'] ?? null, FILTER_VALIDATE_INT);
        return $value === false || $value < 1 ? 0 : $value;
    }

    /** @param array<string, mixed> $environment */
    public static function organisationId(array $environment): int
    {
        $value = filter_var($environment['REQSHEET_TEACHER_ORGANISATION_ID'] ?? null, FILTER_VALIDATE_INT);
        return $value === false || $value < 1 ? 0 : $value;
    }

    /** @param array<string, mixed> $environment */
    public static function firstDayOfWeek(array $environment): int
    {
        $value = filter_var($environment['REQSHEET_FIRST_DAY_OF_WEEK'] ?? 1, FILTER_VALIDATE_INT);
        return $value === false || $value < 1 || $value > 7 ? 1 : $value;
    }
}
