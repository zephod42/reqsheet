<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\SetupBlockingPage;
use Reqsheet\Http\SignupPage;
use Reqsheet\Http\HomePage;
use Reqsheet\Http\SettingsPage;
use Reqsheet\Auth\OnboardingHandoffService;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Settings\OrganisationSettingsStore;
use Reqsheet\Settings\SettingsService;
use Reqsheet\Settings\SettingsValidationException;

final class SettingsTest
{
    public static function run(): void
    {
        $store = new SettingsStoreFake();
        $service = new SettingsService($store);
        self::expectValidation(static fn () => $service->save(1, [
            'school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 6, 'rooms' => [],
        ]), 'Settings accepted without a room.');
        $service->save(1, [
            'school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 6, 'rooms' => ['LAB-A'], 'allow_double_periods' => '1',
            'separator_type' => ['Break'], 'separator_after' => [2], 'separator_duration' => ['10'],
        ]);
        assertSameValue('Test School', $store->saved['school_name'], 'School name was not saved.');
        assertSameValue(['LAB-A'], $store->rooms, 'Room was not saved.');
        assertSameValue(true, $store->saved['allow_double_periods'], 'Double-period setting was not saved.');
        assertSameValue('Break', $store->saved['separators'][0]['type'], 'Separator type was not saved.');
        $service->save(1, [
            'school_name' => 'Nine Period School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 9, 'rooms' => ['LAB-A'], 'separator_type' => ['Break'],
            'separator_after' => [6], 'separator_duration' => ['15'], 'allow_conjoined_periods' => '1',
        ]);
        assertSameValue(9, $store->saved['periods_per_day'], 'Nine-period settings were not saved.');
        assertSameValue(6, $store->saved['separators'][0]['after_period'], 'A separator after period 6 was not saved.');
        assertSameValue(true, $store->saved['allow_double_periods'], 'Conjoined-period setting was not saved.');
        $ninePeriodView = (new SettingsPage(new SettingsService($store), 1, ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true]))->handle('GET', []);
        assertContainsValue('After period 8', $ninePeriodView, 'Nine-period settings did not expose the period 8/9 separator boundary.');

        $transitionStore = new SettingsTransitionStoreFake();
        $settingsPage = new SettingsPage(new SettingsService($transitionStore), 1, ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true]);
        $beforeSetup = $settingsPage->handle('GET', []);
        assertContainsValue('Complete the organisation settings', $beforeSetup, 'Incomplete organisation did not render the setup form.');
        $afterSetup = $settingsPage->handle('POST', [
            'school_name' => 'Transition School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 6, 'rooms' => ['LAB-A'],
        ]);
        assertContainsValue('Settings saved.', $afterSetup, 'Completed settings did not render the saved state.');
        assertNotContainsValue('Service unavailable', $afterSetup, 'Completed settings rendered an unavailable state.');
        assertContainsValue('value="Transition School"', $afterSetup, 'Completed settings were not loaded for the next page render.');
        assertContainsValue('value="08:00"', $beforeSetup, 'Fresh settings did not default the start time to 08:00.');
        assertContainsValue('Allow conjoined periods', $beforeSetup, 'Settings did not use conjoined-period wording.');

        $templateSettings = new SettingsStoreFake();
        $templatePage = new SettingsPage(new SettingsService($templateSettings), 1, ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true], new ConfigurationStore());
        $templateEmpty = $templatePage->handle('GET', []);
        assertContainsValue('No active timetable template exists yet.', $templateEmpty, 'Settings did not show the empty active-template state.');
        assertNotContainsValue('name="periods_per_day"', $templateEmpty, 'Read-only template summary exposed the large editor by default.');
        $templateEditor = $templatePage->handle('POST', ['action' => 'create_template']);
        assertContainsValue('Create new timetable template', $templateEditor, 'Create-template workflow did not open from Settings.');
        $templateSaved = $templatePage->handle('POST', [
            'action' => 'save_template', 'template_label' => 'Autumn settings template', 'effective_from' => '2026-09-01',
            'school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 6, 'rooms' => ['LAB-A'], 'start_time' => '08:00', 'standard_period_minutes' => '60',
            'separator_type' => ['Break', 'Lunchtime'], 'separator_label' => ['Break', 'Lunch'], 'separator_after' => [2, 4], 'separator_duration' => ['10', '30'], 'allow_conjoined_periods' => '1',
        ]);
        assertContainsValue('Current active timetable template', $templateSaved, 'Saved template did not return to the active-template summary.');
        assertContainsValue('Autumn settings template', $templateSaved, 'Active template name was not summarized.');
        $warning = $templatePage->handle('POST', ['action' => 'edit_template']);
        assertContainsValue('Before editing the timetable template', $warning, 'Editing a template did not show the safety warning.');

        $signupStore = new \Reqsheet\Tests\AccountStoreFake();
        $signupAccounts = new \Reqsheet\Account\AccountService($signupStore);
        $signupAccounts->createFirstOrganisation('Existing School', 'Existing Admin', null, 'teacher', 'existing-pass', 'existing-pass');
        $signup = new SignupPage($signupAccounts, 'reqsheet.test', new OnboardingHandoffService(new OnboardingHandoffStoreFake()));
        $signupView = $signup->handle('GET', []);
        assertContainsValue('School name', $signupView, 'Signup did not ask for a school name.');
        assertNotContainsValue('email', strtolower($signupView), 'Signup unexpectedly requires email.');
        $created = $signup->handle('POST', ['school_name' => 'Pilot School', 'tenant_slug' => 'pilot-school', 'display_name' => 'Pilot Admin', 'operational_role' => 'teacher', 'password' => 'pilot-pass', 'password_confirmation' => 'pilot-pass']);
        assertContainsValue('https://pilot-school.reqsheet.test/onboarding?token=', $created, 'Successful signup did not hand off to the tenant host.');
        assertSameValue(true, (bool) $signupStore->accounts['Pilot Admin']['is_admin'], 'Signup did not create an admin account.');
        assertSameValue(2, $signupStore->accounts['Pilot Admin']['organisation_id'], 'Public signup did not create a second organisation.');
        assertSameValue('pilot-school', $signupStore->accounts['Pilot Admin']['tenant_slug'], 'Public signup did not store the tenant slug.');
        assertSameValue(1, $signupStore->accounts['Existing Admin']['organisation_id'], 'Public signup leaked or changed the existing tenant.');
        assertSameValue(null, \Reqsheet\Http\SessionAuth::current(), 'Public signup left a generic-host session active during tenant handoff.');
        self::expectAccountValidation(static fn () => $signupAccounts->createFirstOrganisation('Third School', 'Third Admin', null, 'teacher', 'third-pass', 'third-pass'), 'Legacy bootstrap became available after public signup.');
        \Reqsheet\Http\SessionAuth::logout();
        assertContainsValue('action="/login"', (new HomePage())->render(), 'Home login form did not post to /login.');

        $blocked = (new SetupBlockingPage())->render(['is_admin' => false]);
        assertContainsValue("Something's missing...", $blocked, 'Setup blocking heading was not rendered.');
        assertContainsValue('Settings need to be configured. Contact your admin.', $blocked, 'Setup blocking message was not rendered.');
        $adminNav = \Reqsheet\Http\PageLayout::render('Admin', '<p>Admin</p>', ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true]);
        assertContainsValue('href="/settings"', $adminNav, 'Admin navigation did not expose Settings.');
        assertContainsValue('My Account', $adminNav, 'Authenticated navigation did not expose My Account.');
        assertNotContainsValue('href="/signup"', $adminNav, 'Sign up remained in authenticated navigation.');
        assertContainsValue('href="/settings"', \Reqsheet\Http\PageLayout::render('About', '<p>About</p>', ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true]), 'Authenticated informational layout lost Settings.');
        $teacherNav = \Reqsheet\Http\PageLayout::render('Teacher', '<p>Teacher</p>', ['id' => 2, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => false]);
        assertContainsValue('nav-disabled', $teacherNav, 'Non-admin navigation did not retain disabled admin destinations.');
    }

    private static function expectValidation(callable $operation, string $message): void
    {
        try { $operation(); } catch (SettingsValidationException) { return; }
        throw new \RuntimeException($message);
    }

    private static function expectAccountValidation(callable $operation, string $message): void
    {
        try { $operation(); } catch (AccountValidationException) { return; }
        throw new \RuntimeException($message);
    }
}

final class SettingsStoreFake implements OrganisationSettingsStore
{
    public array $saved = [];
    public array $rooms = [];

    public function find(int $organisationId): array
    {
        return ['school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1, 'periods_per_day' => $this->saved['periods_per_day'] ?? 6, 'start_time' => $this->saved['start_time'] ?? '', 'rooms' => $this->rooms, 'custom_day_settings' => [], 'separators' => $this->saved['separators'] ?? [], 'allow_double_periods' => $this->saved['allow_double_periods'] ?? false];
    }

    public function save(int $organisationId, array $settings, array $rooms): void
    {
        $this->saved = $settings;
        $this->rooms = $rooms;
    }
}

final class SettingsTransitionStoreFake implements OrganisationSettingsStore
{
    private bool $complete = false;
    private array $settings = [];
    private array $rooms = [];

    public function find(int $organisationId): array
    {
        return [
            'school_name' => $this->settings['school_name'] ?? 'New School',
            'working_days' => $this->settings['working_days'] ?? [],
            'first_day_of_week' => $this->settings['first_day_of_week'] ?? 1,
            'periods_per_day' => $this->settings['periods_per_day'] ?? 6,
            'rooms' => $this->rooms,
            'custom_day_settings' => [],
            'separators' => [],
            'allow_double_periods' => false,
            'complete' => $this->complete,
        ];
    }

    public function save(int $organisationId, array $settings, array $rooms): void
    {
        $this->settings = $settings;
        $this->rooms = $rooms;
        $this->complete = true;
    }
}
