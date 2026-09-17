<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class ApplicationRoute
{
    public const ROOT = 'root';
    public const HEALTH = 'health';
    public const NOT_FOUND = 'not_found';

    public static function match(string $method, string $path): string
    {
        if ($path === '/health') {
            return self::HEALTH;
        }
        if ($method === 'GET' && $path === '/') {
            return self::ROOT;
        }

        return self::NOT_FOUND;
    }
}
