<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\ExternalEnvironment;
use Reqsheet\HealthCheck;
use Reqsheet\Http\AdminAccess;
use Reqsheet\Http\AdminTimetablePage;
use Reqsheet\Http\ApplicationRoute;
use Reqsheet\Timetable\PdoTimetableConfigurationStore;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = ApplicationRoute::match($method, $path);

if ($route === ApplicationRoute::HEALTH) {
    header('Content-Type: application/json; charset=UTF-8');

    if ($method !== 'GET') {
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

if ($route === ApplicationRoute::NOT_FOUND) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found\n";
    exit;
}

if ($route === ApplicationRoute::ADMIN_TIMETABLE) {
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
        exit;
    }
    if (!AdminAccess::configured($environment)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Not found\n";
        exit;
    }
    if (!AdminAccess::allowed($environment, $_SERVER)) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Reqsheet admin"');
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Authentication required\n";
        exit;
    }
    try {
        $organisationId = AdminAccess::organisationId($environment);
        if ($organisationId < 1) {
            throw new \RuntimeException('Admin organisation is not configured.');
        }
        $config = DatabaseConfig::fromEnvironment($environment);
        $page = new AdminTimetablePage(new PdoTimetableConfigurationStore((new Database($config))->connection()), $organisationId);
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_GET, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
echo 'Reqsheet ' . HealthCheck::status() . PHP_EOL;
