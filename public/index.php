<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\ExternalEnvironment;
use Reqsheet\HealthCheck;
use Reqsheet\Account\AccountService;
use Reqsheet\Account\PdoAccountStore;
use Reqsheet\Auth\OnboardingHandoffService;
use Reqsheet\Auth\PdoOnboardingHandoffStore;
use Reqsheet\Http\AdminAccess;
use Reqsheet\Http\AdminTimetablePage;
use Reqsheet\Http\ApplicationRoute;
use Reqsheet\Http\TeacherAccess;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Http\AdminPeoplePage;
use Reqsheet\Http\LoginPage;
use Reqsheet\Http\MyAccountPage;
use Reqsheet\Http\HomePage;
use Reqsheet\Http\PageLayout;
use Reqsheet\Http\SessionAuth;
use Reqsheet\Http\SettingsPage;
use Reqsheet\Http\SetupBlockingPage;
use Reqsheet\Http\SetupAccess;
use Reqsheet\Http\SetupPage;
use Reqsheet\Http\SignupPage;
use Reqsheet\Http\TechnicianPage;
use Reqsheet\Http\TenantHostContext;
use Reqsheet\Http\TenantHostException;
use Reqsheet\Http\TenantHostResolver;
use Reqsheet\Teacher\PdoTeacherPlanningStore;
use Reqsheet\Teacher\TeacherPlanningService;
use Reqsheet\Technician\PdoTechnicianPlanningStore;
use Reqsheet\Timetable\PdoTimetableConfigurationStore;
use Reqsheet\Settings\PdoOrganisationSettingsStore;
use Reqsheet\Settings\SettingsService;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = ApplicationRoute::match($method, $path);

// Front-controller responses can contain tenant or session data. Static assets
// are served separately and retain normal cacheability.
header('Cache-Control: no-store, private');
header('Pragma: no-cache');

$rawEnvironment = getenv();
$rawEnvironment = is_array($rawEnvironment) ? $rawEnvironment : [];
try {
    $environment = ExternalEnvironment::load($rawEnvironment);
} catch (\Throwable) {
    $environment = $rawEnvironment;
}

$tenantContext = null;
try {
    $baseHosts = TenantHostResolver::configuredBaseHosts($environment, $_SERVER);
    $canonicalHost = TenantHostResolver::canonicalPublicHost($environment, $baseHosts);
    if ($baseHosts === []) {
        $tenantContext = new TenantHostContext(null, null, null);
    } else {
        $requestHost = TenantHostResolver::requestHost($_SERVER);
        $baseHost = TenantHostResolver::baseHostForRequest($requestHost, $baseHosts);
        if ($baseHost === null) throw new TenantHostException('Host is outside the configured base hosts.');
        $tenantSlug = TenantHostResolver::tenantSlugForHost($requestHost, $baseHost);
        if ($tenantSlug === null) {
            $tenantContext = new TenantHostContext($baseHost, null, null);
        } else {
            $config = DatabaseConfig::fromEnvironment($environment);
            $tenantContext = (new TenantHostResolver(new PdoAccountStore((new Database($config))->connection())))->resolve($requestHost, $baseHost);
        }
    }
    PageLayout::setTenantOrganisation($tenantContext->organisation);
} catch (TenantHostException $exception) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "School not found\n";
    exit;
} catch (\Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Service unavailable\n";
    exit;
}

$tenantOrganisationId = $tenantContext?->organisation['id'] ?? null;
$currentUser = SessionAuth::current();
if ($currentUser !== null) {
    try {
        $sessionAccount = (new PdoAccountStore((new Database(DatabaseConfig::fromEnvironment($environment)))->connection()))->findUserById((int) $currentUser['id']);
        if ($sessionAccount === null || !(bool) ($sessionAccount['is_active'] ?? false) || (int) ($sessionAccount['organisation_id'] ?? 0) !== (int) $currentUser['organisation_id'] || (int) ($sessionAccount['auth_version'] ?? 1) !== (int) ($currentUser['auth_version'] ?? 1)) {
            SessionAuth::logout();
            $currentUser = null;
        }
    } catch (\Throwable) {
        // Do not turn a schema/database outage into an authentication redirect.
        SessionAuth::logout();
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Reqsheet is temporarily unavailable while its database schema is being updated.\n";
        exit;
    }
}
if ($tenantOrganisationId !== null && $currentUser !== null && (int) $currentUser['organisation_id'] !== (int) $tenantOrganisationId) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "School not found\n";
    exit;
}

if ($route === ApplicationRoute::LOGIN) {
    $loginPage = new LoginPage($tenantContext?->organisation);
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
            $account = $accounts->claimFirstLogin($login, (string) ($_POST['password'] ?? ''), (string) ($_POST['password_confirmation'] ?? ''), $tenantOrganisationId === null ? null : (int) $tenantOrganisationId);
            SessionAuth::login($account);
            header('Location: ' . SessionAuth::landingPath($account), true, 302);
            exit;
        }
        if ($method === 'POST') {
            $login = (string) ($_POST['login'] ?? '');
            try {
                $account = $accounts->authenticate($login, (string) ($_POST['password'] ?? ''), $tenantOrganisationId === null ? null : (int) $tenantOrganisationId);
                SessionAuth::login($account);
                header('Location: ' . SessionAuth::landingPath($account), true, 302);
                exit;
            } catch (\Reqsheet\Account\AccountValidationException $exception) {
                if ($accounts->needsFirstLogin($login, $tenantOrganisationId === null ? null : (int) $tenantOrganisationId)) {
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

if ($route === ApplicationRoute::ROOT) {
    if ($tenantOrganisationId !== null) {
        header('Location: /login', true, 302);
        exit;
    }
    header('Content-Type: text/html; charset=UTF-8');
    // The recognised base host is always public. A tenant session must not turn
    // the base URL into a remembered-school redirect or private landing page.
    echo (new HomePage())->render(null);
    exit;
}

if ($route === ApplicationRoute::SIGNUP) {
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $connection = (new Database($config))->connection();
        $page = new SignupPage(
            new AccountService(new PdoAccountStore($connection)),
            $canonicalHost ?? $tenantContext?->baseHost ?? '',
            new OnboardingHandoffService(new PdoOnboardingHandoffStore($connection)),
        );
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::ONBOARDING) {
    if ($method !== 'GET' || $tenantOrganisationId === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Onboarding handoff not found\n";
        exit;
    }
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $connection = (new Database($config))->connection();
        $handoff = (new OnboardingHandoffService(new PdoOnboardingHandoffStore($connection)))->consume(
            (string) ($_GET['token'] ?? ''),
            (int) $tenantOrganisationId,
        );
        if ($handoff === null) throw new \RuntimeException('Onboarding handoff was invalid.');
        $accounts = new AccountService(new PdoAccountStore($connection));
        $account = $accounts->findUserById($handoff['user_id']);
        if ($account === null || !(bool) ($account['is_active'] ?? false) || (int) $account['organisation_id'] !== (int) $tenantOrganisationId) {
            throw new \RuntimeException('Onboarding account was invalid.');
        }
        SessionAuth::login($account);
        header('Location: /settings', true, 302);
    } catch (\Throwable) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Onboarding handoff not found\n";
    }
    exit;
}

if (in_array($route, [ApplicationRoute::ABOUT, ApplicationRoute::DEMO, ApplicationRoute::CONTACT], true)) {
    $heading = ucfirst($route);
    header('Content-Type: text/html; charset=UTF-8');
    echo PageLayout::render($heading, '<section class="content-narrow"><h1>' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1></section>', $currentUser);
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
        $page = new SetupPage(new AccountService(new PdoAccountStore((new Database($config))->connection())), $canonicalHost ?? $tenantContext?->baseHost ?? '');
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

if ($route === ApplicationRoute::MY_ACCOUNT) {
    if ($currentUser === null) {
        header('Location: /login', true, 302);
        exit;
    }
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $page = new MyAccountPage(new AccountService(new PdoAccountStore((new Database($config))->connection())), $currentUser);
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::NOT_FOUND) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Not found\n";
    exit;
}

if ($route === ApplicationRoute::SETTINGS) {
    if (!SessionAuth::isAdmin($currentUser)) {
        header('Location: /login', true, 302);
        exit;
    }
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $database = new Database($config);
        $page = new SettingsPage(new SettingsService(new PdoOrganisationSettingsStore($database->connection())), $currentUser['organisation_id'], $currentUser, new PdoTimetableConfigurationStore($database->connection()));
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($currentUser !== null && in_array($route, [ApplicationRoute::TEACHER_WEEK, ApplicationRoute::TECHNICIAN, ApplicationRoute::ADMIN_PEOPLE, ApplicationRoute::ADMIN_TIMETABLE], true)) {
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $settings = (new SettingsService(new PdoOrganisationSettingsStore((new Database($config))->connection())))->load($currentUser['organisation_id']);
        if (empty($settings['complete'])) {
            if (SessionAuth::isAdmin($currentUser)) {
                header('Location: /settings', true, 302);
                exit;
            }
            header('Content-Type: text/html; charset=UTF-8');
            echo (new SetupBlockingPage())->render($currentUser);
            exit;
        }
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
        exit;
    }
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
            new \DateTimeImmutable('today'),
            $user,
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
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $page = new TechnicianPage(new PdoTechnicianPlanningStore((new Database($config))->connection()), (int) $user['organisation_id'], (int) $user['id'], $user);
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_GET, $_POST);
    } catch (\Throwable) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
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
        $page = new AdminPeoplePage(new AccountService(new PdoAccountStore((new Database($config))->connection())), $user['organisation_id'], $user);
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
        $database = new Database($config);
        $page = new AdminTimetablePage(
            new PdoTimetableConfigurationStore($database->connection()),
            $user['organisation_id'],
            (new SettingsService(new PdoOrganisationSettingsStore($database->connection())))->load($user['organisation_id']),
            $user,
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

header('Content-Type: text/plain; charset=UTF-8');
echo 'Reqsheet ' . HealthCheck::status() . PHP_EOL;
