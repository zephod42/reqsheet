<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class CsrfToken
{
    public static function value(): string
    {
        SessionAuth::start();
        if (!is_string($_SESSION['csrf_token'] ?? null) || strlen($_SESSION['csrf_token']) < 32) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function valid(mixed $value): bool
    {
        return is_string($value) && hash_equals(self::value(), $value);
    }
}
