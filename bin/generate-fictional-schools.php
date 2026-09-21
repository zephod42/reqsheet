<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Fictional\FictionalSchoolGenerator;

$options = getopt('', ['school:', 'dry-run', 'regenerate']);
$only = isset($options['school']) ? (string) $options['school'] : null;
$dryRun = array_key_exists('dry-run', $options);
$regenerate = array_key_exists('regenerate', $options);

try {
    $checked = FictionalSchoolGenerator::preflight($_ENV + $_SERVER);
    $database = new Database($checked['config']);
    $pdo = $database->connection();
    $actualDatabase = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($actualDatabase !== $checked['config']->name || $actualDatabase !== 'reqsheet_dev') {
        throw new RuntimeException('Refusing fictional generation: connected database identity is not reqsheet_dev.');
    }
    $actualHost = strtolower((string) $pdo->query('SELECT @@hostname')->fetchColumn());
    if ($actualHost !== '' && $actualHost !== 'pumba' && $actualHost !== 'localhost') {
        throw new RuntimeException('Refusing fictional generation: connected database server is not approved Pumba.');
    }
    $result = (new FictionalSchoolGenerator())->run($pdo, $checked['auth'], $checked['manifest'], $only, $dryRun, $regenerate);
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, 'Fictional generation refused/failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
