#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\ConfigurationException;
use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\ExternalEnvironment;
use Reqsheet\Monitor\GitCommit;
use Reqsheet\Monitor\MonitorReportRenderer;
use Reqsheet\Monitor\PdoMonitorStore;
use Reqsheet\Monitor\ReportFileWriter;

$usage = 'Usage: php bin/operator-report.php --output=/absolute/path/report.html [--force]' . PHP_EOL;
$options = getopt('', ['output:', 'force', 'help']);
if (isset($options['help'])) {
    fwrite(STDOUT, $usage);
    exit(0);
}
if (!isset($options['output']) || !is_string($options['output']) || trim($options['output']) === '') {
    fwrite(STDERR, $usage);
    exit(2);
}

$repository = dirname(__DIR__);
$environment = getenv();
$environment = is_array($environment) ? $environment : [];

try {
    $environmentFile = $environment['REQSHEET_REPORT_ENV_FILE'] ?? null;
    if (!is_string($environmentFile) || trim($environmentFile) === '') {
        throw new ConfigurationException('REQSHEET_REPORT_ENV_FILE is required.');
    }
    $resolvedEnvironmentFile = realpath($environmentFile);
    if ($resolvedEnvironmentFile === false) {
        throw new ConfigurationException('The configured reporting environment file is unavailable.');
    }
    $permissions = fileperms($resolvedEnvironmentFile);
    if ($permissions === false || ($permissions & 0077) !== 0) {
        throw new ConfigurationException('The reporting environment file must not be accessible by group or other users.');
    }

    $environment = ExternalEnvironment::loadFromVariable($environment, 'REQSHEET_REPORT_ENV_FILE');
    $label = $environment['REPORT_ENVIRONMENT'] ?? null;
    if (!is_string($label) || trim($label) === '') {
        throw new ConfigurationException('REPORT_ENVIRONMENT is required.');
    }
    if (mb_strlen($label) > 80) {
        throw new ConfigurationException('REPORT_ENVIRONMENT must be at most 80 characters.');
    }

    $timezoneName = $environment['REPORT_TIMEZONE'] ?? 'UTC';
    if (!is_string($timezoneName) || trim($timezoneName) === '') {
        throw new ConfigurationException('REPORT_TIMEZONE must be a timezone name.');
    }
    try {
        $timezone = new DateTimeZone($timezoneName);
    } catch (Throwable) {
        throw new ConfigurationException('REPORT_TIMEZONE must be a valid timezone name.');
    }

    $commit = $environment['REQSHEET_DEPLOYED_COMMIT'] ?? null;
    if ($commit !== null && (!is_string($commit) || preg_match('/^[0-9a-f]{7,64}$/iD', $commit) !== 1)) {
        throw new ConfigurationException('REQSHEET_DEPLOYED_COMMIT must be a Git commit hash when set.');
    }
    $commit = is_string($commit) ? $commit : GitCommit::detect($repository);

    $config = DatabaseConfig::fromEnvironment($environment, 'REPORT_DB_');
    $snapshot = (new PdoMonitorStore((new Database($config))->connection()))->snapshot();
    $html = (new MonitorReportRenderer())->render(
        $snapshot,
        new DateTimeImmutable('now', $timezone),
        trim($label),
        $commit,
    );
    (new ReportFileWriter($repository . '/public'))->write($options['output'], $html, isset($options['force']));
    fwrite(STDOUT, 'Reqsheet Monitor report written to ' . $options['output'] . PHP_EOL);
} catch (ConfigurationException $exception) {
    fwrite(STDERR, 'Report configuration failed: ' . $exception->getMessage() . PHP_EOL);
    exit(2);
} catch (PDOException) {
    fwrite(STDERR, 'Report generation failed: database connection or reporting query failed. Verify the restricted reporting account and current schema.' . PHP_EOL);
    exit(1);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Report generation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
