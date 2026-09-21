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
use Reqsheet\Http\AdminTimetableCsvExport;
use Reqsheet\Http\AdminTimetableCsvImport;
use Reqsheet\Http\AdminTimetableCsvConfirm;
use Reqsheet\Http\AdminTimetableResourceCsvExport;
use Reqsheet\Http\AdminTimetablePage;
use Reqsheet\Http\ApplicationRoute;
use Reqsheet\Http\TeacherAccess;
use Reqsheet\Http\TeacherWeekPage;
use Reqsheet\Http\TeacherDayPage;
use Reqsheet\Http\TeacherClassPage;
use Reqsheet\Http\AdminPeoplePage;
use Reqsheet\Http\LoginPage;
use Reqsheet\Http\MyAccountPage;
use Reqsheet\Http\AccountRecoveryPage;
use Reqsheet\Http\RecoveryKeyPage;
use Reqsheet\Http\HomePage;
use Reqsheet\Http\PageLayout;
use Reqsheet\Http\RequestExceptionLogger;
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
use Reqsheet\Timetable\BlankTimetableCsvExporter;
use Reqsheet\Timetable\TimetableCsvImportDraftStore;
use Reqsheet\Timetable\TimetableCsvImportPreviewService;
use Reqsheet\Timetable\TimetableCsvParser;
use Reqsheet\Timetable\TimetableCsvImportService;
use Reqsheet\Timetable\TimetableCsvImportTechnicalException;
use Reqsheet\Timetable\TimetableResourceCsvExporter;
use Reqsheet\Timetable\TimetableCsvExportException;
use Reqsheet\Settings\PdoOrganisationSettingsStore;
use Reqsheet\Settings\SettingsService;
use Reqsheet\Recovery\PdoRecoveryStore;
use Reqsheet\Recovery\RecoveryService;
use Reqsheet\Recovery\RecoverySession;

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$route = ApplicationRoute::match($method, $path);

// Front-controller responses can contain tenant or session data. Static assets
// are served separately and retain normal cacheability.
header('Cache-Control: no-store, private');
header('Pragma: no-cache');
$requestId = RequestExceptionLogger::requestId(isset($_SERVER['HTTP_X_REQUEST_ID']) ? (string) $_SERVER['HTTP_X_REQUEST_ID'] : null);
header('X-Request-ID: ' . $requestId);
$logRequestFailure = static function (string $stage, \Throwable $exception) use ($path, $requestId): void {
    RequestExceptionLogger::log($path . ':' . $stage, $exception, $requestId);
};

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
} catch (\Throwable $exception) {
    $logRequestFailure('tenant-resolution', $exception);
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
    } catch (\Throwable $exception) {
        // Do not turn a schema/database outage into an authentication redirect.
        $logRequestFailure('session-validation', $exception);
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

if ($route === ApplicationRoute::ACCOUNT_RECOVERY) {
    if ($tenantOrganisationId === null || $tenantContext?->organisation === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Not found\n";
        exit;
    }
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if (!$https) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Account Recovery requires HTTPS.\n";
        exit;
    }
    if ($currentUser !== null) {
        header('Location: ' . SessionAuth::landingPath($currentUser), true, 302);
        exit;
    }
    if (!in_array($method, ['GET', 'POST'], true) || (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code($method === 'POST' ? 413 : 405);
        header('Content-Type: text/plain; charset=UTF-8');
        echo $method === 'POST' ? "Request too large\n" : "Method not allowed\n";
        exit;
    }
    try {
        $connection = (new Database(DatabaseConfig::fromEnvironment($environment)))->connection();
        $accounts = new AccountService(new PdoAccountStore($connection));
        $recovery = new RecoveryService(new PdoRecoveryStore($connection), $accounts);
        $page = new AccountRecoveryPage($recovery, $accounts, $tenantContext->organisation, (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable $exception) {
        $logRequestFailure('account-recovery', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
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
    } catch (\Throwable $exception) {
        $logRequestFailure('login', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('signup', $exception);
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
        $recovery = new RecoveryService(new PdoRecoveryStore($connection), $accounts);
        $issued = $recovery->issueForAdministrator((int) $tenantOrganisationId, (int) $account['id'], true);
        $account = $accounts->findUserById((int) $account['id']);
        if ($account === null) throw new \RuntimeException('Onboarding account was unavailable after recovery-key issue.');
        SessionAuth::login($account);
        RecoverySession::present((int) $tenantOrganisationId, (int) $account['id'], $issued);
        header('Location: /recovery-key', true, 302);
    } catch (\Throwable) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Onboarding handoff not found\n";
    }
    exit;
}

if (in_array($route, [ApplicationRoute::ABOUT, ApplicationRoute::ALPHA, ApplicationRoute::DEMO, ApplicationRoute::CONTACT], true)) {
    $heading = $route === ApplicationRoute::ALPHA ? 'Reqsheet α — Alpha testing' : ucfirst($route);
    $content = '<section class="content-narrow"><h1>' . htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</h1></section>';
    if ($route === ApplicationRoute::ALPHA) {
        PageLayout::setTenantOrganisation(null);
        $content = <<<'HTML'
<section class="content-narrow public-copy alpha-page">
    <h1>Reqsheet α — Alpha testing</h1>
    <h2>Introduction</h2>
    <p>Reqsheet is currently in alpha testing. The application is still under active development and has not yet reached the level of stability required for schools to depend upon it as their sole departmental organisation system.</p>
    <p>We are making Reqsheet available so that teachers and technicians can test it, identify problems and help improve the application.</p>
    <h2>What does alpha mean for you?</h2>
    <h3>Features may change without warning.</h3>
    <p>Features may appear, disappear or change as development continues. Some functionality may not work as expected.</p>
    <h3>Your information may be lost.</h3>
    <p>Information stored in Reqsheet's database may be changed or deleted during testing and may not be recoverable.</p>
    <p>This includes timetables, lesson information, requisitions and preparation records.</p>
    <h3>Account access may be lost.</h3>
    <p>Account details, passwords and recovery information may be affected by development changes.</p>
    <p>You may need to create a new account or set up your school again.</p>
    <h2>If you are using Reqsheet in your department</h2>
    <p>Do not rely on Reqsheet as your only source of departmental organisation during alpha testing.</p>
    <p>If you use it for real departmental planning, maintain an independent record of essential information.</p>
    <p>Print and securely store your timetables and requisitions regularly.</p>
    <p>Keep existing departmental arrangements available during alpha testing.</p>
    <h2>What comes next? Beta testing</h2>
    <p>We expect to move Reqsheet into beta testing soon.</p>
    <p>Beta means that the core features are in place and development focuses increasingly on reliability, fixing remaining bugs and improving the experience of using Reqsheet in real departments.</p>
    <p>Before moving to beta, we intend to establish stronger expectations around stability and data preservation.</p>
    <p>Beta will not mean that the application is completely free from bugs.</p>
    <h2>Feedback</h2>
    <p><a href="mailto:feedback@reqsheet.com">feedback@reqsheet.com</a></p>
</section>
HTML;
    } elseif ($route === ApplicationRoute::ABOUT) {
        $content = <<<'HTML'
<section class="content-narrow about-page">
    <h1 class="about-wordmark">Reqsheet.</h1>
    <div class="about-introduction">
        <p>Reqsheet is a lightweight organiser for school departments. Think of it as the digital equivalent of Post-it notes on the prep room wall: a simple, shared space where everyone can see what needs to happen and when.</p>
        <p>By design, Reqsheet stores only the information needed to organise your department. No unnecessary personal details, no complicated administration, and no features getting in the way of the job.</p>
        <p>Built on 20 years of experience working in school science departments, Reqsheet brings teachers and technicians together in one clear, straightforward system.</p>
        <p><strong>Maximum clarity. Minimum effort.</strong></p>
    </div>
    <section class="about-principles">
        <h2>Our principles</h2>
        <section>
            <h3>Data collection</h3>
            <p>Reqsheet is designed to collect the bare minimum of information necessary to operate. We will never sell your information to a third party. Wherever possible, we simply won't collect it in the first place.</p>
            <p>Reqsheet does not ask for your name, home address or email address. We require only a school name, a school short code and staff initials to identify your organisation and its users. These can be as real — or as fictional — as you choose.</p>
            <p>Collecting unnecessary personal information creates risks for everyone. By avoiding it in the first place, we aim to keep Reqsheet simple and minimise the consequences of a potential data breach.</p>
        </section>
        <section>
            <h3>Economic model</h3>
            <p>Reqsheet is currently free to use, but hosting it isn't free.</p>
            <p>If you'd like to support the project, you can share your feedback at <a href="mailto:feedback@reqsheet.com">feedback@reqsheet.com</a> or make a donation using the options below.</p>
            <div class="donation-placeholder"><strong>Donations</strong><p>Donation options coming soon.</p></div>
        </section>
        <section>
            <h3>Advertising</h3>
            <p>Reqsheet does not support itself through advertising. On-page adverts make interfaces busier, introduce unnecessary distractions and don't align with our principles of keeping things clean, simple and fast.</p>
        </section>
        <section>
            <h3>Freemium</h3>
            <p>Reqsheet is a tool. Giving some users a deliberately restricted version of that tool isn't in the interests of Reqsheet or its users.</p>
            <p>We want everyone to have access to the same useful, fully functional application.</p>
        </section>
        <section>
            <h3>Charging</h3>
            <p><strong>Ultimately, Reqsheet is intended to operate as a software-as-a-service (SaaS) business.</strong></p>
            <p>By keeping the application lightweight and simple, we hope to offer it for a minimal annual fee, potentially in the region of £20, €20 or $20 per school, depending on scale and operating costs. Pricing is still being evaluated.</p>
            <p>If we introduce charging, existing users will receive suitable advance notice and will be able to continue using Reqsheet for free for at least 60 days after that notice. This will give schools time to decide whether to continue with Reqsheet or move to another system — even if that's Post-it notes on the prep room wall!</p>
            <p>Payments will be handled by an independent third-party provider, which may collect personal information according to its own terms and privacy policy. Reqsheet itself will not request or store your personal payment details.</p>
        </section>
    </section>
</section>
HTML;
    } elseif ($route === ApplicationRoute::DEMO) {
        $content = '<section class="content-narrow public-copy demo-page"><h1>Demo</h1><p>Reqsheet is designed to be simple and intuitive. A few short tutorials will appear here in time, but for now, here\'s how to get started.</p><ol><li>Sign up and set up your school using the on-screen instructions.</li><li>Create your timetable template in Settings, configuring your working days and teaching periods.</li><li>Add your rooms and use People to create your staff accounts.</li><li>Export your blank timetable CSV from the timetable builder.</li><li>Download your department\'s existing timetable from your school\'s MIS or other timetable system.</li><li>Use an AI assistant, such as ChatGPT, to populate the Reqsheet CSV using your existing timetable information.</li><li>Import the completed CSV into Reqsheet. Your new timetable will be created and activated automatically.</li></ol><p>Of course, you can build your timetable manually if you prefer, although importing it can save a considerable amount of time.</p><p>Once your timetable, rooms, staff and class codes are in place, you\'re pretty much good to go!</p><p>Reqsheet is ready for your teachers and technicians to start using.</p></section>';
    } elseif ($route === ApplicationRoute::CONTACT) {
        $content = '<section class="content-narrow public-copy contact-page"><h1>Contact</h1><p>Feedback, bug reports and feature requests:<br><a href="mailto:feedback@reqsheet.com">feedback@reqsheet.com</a></p><p>Account enquiries and access issues:<br><a href="mailto:accounts@reqsheet.com">accounts@reqsheet.com</a></p></section>';
    }
    header('Content-Type: text/html; charset=UTF-8');
    echo PageLayout::render($heading, $content, $currentUser);
    exit;
}

if ($route === ApplicationRoute::LOGOUT) {
    SessionAuth::logout();
    header('Location: /login', true, 302);
    exit;
}

if ($route === ApplicationRoute::RECOVERY_KEY) {
    if ($tenantOrganisationId === null || !SessionAuth::isAdmin($currentUser)) {
        header('Location: /login', true, 302);
        exit;
    }
    $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
    if (!$https) {
        http_response_code(400);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Organisation Recovery Key management requires HTTPS.\n";
        exit;
    }
    try {
        $connection = (new Database(DatabaseConfig::fromEnvironment($environment)))->connection();
        $accounts = new AccountService(new PdoAccountStore($connection));
        $page = new RecoveryKeyPage(new RecoveryService(new PdoRecoveryStore($connection), $accounts), $accounts, $currentUser);
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable $exception) {
        $logRequestFailure('recovery-key', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($currentUser !== null && ($currentUser['account_state'] ?? 'claimed') === 'recovery_pending') {
    header('Location: /recovery-key', true, 302);
    exit;
}

if ($route === ApplicationRoute::SETUP) {
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
    } catch (\Throwable $exception) {
        $logRequestFailure('setup-environment', $exception);
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
        $connection = (new Database($config))->connection();
        $page = new SetupPage(
            new AccountService(new PdoAccountStore($connection)),
            $canonicalHost ?? $tenantContext?->baseHost ?? '',
            new OnboardingHandoffService(new PdoOnboardingHandoffStore($connection)),
        );
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_POST);
    } catch (\Throwable $exception) {
        $logRequestFailure('setup-page', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('health', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('account-page', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('settings-page', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($currentUser !== null && in_array($route, [ApplicationRoute::TEACHER_WEEK, ApplicationRoute::TEACHER_DAY, ApplicationRoute::TEACHER_CLASS, ApplicationRoute::TECHNICIAN, ApplicationRoute::ADMIN_PEOPLE, ApplicationRoute::ADMIN_TIMETABLE, ApplicationRoute::ADMIN_TIMETABLE_EXPORT, ApplicationRoute::ADMIN_TIMETABLE_IMPORT, ApplicationRoute::ADMIN_TIMETABLE_IMPORT_CONFIRM, ApplicationRoute::ADMIN_TIMETABLE_RESOURCES], true)) {
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $settings = (new SettingsService(new PdoOrganisationSettingsStore((new Database($config))->connection())))->load($currentUser['organisation_id']);
        $adminTimetableRoutes = [ApplicationRoute::ADMIN_TIMETABLE, ApplicationRoute::ADMIN_TIMETABLE_EXPORT, ApplicationRoute::ADMIN_TIMETABLE_IMPORT, ApplicationRoute::ADMIN_TIMETABLE_IMPORT_CONFIRM, ApplicationRoute::ADMIN_TIMETABLE_RESOURCES];
        if (empty($settings['complete']) && !($currentUser !== null && SessionAuth::isAdmin($currentUser) && in_array($route, $adminTimetableRoutes, true))) {
            if (SessionAuth::isAdmin($currentUser)) {
                header('Location: /settings', true, 302);
                exit;
            }
            header('Content-Type: text/html; charset=UTF-8');
            echo (new SetupBlockingPage())->render($currentUser);
            exit;
        }
    } catch (\Throwable $exception) {
        $logRequestFailure('setup-gate', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
        exit;
    }
}

if (in_array($route, [ApplicationRoute::TEACHER_WEEK, ApplicationRoute::TEACHER_DAY, ApplicationRoute::TEACHER_CLASS], true)) {
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    try {
        $environment = ExternalEnvironment::load($environment);
    } catch (\Throwable $exception) {
        $logRequestFailure('teacher-environment', $exception);
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
        $planning = new TeacherPlanningService(
            new PdoTeacherPlanningStore((new Database($config))->connection()),
            TeacherAccess::firstDayOfWeek($environment),
        );
        $today = new \DateTimeImmutable('today');
        $page = $route === ApplicationRoute::TEACHER_DAY
            ? new TeacherDayPage($planning, $user['organisation_id'], $user['id'], $today, $user)
            : ($route === ApplicationRoute::TEACHER_CLASS
                ? new TeacherClassPage($planning, $user['organisation_id'], $user['id'], $today, $user)
                : new TeacherWeekPage($planning, $user['organisation_id'], $user['id'], $today, $user));
        header('Content-Type: text/html; charset=UTF-8');
        echo $page->handle($method, $_GET, $_POST);
    } catch (\Throwable $exception) {
        $logRequestFailure($route === ApplicationRoute::TEACHER_DAY ? 'teacher-day-page' : 'teacher-page', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('technician-page', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('people-page', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::ADMIN_TIMETABLE_EXPORT) {
    $user = SessionAuth::current();
    if (!SessionAuth::isAdmin($user)) {
        header('Location: /login', true, 302);
        exit;
    }
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    $versionId = (int) ($_GET['version'] ?? 0);
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $export = (new AdminTimetableCsvExport(
            new BlankTimetableCsvExporter(new PdoTimetableConfigurationStore((new Database($config))->connection())),
            (int) $user['organisation_id'],
            $user,
        ))->create($versionId);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $export->filename . '"');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $export->content;
    } catch (TimetableCsvExportException $exception) {
        if ($exception->reason === TimetableCsvExportException::VERSION_UNAVAILABLE) {
            http_response_code(404);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Timetable template not found\n";
        } elseif ($exception->reason === TimetableCsvExportException::UNAUTHORISED) {
            http_response_code(403);
            header('Content-Type: text/plain; charset=UTF-8');
            echo "Administrator access required\n";
        } else {
            header('Location: /admin/timetable?version=' . $versionId . '&csv_error=' . rawurlencode($exception->reason), true, 302);
        }
    } catch (\Throwable $exception) {
        $logRequestFailure('timetable-csv-export', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::ADMIN_TIMETABLE_RESOURCES) {
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
        $export = (new AdminTimetableResourceCsvExport(
            new TimetableResourceCsvExporter(new PdoTimetableConfigurationStore((new Database($config))->connection())),
            (int) $user['organisation_id'],
            $user,
        ))->create();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $export->filename . '"');
        header('Cache-Control: no-store, private, max-age=0');
        header('X-Content-Type-Options: nosniff');
        echo $export->content;
    } catch (TimetableCsvExportException $exception) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Administrator access required\n";
    } catch (\Throwable $exception) {
        $logRequestFailure('timetable-resource-csv-export', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::ADMIN_TIMETABLE_IMPORT) {
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
        $database = new Database($config);
        $store = new PdoTimetableConfigurationStore($database->connection());
        $settings = (new SettingsService(new PdoOrganisationSettingsStore($database->connection())))->load((int) $user['organisation_id']);
        $response = (new AdminTimetableCsvImport(
            new TimetableCsvParser(),
            new TimetableCsvImportPreviewService($store),
            new TimetableCsvImportDraftStore(),
            (int) $user['organisation_id'],
            $user,
            (bool) ($settings['allow_double_periods'] ?? false),
            $store,
            new AccountService(new PdoAccountStore($database->connection())),
        ))->handle($_POST, $_FILES);
        http_response_code($response->status);
        header('Content-Type: text/html; charset=UTF-8');
        echo $response->html;
    } catch (\Throwable $exception) {
        $logRequestFailure('timetable-csv-import-preview', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

if ($route === ApplicationRoute::ADMIN_TIMETABLE_IMPORT_CONFIRM) {
    $user = SessionAuth::current();
    if (!SessionAuth::isAdmin($user)) {
        header('Location: /login', true, 302);
        exit;
    }
    $environment = getenv();
    $environment = is_array($environment) ? $environment : [];
    $action = null;
    try {
        $environment = ExternalEnvironment::load($environment);
        $config = DatabaseConfig::fromEnvironment($environment);
        $store = new PdoTimetableConfigurationStore((new Database($config))->connection());
        $action = new AdminTimetableCsvConfirm(
            new TimetableCsvImportService(
                $store,
                new BlankTimetableCsvExporter($store),
                new TimetableCsvParser(),
                new TimetableCsvImportPreviewService($store),
            ),
            new TimetableCsvImportDraftStore(),
            (int) $user['organisation_id'],
            $user,
        );
        $response = $action->handle($_POST);
        http_response_code($response->status);
        if ($response->location !== null) header('Location: ' . $response->location, true, $response->status);
        if ($response->html !== '') {
            header('Content-Type: text/html; charset=UTF-8');
            echo $response->html;
        }
    } catch (TimetableCsvImportTechnicalException $exception) {
        $logRequestFailure('timetable-csv-import-confirm', $exception);
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo $action instanceof AdminTimetableCsvConfirm
            ? $action->error(['The import could not be completed. No lessons were saved. Please try again.'])
            : "Timetable import failed. No lessons were saved.\n";
    } catch (\Throwable $exception) {
        $logRequestFailure('timetable-csv-import-confirm-setup', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('timetable-environment', $exception);
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
    } catch (\Throwable $exception) {
        $logRequestFailure('timetable-page', $exception);
        http_response_code(503);
        header('Content-Type: text/plain; charset=UTF-8');
        echo "Service unavailable\n";
    }
    exit;
}

header('Content-Type: text/plain; charset=UTF-8');
echo 'Reqsheet ' . HealthCheck::status() . PHP_EOL;
