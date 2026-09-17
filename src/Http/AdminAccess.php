<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class AdminAccess
{
    /** @param array<string, mixed> $environment @param array<string, mixed> $server */
    public static function allowed(array $environment, array $server): bool
    {
        $key = $environment['REQSHEET_ADMIN_KEY'] ?? null;
        return is_string($key) && $key !== ''
            && ($server['PHP_AUTH_USER'] ?? null) === 'admin'
            && is_string($server['PHP_AUTH_PW'] ?? null)
            && hash_equals($key, $server['PHP_AUTH_PW']);
    }

    /** @param array<string, mixed> $environment */
    public static function configured(array $environment): bool
    {
        return is_string($environment['REQSHEET_ADMIN_KEY'] ?? null)
            && trim($environment['REQSHEET_ADMIN_KEY']) !== '';
    }

    /** @param array<string, mixed> $environment */
    public static function organisationId(array $environment): int
    {
        $value = filter_var($environment['REQSHEET_ADMIN_ORGANISATION_ID'] ?? null, FILTER_VALIDATE_INT);
        return $value === false || $value < 1 ? 0 : $value;
    }
}
