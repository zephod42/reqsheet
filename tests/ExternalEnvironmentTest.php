<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Database\ExternalEnvironment;

final class ExternalEnvironmentTest
{
    public static function run(): void
    {
        $unchanged = ['DB_HOST' => 'existing', 'REQSHEET_ENV_FILE' => null];
        assertSameValue($unchanged, ExternalEnvironment::load($unchanged), 'Absent environment file changed the environment.');

        $path = tempnam(sys_get_temp_dir(), 'reqsheet-env-');
        if ($path === false) {
            throw new \RuntimeException('Could not create temporary environment fixture.');
        }
        try {
            file_put_contents($path, "# comment\n\nDB_HOST=loaded\nDB_PORT=3306\nDB_PASSWORD=literal\$value\nDB_NAME=\n");
            $loaded = ExternalEnvironment::load([
                'REQSHEET_ENV_FILE' => $path,
                'DB_HOST' => 'existing',
            ]);
            assertSameValue('existing', $loaded['DB_HOST'], 'Existing environment value was overwritten.');
            assertSameValue('3306', $loaded['DB_PORT'], 'Environment file value was not loaded.');
            assertSameValue('literal$value', $loaded['DB_PASSWORD'], 'Environment value was interpreted as shell syntax.');
            assertSameValue('', $loaded['DB_NAME'], 'Empty environment value was not preserved.');

            file_put_contents($path, "export DB_HOST=invalid\n");
            assertThrows(static fn (): array => ExternalEnvironment::load(['REQSHEET_ENV_FILE' => $path]), 'Shell export syntax was accepted.');
            file_put_contents($path, "DB_HOST=valid\nnot an assignment\n");
            assertThrows(static fn (): array => ExternalEnvironment::load(['REQSHEET_ENV_FILE' => $path]), 'Malformed environment entry was accepted.');
        } finally {
            unlink($path);
        }

        assertThrows(static fn (): array => ExternalEnvironment::load(['REQSHEET_ENV_FILE' => 'relative.env']), 'Relative environment path was accepted.');
        assertThrows(static fn (): array => ExternalEnvironment::load(['REQSHEET_ENV_FILE' => dirname(__DIR__) . '/.env']), 'Repository environment path was accepted.');
        assertThrows(static fn (): array => ExternalEnvironment::load(['REQSHEET_ENV_FILE' => '/path/that/does/not/exist.env']), 'Missing environment file was accepted.');
    }
}
