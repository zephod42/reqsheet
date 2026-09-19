<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use PDO;
use Reqsheet\Database\TenantDataResetter;
use RuntimeException;

final class TenantDataResetterTest
{
    public static function run(): void
    {
        $pdo = new class extends PDO {
            public function __construct() {}
        };
        $rejected = false;
        try {
            new TenantDataResetter($pdo, 'unrelated_database');
        } catch (RuntimeException) {
            $rejected = true;
        }
        assertSameValue(true, $rejected, 'An unknown database was not rejected.');
        assertSameValue(TenantDataResetter::CONFIRMATION, 'DELETE-ALL-REQSHEET-TEST-DATA', 'Reset confirmation token changed unexpectedly.');

        $command = (string) file_get_contents(dirname(__DIR__) . '/bin/reset-test-data.php');
        assertSameValue(true, str_contains($command, "'MIGRATION_DB_'"), 'Reset command did not use protected migration configuration.');
        assertSameValue(true, str_contains($command, "'dry-run'"), 'Reset command has no dry-run option.');
        assertSameValue(true, str_contains($command, "'confirm:'"), 'Reset command has no explicit confirmation option.');
        assertSameValue(false, str_contains($command, 'DROP DATABASE'), 'Reset command must not drop a database.');
        assertSameValue(false, str_contains($command, 'FOREIGN_KEY_CHECKS'), 'Reset command must not disable foreign-key checks.');
        assertSameValue(false, str_contains($command, 'TRUNCATE'), 'Reset command must not truncate tables.');
    }
}
