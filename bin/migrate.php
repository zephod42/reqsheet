#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\MigrationRunner;

$environment = getenv();
$environment = is_array($environment) ? $environment : [];

try {
    $config = DatabaseConfig::fromEnvironment($environment, 'MIGRATION_DB_');
    $database = new Database($config);
    $runner = new MigrationRunner(
        $database->connection(),
        dirname(__DIR__) . '/database/migrations',
    );
    $count = $runner->run();
    fwrite(STDOUT, sprintf("Applied %d migration%s.\n", $count, $count === 1 ? '' : 's'));
} catch (Throwable $exception) {
    fwrite(STDERR, 'Migration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
