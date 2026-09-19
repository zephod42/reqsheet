<?php

declare(strict_types=1);

namespace Reqsheet\Account;

final class StaffIdentifier
{
    public static function normalise(string $value): string
    {
        $value = strtoupper(trim($value));
        if (preg_match('/\A[A-Z]{3}\z/D', $value) !== 1) {
            throw new AccountValidationException(['Please use three capital letters.']);
        }
        return $value;
    }
}
