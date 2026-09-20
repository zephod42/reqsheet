<?php

declare(strict_types=1);

ini_set('session.save_path', sys_get_temp_dir());

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/ExternalEnvironmentTest.php';
require __DIR__ . '/RoutingTest.php';
require __DIR__ . '/TimetableConfigurationTest.php';
require __DIR__ . '/AdminTimetablePageTest.php';
require __DIR__ . '/BlankTimetableCsvExporterTest.php';
require __DIR__ . '/TimetableCsvImportTest.php';
require __DIR__ . '/TimetableCsvConfirmTest.php';
require __DIR__ . '/TeacherWeekPageTest.php';
require __DIR__ . '/PdoTeacherPlanningStoreTest.php';
require __DIR__ . '/AccountTest.php';
require __DIR__ . '/TenantTest.php';
require __DIR__ . '/SettingsTest.php';
require __DIR__ . '/OnboardingHandoffTest.php';
require __DIR__ . '/TimetableGenerationTest.php';
require __DIR__ . '/MyAccountTest.php';
require __DIR__ . '/AdminPeoplePageTest.php';
require __DIR__ . '/LoginPageTest.php';
require __DIR__ . '/TenantDataResetterTest.php';
require __DIR__ . '/TechnicianPageTest.php';
require __DIR__ . '/RecoveryTest.php';

use Reqsheet\Database\Database;
use Reqsheet\Database\DatabaseConfig;
use Reqsheet\Database\MigrationFile;
use Reqsheet\Database\MigrationRunner;
use Reqsheet\HealthCheck;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new RuntimeException($message);
}

$environment = [
    'DB_HOST' => 'localhost',
    'DB_PORT' => '3307',
    'DB_NAME' => 'reqsheet_test',
    'DB_USER' => 'runtime',
    'DB_PASSWORD' => 'not-a-real-secret',
];
$config = DatabaseConfig::fromEnvironment($environment);
assertSameValue(3307, $config->port, 'Database port was not parsed.');
assertSameValue('reqsheet_test', $config->name, 'Database name was not parsed.');
assertThrows(
    static fn (): DatabaseConfig => DatabaseConfig::fromEnvironment(['DB_HOST' => 'localhost']),
    'Missing database configuration was accepted.',
);
assertThrows(
    static fn (): DatabaseConfig => DatabaseConfig::fromEnvironment(array_replace($environment, ['DB_PORT' => '0'])),
    'Invalid database port was accepted.',
);

$factoryCalls = 0;
$database = new Database($config, static function () use (&$factoryCalls): PDO {
    $factoryCalls++;
    return new class extends PDO {
        public function __construct()
        {
        }
    };
});
assertSameValue(0, $factoryCalls, 'Database connection was not lazy.');
$database->connection();
assertSameValue(1, $factoryCalls, 'Database connection factory was not called once.');
$database->connection();
assertSameValue(1, $factoryCalls, 'Database connection was recreated.');

$healthyDatabase = new Database($config, static function (): PDO {
    return new class extends PDO {
        public function __construct()
        {
        }

        public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
        {
            return new class extends PDOStatement {
            };
        }
    };
});
assertSameValue(true, HealthCheck::databaseIsHealthy($healthyDatabase), 'Healthy database was not reported.');

$failingDatabase = new Database($config, static function (): PDO {
    throw new RuntimeException('expected test failure');
});
assertSameValue(false, HealthCheck::databaseIsHealthy($failingDatabase), 'Database failure was not contained.');

$migrationDirectory = dirname(__DIR__) . '/database/migrations';
$ordered = MigrationFile::discover($migrationDirectory);
assertSameValue('0001', $ordered[0]->version, 'Migration ordering is incorrect.');
assertSameValue(['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012', '0013'], array_map(static fn (MigrationFile $migration): string => $migration->version, $ordered), 'Unexpected migration set.');
assertSameValue([], MigrationRunner::pending($ordered, ['0001', '0002', '0003', '0004', '0005', '0006', '0007', '0008', '0009', '0010', '0011', '0012', '0013']), 'Applied migrations were not idempotently selectable.');
$domainMigration = file_get_contents($migrationDirectory . '/0002_create_application_domain.sql');
if ($domainMigration === false) {
    throw new RuntimeException('Domain migration could not be read.');
}
foreach (['organisations', 'users', 'timetable_versions', 'timetable_slots', 'recurring_lessons', 'lesson_occurrences', 'requisitions'] as $table) {
    if (!str_contains($domainMigration, 'CREATE TABLE ' . $table . ' ')) {
        throw new RuntimeException('Expected domain table is missing: ' . $table);
    }
}
foreach ([
    'UNIQUE KEY lesson_occurrences_organisation_lesson',
    'CONSTRAINT timetable_versions_date_range_valid',
    'CONSTRAINT timetable_slots_kind_valid',
    'CONSTRAINT timetable_slots_period_kind_consistent',
    'CONSTRAINT requisitions_state_valid',
    'FOREIGN KEY (organisation_id) REFERENCES organisations (id)',
] as $expectedSchemaFragment) {
    if (!str_contains($domainMigration, $expectedSchemaFragment)) {
        throw new RuntimeException('Expected schema constraint is missing: ' . $expectedSchemaFragment);
    }
}
foreach (['roles', 'permissions', 'rooms', 'equipment', 'stock', 'timetable_exceptions'] as $forbiddenTable) {
    if (preg_match('/CREATE TABLE [^;]*\b' . preg_quote($forbiddenTable, '/') . '\b/i', $domainMigration) === 1) {
        throw new RuntimeException('Deferred table was introduced: ' . $forbiddenTable);
    }
}
$settingsMigration = file_get_contents($migrationDirectory . '/0004_add_organisation_settings.sql');
if ($settingsMigration === false) throw new RuntimeException('Settings migration could not be read.');
foreach (['organisation_settings', 'organisation_rooms', 'allow_double_periods'] as $expectedSettingsFragment) {
    if (!str_contains($settingsMigration, $expectedSettingsFragment)) throw new RuntimeException('Expected settings schema fragment is missing: ' . $expectedSettingsFragment);
}
$tenantMigration = file_get_contents($migrationDirectory . '/0005_add_tenant_slugs.sql');
if ($tenantMigration === false) throw new RuntimeException('Tenant migration could not be read.');
foreach (['tenant_slug', 'organisations_tenant_slug', 'organisation-'] as $expectedTenantFragment) {
    if (!str_contains($tenantMigration, $expectedTenantFragment)) throw new RuntimeException('Expected tenant schema fragment is missing: ' . $expectedTenantFragment);
}
$handoffMigration = file_get_contents($migrationDirectory . '/0006_create_onboarding_handoffs.sql');
if ($handoffMigration === false) throw new RuntimeException('Onboarding handoff migration could not be read.');
foreach (['onboarding_handoffs', 'token_hash', 'consumed_at', 'expires_at'] as $expectedHandoffFragment) {
    if (!str_contains($handoffMigration, $expectedHandoffFragment)) throw new RuntimeException('Expected onboarding handoff schema fragment is missing: ' . $expectedHandoffFragment);
}
$resourceMigration = file_get_contents($migrationDirectory . '/0007_add_timetable_resources.sql');
if ($resourceMigration === false) throw new RuntimeException('Timetable resource migration could not be read.');
foreach (['organisation_classes', 'class_id', 'room_id', 'recurring_lessons_class_fk', 'recurring_lessons_room_fk'] as $expectedResourceFragment) {
    if (!str_contains($resourceMigration, $expectedResourceFragment)) throw new RuntimeException('Expected timetable resource schema fragment is missing: ' . $expectedResourceFragment);
}
$peopleMigration = file_get_contents($migrationDirectory . '/0008_add_people_roles_and_teacher_numbers.sql');
if ($peopleMigration === false) throw new RuntimeException('People migration could not be read.');
foreach (['email', 'is_teacher', 'is_technician', 'teacher_number', 'users_organisation_teacher_number', 'ROW_NUMBER'] as $expectedPeopleFragment) {
    if (!str_contains($peopleMigration, $expectedPeopleFragment)) throw new RuntimeException('Expected people schema fragment is missing: ' . $expectedPeopleFragment);
}
$technicianMigration = file_get_contents($migrationDirectory . '/0010_create_technician_room_preferences.sql');
if ($technicianMigration === false) throw new RuntimeException('Technician preference migration could not be read.');
foreach (['technician_room_preferences', 'organisation_id', 'user_id', 'room_id'] as $expectedTechnicianFragment) {
    if (!str_contains($technicianMigration, $expectedTechnicianFragment)) throw new RuntimeException('Expected technician schema fragment is missing: ' . $expectedTechnicianFragment);
}
$recoveryMigration = file_get_contents($migrationDirectory . '/0012_add_recovery_and_remove_email.sql');
if ($recoveryMigration === false) throw new RuntimeException('Recovery migration could not be read.');
foreach (['DROP COLUMN email', 'DROP COLUMN contact_email', 'recovery_key_digest', 'recovery_key_generation', 'account_recovery_flows', 'account_recovery_rate_limits', 'subscription_state', 'subscription_until'] as $expectedRecoveryFragment) {
    if (!str_contains($recoveryMigration, $expectedRecoveryFragment)) throw new RuntimeException('Expected recovery schema fragment is missing: ' . $expectedRecoveryFragment);
}
$staffMigration = file_get_contents($migrationDirectory . '/0013_remove_staff_names_and_extend_recovery.sql');
if ($staffMigration === false) {
    throw new RuntimeException('Staff-minimisation migration could not be read.');
}
foreach (['information_schema.TABLE_CONSTRAINTS', 'information_schema.STATISTICS', 'information_schema.COLUMNS', 'DROP CHECK users_display_name_not_blank', 'DROP INDEX users_login', 'DROP COLUMN display_name', 'ADD COLUMN prepared_at', 'MODIFY COLUMN user_id BIGINT UNSIGNED NULL', 'requested_initials', 'DEALLOCATE PREPARE'] as $expectedStaffFragment) {
    if (!str_contains(strtoupper($staffMigration), strtoupper($expectedStaffFragment))) {
        throw new RuntimeException('Staff-minimisation migration is missing: ' . $expectedStaffFragment);
    }
}
if (str_contains($staffMigration, 'account_recovery_flows_target_valid')) {
    throw new RuntimeException('Staff-minimisation migration contains the incompatible recovery target CHECK.');
}
$publicIndex = file_get_contents(dirname(__DIR__) . '/public/index.php');
if ($publicIndex === false) {
    throw new RuntimeException('Public front controller could not be read.');
}
foreach ([
    'Reqsheet is designed to be simple and intuitive.',
    'Use an AI assistant, such as ChatGPT, to populate the Reqsheet CSV',
    'Your new timetable will be created and activated automatically.',
    'mailto:feedback@reqsheet.com',
    'mailto:accounts@reqsheet.com',
    '<h1 class="about-wordmark">Reqsheet.</h1>',
    'Reqsheet is a lightweight organiser for school departments.',
    'Our principles',
    'Data collection',
    'Economic model',
    'Advertising',
    'Freemium',
    'Charging',
    'Reqsheet is currently free to use, but hosting it isn\'t free.',
    'mailto:feedback@reqsheet.com',
    'Donations',
    'Donation options coming soon.',
    'Ultimately, Reqsheet is intended to operate as a software-as-a-service (SaaS) business.',
    'potentially in the region of £20, €20 or $20 per school',
    'at least 60 days after that notice',
] as $publicFragment) {
    if (!str_contains($publicIndex, $publicFragment)) {
        throw new RuntimeException('Public page content is missing: ' . $publicFragment);
    }
}
$alphaFragments = [
    "public const ALPHA = 'alpha'",
    "if (\$path === '/alpha') return self::ALPHA",
    'Reqsheet α — Alpha testing',
    'What does alpha mean for you?',
    'If you are using Reqsheet in your department',
    'What comes next? Beta testing',
    'href="mailto:feedback@reqsheet.com"',
];
foreach ($alphaFragments as $alphaFragment) {
    if (!str_contains($publicIndex, $alphaFragment) && !str_contains((string) file_get_contents(dirname(__DIR__) . '/src/Http/ApplicationRoute.php'), $alphaFragment)) {
        throw new RuntimeException('Alpha page content or routing is missing: ' . $alphaFragment);
    }
}
if (!preg_match('/<div class="donation-placeholder">(.*?)<\/div>/s', $publicIndex, $donationMatch) || str_contains($donationMatch[1], 'href=')) {
    throw new RuntimeException('Donation placeholder unexpectedly contains an active link.');
}
foreach (['The prep room wall, reimagined for the 21st century.', '<h1>ABOUT REQSHEET</h1>'] as $removedAboutFragment) {
    if (str_contains($publicIndex, $removedAboutFragment)) {
        throw new RuntimeException('Obsolete About page content remains: ' . $removedAboutFragment);
    }
}
$publicCss = file_get_contents(dirname(__DIR__) . '/public/assets/app.css');
if ($publicCss === false || !str_contains($publicCss, '.about-page { max-width: 44rem; text-align: left;') || !str_contains($publicCss, '.about-wordmark { margin: 0 0 2rem; text-align: center;')) {
    throw new RuntimeException('About page reading-layout styles are missing.');
}
if ($publicCss === false || !str_contains($publicCss, '.alpha-banner') || !str_contains($publicCss, '.site-nav, .alpha-banner, .page-header a')) {
    throw new RuntimeException('Global alpha banner styling or print exclusion is missing.');
}
$synthetic = MigrationFile::ordered([
    new MigrationFile('0010', 'later', 'later.sql', ''),
    new MigrationFile('0002', 'earlier', 'earlier.sql', ''),
]);
assertSameValue(['0002', '0010'], array_map(static fn (MigrationFile $migration): string => $migration->version, $synthetic), 'Synthetic migration ordering is incorrect.');
assertSameValue(['0010'], array_map(
    static fn (MigrationFile $migration): string => $migration->version,
    MigrationRunner::pending($synthetic, ['0002']),
), 'Pending migration selection is incorrect.');
assertThrows(
    static fn (): array => MigrationFile::ordered([
        new MigrationFile('0001', 'first', 'first.sql', ''),
        new MigrationFile('0001', 'second', 'second.sql', ''),
    ]),
    'Duplicate migration version was accepted.',
);
assertThrows(
    static fn (): array => MigrationFile::ordered([
        new MigrationFile('0001', 'same_name', 'first.sql', ''),
        new MigrationFile('0002', 'same_name', 'second.sql', ''),
    ]),
    'Duplicate migration name was accepted.',
);
assertSameValue('ok', HealthCheck::status(), 'Existing application health status changed.');

$accountMigration = file_get_contents($migrationDirectory . '/0003_add_pilot_accounts.sql');
if ($accountMigration === false) throw new RuntimeException('Account migration could not be read.');
foreach (['operational_role', 'is_admin', 'password_hash', 'account_state', 'users_login', 'awaiting_first_login', 'claimed'] as $expectedAccountFragment) {
    if (!str_contains($accountMigration, $expectedAccountFragment)) throw new RuntimeException('Expected account schema fragment is missing: ' . $expectedAccountFragment);
}
\Reqsheet\Tests\TimetableGenerationTest::run();
\Reqsheet\Tests\TimetableConfigurationTest::run();
\Reqsheet\Tests\PdoTeacherPlanningStoreTest::run();
\Reqsheet\Tests\ExternalEnvironmentTest::run();
\Reqsheet\Tests\RoutingTest::run();
\Reqsheet\Tests\AdminTimetablePageTest::run();
\Reqsheet\Tests\BlankTimetableCsvExporterTest::run();
\Reqsheet\Tests\TimetableCsvImportTest::run();
\Reqsheet\Tests\TimetableCsvConfirmTest::run();
\Reqsheet\Tests\AccountTest::run();
\Reqsheet\Tests\TenantTest::run();
\Reqsheet\Tests\SettingsTest::run();
\Reqsheet\Tests\OnboardingHandoffTest::run();
\Reqsheet\Tests\MyAccountTest::run();
\Reqsheet\Tests\AdminPeoplePageTest::run();
\Reqsheet\Tests\LoginPageTest::run();
\Reqsheet\Tests\TenantDataResetterTest::run();
\Reqsheet\Tests\TechnicianPageTest::run();
\Reqsheet\Tests\RecoveryTest::run();

fwrite(STDOUT, "Checks passed.\n");
