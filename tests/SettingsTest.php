<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\SetupBlockingPage;
use Reqsheet\Http\SetupPage;
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
        $service->save(1, [
            'school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 6,
        ]);
        assertSameValue([], $store->rooms, 'Settings unexpectedly created rooms during a minimal setup save.');
        $service->save(1, [
            'school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 6, 'rooms' => ['LAB-A'], 'allow_double_periods' => '1',
            'separator_type' => ['Break'], 'separator_after' => [2], 'separator_duration' => ['10'], 'date_format' => 'YYYY/MM/DD',
        ]);
        assertSameValue('Test School', $store->saved['school_name'], 'School name was not saved.');
        assertSameValue(['LAB-A'], $store->rooms, 'Room was not saved.');
        assertSameValue(true, $store->saved['allow_double_periods'], 'Double-period setting was not saved.');
        assertSameValue('YYYY/MM/DD', $store->saved['date_format'], 'Date format setting was not saved.');
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
        assertNotContainsValue('Contact email', $ninePeriodView, 'Organisation Settings still collected a contact email.');
        assertSameValue(1, substr_count($ninePeriodView, 'value="YYYY/MM/DD" selected'), 'Saved date format was not selected in Settings.');
        assertSameValue(1, substr_count($ninePeriodView, 'value="DD/MM/YYYY"'), 'Date format options did not include the default.');
        assertSameValue(1, substr_count($ninePeriodView, 'value="MM/DD/YYYY"'), 'Date format options did not include the US format.');

        $nonAdminSettings = (new SettingsPage(new SettingsService($store), 1, ['id' => 2, 'organisation_id' => 1, 'roles' => ['teacher']]))->handle('POST', ['date_format' => 'MM/DD/YYYY']);
        assertContainsValue('Administrator access is required.', $nonAdminSettings, 'Non-administrator modified organisation settings.');

        $transitionStore = new SettingsTransitionStoreFake();
        $settingsPage = new SettingsPage(new SettingsService($transitionStore), 1, ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true]);
        $beforeSetup = $settingsPage->handle('GET', []);
        assertContainsValue('Complete the organisation settings', $beforeSetup, 'Incomplete organisation did not render the setup form.');
        $afterSetup = $settingsPage->handle('POST', [
            'school_name' => 'Transition School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1,
            'periods_per_day' => 6,
        ]);
        assertContainsValue('Settings saved.', $afterSetup, 'Completed settings did not render the saved state.');
        assertNotContainsValue('Service unavailable', $afterSetup, 'Completed settings rendered an unavailable state.');
        assertContainsValue('value="Transition School"', $afterSetup, 'Completed settings were not loaded for the next page render.');
        assertContainsValue('value="08:00"', $beforeSetup, 'Fresh settings did not default the start time to 08:00.');
        assertContainsValue('Allow conjoined periods', $beforeSetup, 'Settings did not use conjoined-period wording.');
        assertNotContainsValue('data-add-room', $beforeSetup, 'Settings still offered room creation outside the timetable builder.');
        assertContainsValue('Standard period length', $beforeSetup, 'Optional timetable settings were not available after setup.');

        $initialSetupAccounts = new \Reqsheet\Account\AccountService(new \Reqsheet\Tests\AccountStoreFake());
        $initialSetup = new SetupPage($initialSetupAccounts, 'reqsheet.test', new OnboardingHandoffService(new OnboardingHandoffStoreFake()));
        $initialSetupView = $initialSetup->handle('GET', []);
        assertContainsValue('class="alpha-banner"', $initialSetupView, 'First-run setup did not render the global alpha banner.');
        assertNotContainsValue('name="rooms[]"', $initialSetupView, 'Initial setup still offered room creation.');
        assertContainsValue('You will be able to include additional settings such as the length of lessons from the settings menu after initial setup.', $initialSetupView, 'Initial setup did not explain later settings.');
        $initialSetupComplete = $initialSetup->handle('POST', [
            'organisation_name' => 'Minimal School', 'staff_identifier' => 'MAD',
            'operational_role' => 'teacher', 'password' => 'minimal-pass', 'password_confirmation' => 'minimal-pass', 'tenant_slug' => 'minimalschl',
        ]);
        assertContainsValue('https://minimalschl.reqsheet.test/onboarding?token=', $initialSetupComplete, 'Initial setup did not continue to tenant-bound recovery-key onboarding.');

        $templateSettings = new SettingsStoreFake();
        $templatePage = new SettingsPage(new SettingsService($templateSettings), 1, ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true], new ConfigurationStore());
        $templateEmpty = $templatePage->handle('GET', []);
        assertContainsValue('No active timetable template exists yet.', $templateEmpty, 'Settings did not show the empty active-template state.');
        assertNotContainsValue('name="periods_per_day"', $templateEmpty, 'Read-only template summary exposed the large editor by default.');
        $templateEditor = $templatePage->handle('POST', ['action' => 'create_template']);
        assertContainsValue('Create new timetable template', $templateEditor, 'Create-template workflow did not open from Settings.');
        assertContainsValue('name="template_label"', $templateEditor, 'Template creation did not ask for a timetable name.');
        assertContainsValue('name="working_days[]"', $templateEditor, 'Template creation did not ask for working days.');
        assertContainsValue('name="first_day_of_week"', $templateEditor, 'Template creation did not ask for the first day.');
        assertContainsValue('name="periods_per_day"', $templateEditor, 'Template creation did not ask for periods per day.');
        assertNotContainsValue('name="school_name"', $templateEditor, 'Template creation still asks for a school name.');
        assertNotContainsValue('name="start_time"', $templateEditor, 'Template creation still exposes timing controls.');
        assertNotContainsValue('name="effective_from"', $templateEditor, 'Template creation still exposes an effective date.');
        $templateSaved = $templatePage->handle('POST', [
            'action' => 'save_template', 'csrf_token' => \Reqsheet\Http\CsrfToken::value(), 'template_label' => 'Autumn settings template',
            'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1, 'periods_per_day' => 6,
        ]);
        assertContainsValue('No active timetable template exists yet.', $templateSaved, 'A template became active without explicit selection.');
        assertContainsValue('Autumn settings template', $templateSaved, 'Active template name was not summarized.');
        $activated = $templatePage->handle('POST', ['action' => 'activate_template', 'version_id' => 1, 'csrf_token' => \Reqsheet\Http\CsrfToken::value()]);
        assertContainsValue('Timetable activated.', $activated, 'Manual timetable activation did not complete.');
        assertContainsValue('Current active timetable template', $activated, 'Activated timetable was not summarized.');
        assertContainsValue('Working days:</strong>', $activated, 'Active timetable summary did not use separated labelled rows.');
        assertContainsValue('Periods per day:</strong>', $activated, 'Active timetable summary omitted periods per day.');
        assertContainsValue('Timings:</strong>', $activated, 'Active timetable summary omitted standard timings.');
        assertNotContainsValue('Custom day timings', $activated, 'Active timetable summary exposed removed custom timing terminology.');
        $warning = $templatePage->handle('POST', ['action' => 'edit_template']);
        assertContainsValue('Before editing the timetable template', $warning, 'Editing a template did not show the safety warning.');
        $fullEditor = $templatePage->handle('POST', ['action' => 'continue_edit_template', 'source_version_id' => 1]);
        assertContainsValue('Edit timetable template', $fullEditor, 'Template editing did not open after creation.');
        assertNotContainsValue('name="school_name"', $fullEditor, 'Timetable editing exposed the organisation school-name field.');
        assertContainsValue('name="standard_period_minutes"', $fullEditor, 'Full timetable editing lost timing controls.');
        assertNotContainsValue('custom_day_start', $fullEditor, 'Timetable editor still exposed custom day timing inputs.');
        assertNotContainsValue('Custom day timings', $fullEditor, 'Timetable editor still exposed custom day timing controls.');
        $settingsBeforeFailedTemplateSave = $templateSettings->saved;
        $failedTemplateSave = $templatePage->handle('POST', [
            'action' => 'save_template', 'csrf_token' => \Reqsheet\Http\CsrfToken::value(),
            'source_version_id' => 1, 'template_label' => 'Invalid edit',
            'working_days' => [], 'first_day_of_week' => 1, 'periods_per_day' => 6,
        ]);
        assertContainsValue('Select at least one working day.', $failedTemplateSave, 'Invalid timetable edit did not report its field error.');
        assertSameValue($settingsBeforeFailedTemplateSave, $templateSettings->saved, 'Failed timetable edit partially modified organisation settings.');

        $signupStore = new \Reqsheet\Tests\AccountStoreFake();
        $signupAccounts = new \Reqsheet\Account\AccountService($signupStore);
        $signupAccounts->createFirstOrganisation('Existing School', 'EAD', 'teacher', 'existing-pass', 'existing-pass');
        $signup = new SignupPage($signupAccounts, 'reqsheet.test', new OnboardingHandoffService(new OnboardingHandoffStoreFake()));
        $signupView = $signup->handle('GET', []);
        assertContainsValue('School name', $signupView, 'Signup did not ask for a school name.');
        assertContainsValue('School short code', $signupView, 'Signup did not use school-facing short-code language.');
        assertContainsValue('Use 3–12 lowercase letters or numbers.', $signupView, 'Signup short-code guidance was not rendered.');
        assertContainsValue('sch4', $signupView, 'Signup did not show a short-code example.');
        assertContainsValue('sch4.reqsheet.test', $signupView, 'Signup did not show the configured public domain in its example.');
        assertNotContainsValue('Tenant slug', $signupView, 'Signup exposed internal tenant-slug terminology.');
        assertNotContainsValue('tenant identity', strtolower($signupView), 'Signup exposed internal tenant terminology.');
        assertNotContainsValue('email', strtolower($signupView), 'Signup still collected an email address.');
        $created = $signup->handle('POST', ['school_name' => 'Pilot School', 'tenant_slug' => 'pilotschool', 'staff_identifier' => 'PAD', 'operational_role' => 'teacher', 'password' => 'pilot-pass', 'password_confirmation' => 'pilot-pass']);
        assertContainsValue('https://pilotschool.reqsheet.test/onboarding?token=', $created, 'Successful signup did not hand off to the tenant host.');
        $newDomainSignup = new SignupPage($signupAccounts, 'reqsheet.com', new OnboardingHandoffService(new OnboardingHandoffStoreFake()));
        $newDomainPreview = $newDomainSignup->handle('GET', []);
        assertContainsValue('sch4.reqsheet.com', $newDomainPreview, 'Signup did not use the canonical new public domain.');
        $newDomainCreated = $newDomainSignup->handle('POST', ['school_name' => 'New Domain School', 'tenant_slug' => 'newdomain', 'staff_identifier' => 'NDA', 'operational_role' => 'teacher', 'password' => 'new-domain-pass', 'password_confirmation' => 'new-domain-pass']);
        assertContainsValue('https://newdomain.reqsheet.com/onboarding?token=', $newDomainCreated, 'Signup from the new domain did not generate a canonical tenant handoff.');
        assertSameValue(true, (bool) $signupStore->accounts['PAD']['is_admin'], 'Signup did not create an admin account.');
        assertSameValue(2, $signupStore->accounts['PAD']['organisation_id'], 'Public signup did not create a second organisation.');
        assertSameValue('pilotschool', $signupStore->accounts['PAD']['tenant_slug'], 'Public signup did not store the tenant slug.');
        assertSameValue(1, $signupStore->accounts['EAD']['organisation_id'], 'Public signup leaked or changed the existing tenant.');
        assertSameValue(null, \Reqsheet\Http\SessionAuth::current(), 'Public signup left a generic-host session active during tenant handoff.');
        self::expectAccountValidation(static fn () => $signupAccounts->createFirstOrganisation('Third School', 'Third Admin', 'TAD', 'teacher', 'third-pass', 'third-pass'), 'Legacy bootstrap became available after public signup.');
        foreach (['www', 'WWW', 'Www'] as $reservedSlug) {
            self::expectAccountValidation(static fn () => $signupAccounts->createOrganisationAdmin('Reserved School', 'RSA', 'teacher', 'reserved-pass', 'reserved-pass', $reservedSlug), 'Reserved www school short code was accepted: ' . $reservedSlug);
        }
        $reservedSignupView = $signup->handle('POST', ['school_name' => 'Reserved School', 'tenant_slug' => 'WWW', 'staff_identifier' => 'RSV', 'operational_role' => 'teacher', 'password' => 'reserved-pass', 'password_confirmation' => 'reserved-pass']);
        assertContainsValue('This school short code is reserved. Please choose another.', $reservedSignupView, 'Signup did not display the reserved www error.');
        \Reqsheet\Http\SessionAuth::logout();
        $home = (new HomePage())->render();
        assertContainsValue('Fast. Clean. Simple.', $home, 'Public homepage tagline was not rendered.');
        assertContainsValue('href="/signup"', $home, 'Public homepage signup action was not rendered.');
        assertContainsValue('Already have an account?', $home, 'Public homepage account guidance was not rendered.');
        assertContainsValue('Reqsheet alpha. Testing phase. Expect the unexpected. Do not rely upon this resource (yet). Feedback appreciated', $home, 'Public homepage alpha warning was not rendered.');
        assertContainsValue('mailto:feedback@reqsheet.com', $home, 'Public homepage feedback address was not a mailto link.');
        assertNotContainsValue('action="/login"', $home, 'Public homepage still renders the login form.');
        $authenticatedHome = (new HomePage())->render(['id' => 2, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => false, 'roles' => ['teacher']]);
        assertContainsValue('View My Timetable', $authenticatedHome, 'Authenticated homepage lost the application navigation.');
        assertNotContainsValue('href="/signup"', $authenticatedHome, 'Authenticated homepage exposed public signup navigation.');

        $blocked = (new SetupBlockingPage())->render(['is_admin' => false]);
        assertContainsValue("Something's missing...", $blocked, 'Setup blocking heading was not rendered.');
        assertContainsValue('Settings need to be configured. Contact your admin.', $blocked, 'Setup blocking message was not rendered.');
        $adminNav = \Reqsheet\Http\PageLayout::render('Admin', '<p>Admin</p>', ['id' => 1, 'organisation_id' => 1, 'operational_role' => 'teacher', 'is_admin' => true]);
        assertContainsValue('href="/settings"', $adminNav, 'Admin navigation did not expose Settings.');
        assertContainsValue('My Account', $adminNav, 'Authenticated navigation did not expose My Account.');
        assertContainsValue('View My Timetable', $adminNav, 'Teacher navigation label was not updated.');
        assertContainsValue('Reqsheet α', $adminNav, 'Shared application branding did not include the alpha marker.');
        assertContainsValue('class="alpha-banner"', $adminNav, 'Authenticated layout did not render the global alpha banner.');
        assertContainsValue('href="/alpha">here</a>', $adminNav, 'Authenticated alpha banner did not link to the information page.');
        assertContainsValue('nav-separator', $adminNav, 'Authenticated navigation did not render its general/application separator.');
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
    public array $active = [];

    public function find(int $organisationId): array
    {
        return ['school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1, 'periods_per_day' => $this->saved['periods_per_day'] ?? 6, 'start_time' => $this->saved['start_time'] ?? '', 'rooms' => $this->rooms, 'custom_day_settings' => [], 'separators' => $this->saved['separators'] ?? [], 'allow_double_periods' => $this->saved['allow_double_periods'] ?? false, 'date_format' => $this->saved['date_format'] ?? 'DD/MM/YYYY'];
    }

    public function activeVersionId(int $organisationId): ?int { return $this->active[$organisationId] ?? null; }
    public function activateVersion(int $organisationId, int $versionId): void { $this->active[$organisationId] = $versionId; }

    public function save(int $organisationId, array $settings, ?array $rooms = null): void
    {
        $this->saved = $settings;
        if ($rooms !== null) $this->rooms = $rooms;
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

    public function save(int $organisationId, array $settings, ?array $rooms = null): void
    {
        $this->settings = $settings;
        if ($rooms !== null) $this->rooms = $rooms;
        $this->complete = true;
    }
}
