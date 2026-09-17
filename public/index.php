<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\ExternalEnvironment;
use Reqsheet\HealthCheck;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if ($path === '/health') {
    header('Content-Type: application/json; charset=UTF-8');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        echo json_encode(['status' => 'method_not_allowed'], JSON_THROW_ON_ERROR) . PHP_EOL;
        exit;
    }

    try {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        $config = DatabaseConfig::fromEnvironment(ExternalEnvironment::load($environment));
        $healthy = HealthCheck::databaseIsHealthy(new Database($config));
    } catch (\Throwable) {
        $healthy = false;
    }

    http_response_code($healthy ? 200 : 503);
    echo json_encode(
        ['status' => $healthy ? 'ok' : 'unhealthy'],
        JSON_THROW_ON_ERROR,
    ) . PHP_EOL;
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
echo 'Reqsheet ' . HealthCheck::status() . PHP_EOL;
