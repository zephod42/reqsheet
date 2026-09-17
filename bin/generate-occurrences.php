#!/usr/bin/env php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Timetable\PdoTimetableGenerationStore;
use Reqsheet\Timetable\TimetableOccurrenceGenerator;
use Reqsheet\Timetable\TimetableValidationException;

$options = getopt('', ['organisation:', 'version:', 'start:', 'end:']);
$required = ['organisation', 'version', 'start', 'end'];
foreach ($required as $option) {
    if (!isset($options[$option]) || !is_string($options[$option]) || trim($options[$option]) === '') {
        fwrite(STDERR, "Usage: php bin/generate-occurrences.php --organisation=ID --version=ID --start=YYYY-MM-DD --end=YYYY-MM-DD\n");
        exit(2);
    }
}

$environment = getenv();
$environment = is_array($environment) ? $environment : [];

try {
    $config = DatabaseConfig::fromEnvironment($environment);
    $database = new Database($config);
    $generator = new TimetableOccurrenceGenerator(new PdoTimetableGenerationStore($database->connection()));
    $result = $generator->generate(
        (int) $options['organisation'],
        (int) $options['version'],
        $options['start'],
        $options['end'],
    );
    fwrite(STDOUT, sprintf("Generated %d occurrence%s; skipped %d existing.\n", $result->generated, $result->generated === 1 ? '' : 's', $result->skippedExisting));
} catch (TimetableValidationException $exception) {
    foreach ($exception->errors() as $error) {
        fwrite(STDERR, 'Validation failed: ' . $error . PHP_EOL);
    }
    exit(2);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Occurrence generation failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
