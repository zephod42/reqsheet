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

        $resetter = (string) file_get_contents(dirname(__DIR__) . '/src/Database/TenantDataResetter.php');
        assertSameValue(true, str_contains($resetter, 'TABLE_NAME AS table_name'), 'Table metadata query does not explicitly alias TABLE_NAME.');
        assertSameValue(true, str_contains($resetter, 'REFERENCED_TABLE_NAME AS referenced_table_name'), 'Foreign-key metadata query does not explicitly alias REFERENCED_TABLE_NAME.');
        $mysqlMetadataRow = ['TABLE_NAME' => 'organisations'];
        assertSameValue('organisations', $mysqlMetadataRow['TABLE_NAME'], 'Regression fixture did not model MySQL metadata key casing.');
    }
}
