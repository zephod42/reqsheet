<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\ExternalEnvironment;
use Reqsheet\HealthCheck;
use Reqsheet\Account\AccountService;
use Reqsheet\Account\PdoAccountStore;
use Reqsheet\Http\AdminAccess;
use Reqsheet\Http\AdminTimetablePage;
use Reqsheet\Http\ApplicationRoute;
use Reqsheet\Http\TeacherAccess;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Http\AdminPeoplePage;
use Reqsheet\Http\LoginPage;
use Reqsheet\Http\SessionAuth;
use Reqsheet\Http\SetupAccess;
use Reqsheet\Http\SetupPage;
use Reqsheet\Http\TechnicianPlaceholderPage;
use Reqsheet\Teacher\PdoTeacherPlanningStore;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Timetable\PdoTimetableConfigurationStore;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = ApplicationRoute::match($method, $path);

if ($route === ApplicationRoute::LOGIN) {
    $loginPage = new LoginPage();
    $current = SessionAuth::current();
    if ($current !== null) {
        header('Location: ' . SessionAuth::landingPath($current), true, 302);
        exit;
    }
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $accounts = new AccountService(new PdoAccountStore((new Database($config))->connection()));
        if ($method === 'POST' && ($_POST['action'] ?? '') === 'claim') {
            $login = (string) ($_POST['login'] ?? '');
            $account = $accounts->claimFirstLogin($login, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''));
            SessionAuth::login($account);
            header('Location: ' . SessionAuth::landingPath($account), true, 302);
            exit;
        }
        if ($method === 'POST') {
            $login = (string) ($_POST['login'] ?? '');
            try {
                $account = $accounts->authenticate($login, (string) ($_POST['password'] ?? ''));
                SessionAuth::login($account);
                header('Location: ' . SessionAuth::landingPath($account), true, 302);
                exit;
            } catch (\Reqsheet\Account\AccountValidationException $exception) {
                if ($accounts->needsFirstLogin($login)) {
                    header('Content-Type: text/html; charset=UTF-8');
                    echo $loginPage->firstLogin($login);
                    exit;
                }
                header('Content-Type: text/html; charset=UTF-8');
                echo $loginPage->form('Invalid login details.', $login);
                exit;
            }
        }
        header('Content-Type: text/html; charset=UTF-8');
        echo $loginPage->form();
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::LOGOUT) {
    SessionAuth::logout();
    header('Location: /login', true, 302);
    exit;
}

if ($route === ApplicationRoute::SETUP) {
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
    if (!SetupAccess::configured($environment)) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Not found\n";
        exit;
    }
    if (!SetupAccess::allowed($environment, $_SERVER)) {
        http_response_code(401);
        header('WWW-Authenticate: Basic realm="Reqsheet setup"');
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Authentication required\n";
        exit;
    }
    try {
        $config = DatabaseConfig::fromEnvironment($environment);
        $page = new SetupPage(new AccountService(new PdoAccountStore((new Database($config))->connection())));
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

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

if ($route === ApplicationRoute::TEACHER_WEEK) {
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
    $user = SessionAuth::current();
    if (!SessionAuth::hasRole($user, 'teacher')) {
        http_response_code(401);
        header('Location: /login', true, 302);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Authentication required\n";
        exit;
    }
    try {
        $config = DatabaseConfig::fromEnvironment($environment);
        $page = new TeacherWeekPage(
            new TeacherPlanningService(
                new PdoTeacherPlanningStore((new Database($config))->connection()),
                TeacherAccess::firstDayOfWeek($environment),
            ),
            $user['organisation_id'],
            $user['id'],
        );
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_GET, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::TECHNICIAN) {
    $user = SessionAuth::current();
    if (!SessionAuth::hasRole($user, 'technician')) {
        header('Location: /login', true, 302);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo (new TechnicianPlaceholderPage())->render();
    exit;
}

if ($route === ApplicationRoute::ADMIN_PEOPLE) {
    $user = SessionAuth::current();
    if (!SessionAuth::isAdmin($user)) {
        header('Location: /login', true, 302);
        exit;
    }
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $page = new AdminPeoplePage(new AccountService(new PdoAccountStore((new Database($config))->connection())), $user['organisation_id']);
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
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
    $user = SessionAuth::current();
    if (!SessionAuth::isAdmin($user)) {
        header('Location: /login', true, 302);
        exit;
    }
    try {
        $config = DatabaseConfig::fromEnvironment($environment);
        $page = new AdminTimetablePage(new PdoTimetableConfigurationStore((new Database($config))->connection()), $user['organisation_id']);
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
