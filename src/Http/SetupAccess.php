<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class SetupAccess
{
    /** @param array<string, mixed> $environment @param array<string, mixed> $server */
    public static function allowed(array $environment, array $server): bool
    {
        $key = $environment['REQSHEET_SETUP_KEY'] ?? null;
        return is_string($key) && trim($key) !== ''
            && ($server['PHP_AUTH_USER'] ?? null) === 'setup'
            && is_string($server['PHP_AUTH_PW'] ?? null)
            && hash_equals($key, $server['PHP_AUTH_PW']);
    }

    /** @param array<string, mixed> $environment */
    public static function configured(array $environment): bool
    {
        return is_string($environment['REQSHEET_SETUP_KEY'] ?? null)
            && trim($environment['REQSHEET_SETUP_KEY']) !== '';
    }
}
