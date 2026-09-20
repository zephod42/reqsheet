<?php

declare(strict_types=1);

namespace Reqsheet\Monitor;

final class ReadOnlyGrantValidator
{
    /** @param list<string> $grants */
    public static function assertSelectOnly(array $grants): void
    {
        if ($grants === []) {
            throw new \RuntimeException('The reporting database account grants could not be verified.');
        }

        $hasSelect = false;
        foreach ($grants as $grant) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+.+\s+TO\s+/i', $grant, $matches) !== 1) {
                throw new \RuntimeException('The reporting database account has an unsupported grant.');
            }

            $privileges = array_map('trim', explode(',', strtoupper($matches[1])));
            foreach ($privileges as $privilege) {
                if ($privilege === 'SELECT') {
                    $hasSelect = true;
                    continue;
                }
                if ($privilege !== 'USAGE') {
                    throw new \RuntimeException('The reporting database account is not restricted to SELECT privileges.');
                }
            }
            if (stripos($grant, 'WITH GRANT OPTION') !== false) {
                throw new \RuntimeException('The reporting database account must not have GRANT OPTION.');
            }
        }

        if (!$hasSelect) {
            throw new \RuntimeException('The reporting database account does not have SELECT privileges.');
        }
    }
}
