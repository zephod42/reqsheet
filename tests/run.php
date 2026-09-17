<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\MigrationFile;
use Reqsheet\Database\MigrationRunner;
use Reqsheet\HealthCheck;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

$environment = [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3307',
    'DB_NAME' => 'reqsheet_test',
    'DB_USER' => 'runtime',
    'DB_PASSWORD' => 'not-a-real-secret',
];
$config = DatabaseConfig::fromEnvironment($environment);
assertSameValue(3307, $config->port, 'Database port was not parsed.');
assertSameValue('reqsheet_test', $config->name, 'Database name was not parsed.');
assertThrows(
    static fn (): DatabaseConfig => DatabaseConfig::fromEnvironment(['DB_HOST' => 'localhost']),
    'Missing database configuration was accepted.',
);
assertThrows(
    static fn (): DatabaseConfig => DatabaseConfig::fromEnvironment(array_replace($environment, ['DB_PORT' => '0'])),
    'Invalid database port was accepted.',
);

$factoryCalls = 0;
$database = new Database($config, static function () use (&$factoryCalls): PDO {
    $factoryCalls++;
    return new class extends PDO {
        public function __construct()
        {
        }
    };
});
assertSameValue(0, $factoryCalls, 'Database connection was not lazy.');
$database->connection();
assertSameValue(1, $factoryCalls, 'Database connection factory was not called once.');
$database->connection();
assertSameValue(1, $factoryCalls, 'Database connection was recreated.');

$healthyDatabase = new Database($config, static function (): PDO {
    return new class extends PDO {
        public function __construct()
        {
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            return new class extends PDOStatement {
            };
        }
    };
});
assertSameValue(true, HealthCheck::databaseIsHealthy($healthyDatabase), 'Healthy database was not reported.');

$failingDatabase = new Database($config, static function (): PDO {
    throw new RuntimeException('expected test failure');
});
assertSameValue(false, HealthCheck::databaseIsHealthy($failingDatabase), 'Database failure was not contained.');

$migrationDirectory = dirname(__DIR__) . '/database/migrations';
$ordered = MigrationFile::discover($migrationDirectory);
assertSameValue('0001', $ordered[0]->version, 'Migration ordering is incorrect.');
assertSameValue(['0001'], array_map(static fn (MigrationFile $migration): string => $migration->version, $ordered), 'Unexpected migration set.');
$synthetic = MigrationFile::ordered([
    new MigrationFile('0010', 'later', 'later.sql', ''),
    new MigrationFile('0002', 'earlier', 'earlier.sql', ''),
]);
assertSameValue(['0002', '0010'], array_map(static fn (MigrationFile $migration): string => $migration->version, $synthetic), 'Synthetic migration ordering is incorrect.');
assertSameValue(['0010'], array_map(
    static fn (MigrationFile $migration): string => $migration->version,
    MigrationRunner::pending($synthetic, ['0002']),
), 'Pending migration selection is incorrect.');
assertThrows(
    static fn (): array => MigrationFile::ordered([
        new MigrationFile('0001', 'first', 'first.sql', ''),
        new MigrationFile('0001', 'second', 'second.sql', ''),
    ]),
    'Duplicate migration version was accepted.',
);
assertThrows(
    static fn (): array => MigrationFile::ordered([
        new MigrationFile('0001', 'same_name', 'first.sql', ''),
        new MigrationFile('0002', 'same_name', 'second.sql', ''),
    ]),
    'Duplicate migration name was accepted.',
);
assertSameValue('ok', HealthCheck::status(), 'Existing application health status changed.');

fwrite(STDOUT, "Checks passed.\n");
