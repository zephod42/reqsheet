#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\TenantDataResetter;

$options = getopt('', ['dry-run', 'confirm:']);
$environment = getenv();
$environment = is_array($environment) ? $environment : [];

try {
    $config = DatabaseConfig::fromEnvironment($environment, 'MIGRATION_DB_');
    $resetter = new TenantDataResetter((new Database($config))->connection(), $config->name);
    $plan = $resetter->plan();
    fwrite(STDOUT, 'Target database: ' . $plan['database'] . PHP_EOL);
    fwrite(STDOUT, 'Tenant-owned tables in deletion order: ' . implode(', ', $plan['tables']) . PHP_EOL);
    foreach ($plan['counts'] as $table => $count) fwrite(STDOUT, sprintf('  %s: %d row%s%s', $table, $count, $count === 1 ? '' : 's', PHP_EOL));

    if (isset($options['dry-run'])) {
        fwrite(STDOUT, "Dry run only; no data changed.\n");
        exit(0);
    }
    if (($options['confirm'] ?? null) !== TenantDataResetter::CONFIRMATION) {
        throw new RuntimeException('Refusing reset: pass --confirm=' . TenantDataResetter::CONFIRMATION . ' after reviewing the target and counts.');
    }

    $resetter->reset();
    fwrite(STDOUT, "Reset complete. Schema and migration history were preserved.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, 'Test-data reset failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
