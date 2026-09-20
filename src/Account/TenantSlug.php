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
        $value = preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
        return substr($value, 0, 12);
    }

    public static function normalise(string $value): string
    {
        $value = trim($value);
        if (!self::isValid($value)) {
            if (strtolower($value) === 'www') {
                throw new AccountValidationException(['This school short code is reserved. Please choose another.']);
            }
            throw new AccountValidationException(['Use 3–12 lowercase letters or numbers.']);
        }
        return $value;
    }

    public static function isValid(string $value): bool
    {
        if (strlen($value) < 3 || strlen($value) > 12 || preg_match('/^[a-z0-9]+$/D', $value) !== 1) {
            return false;
        }
        return !in_array($value, self::RESERVED, true);
    }

    /** Existing slugs remain routable even when they pre-date the creation policy. */
    public static function isRoutable(string $value): bool
    {
        return $value !== '' && strlen($value) <= 63
            && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $value) === 1
            && strtolower($value) !== 'www';
    }

    /** @return list<string> */
    public static function reservedLabels(): array
    {
        return self::RESERVED;
    }
}
