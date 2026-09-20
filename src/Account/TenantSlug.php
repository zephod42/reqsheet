<?php

declare(strict_types=1);

namespace Reqsheet\Account;

final class TenantSlug
{
    /** @var list<string> */
    private const RESERVED = [
        'www', 'admin', 'api', 'mail', 'smtp', 'ftp', 'localhost',
        'support', 'help', 'demo', 'signup', 'login', 'settings', 'assets',
    ];

    public static function suggest(string $organisationName): string
    {
        $value = strtolower(trim($organisationName));
        if (function_exists('iconv')) {
            $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($transliterated)) $value = $transliterated;
        }
        $value = str_replace(["'", '’'], '', $value);
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value, '-');
    }

    public static function normalise(string $value): string
    {
        $value = strtolower(trim($value));
        if (!self::isValid($value)) {
            if ($value === 'www') {
                throw new AccountValidationException(['This school short code is reserved. Please choose another.']);
            }
            throw new AccountValidationException(['School short code must use lowercase letters, digits, and hyphens; it must not start or end with a hyphen.']);
        }
        return $value;
    }

    public static function isValid(string $value): bool
    {
        if ($value === '' || strlen($value) > 63 || preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value) !== 1) {
            return false;
        }
        return !in_array($value, self::RESERVED, true);
    }

    /** @return list<string> */
    public static function reservedLabels(): array
    {
        return self::RESERVED;
    }
}
