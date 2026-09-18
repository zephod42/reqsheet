<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\SetupBlockingPage;
use Reqsheet\Http\SignupPage;
use Reqsheet\Http\HomePage;
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

        $signupStore = new \Reqsheet\Tests\AccountStoreFake();
        $signupAccounts = new \Reqsheet\Account\AccountService($signupStore);
        $signupAccounts->createFirstOrganisation('Existing School', 'Existing Admin', null, 'teacher', 'existing-pass', 'existing-pass');
        $signup = new SignupPage($signupAccounts);
        $signupView = $signup->handle('GET', []);
        assertContainsValue('School name', $signupView, 'Signup did not ask for a school name.');
        assertNotContainsValue('email', strtolower($signupView), 'Signup unexpectedly requires email.');
        $created = $signup->handle('POST', ['school_name' => 'Pilot School', 'display_name' => 'Pilot Admin', 'operational_role' => 'teacher', 'password' => 'pilot-pass', 'password_confirmation' => 'pilot-pass']);
        assertContainsValue('/settings', $created, 'Successful signup did not route the first admin to settings.');
        assertSameValue(true, (bool) $signupStore->accounts['Pilot Admin']['is_admin'], 'Signup did not create an admin account.');
        assertSameValue(2, $signupStore->accounts['Pilot Admin']['organisation_id'], 'Public signup did not create a second organisation.');
        assertSameValue(1, $signupStore->accounts['Existing Admin']['organisation_id'], 'Public signup leaked or changed the existing tenant.');
        assertSameValue(2, \Reqsheet\Http\SessionAuth::current()['organisation_id'], 'Public signup did not authenticate the new admin tenant.');
        self::expectAccountValidation(static fn () => $signupAccounts->createFirstOrganisation('Third School', 'Third Admin', null, 'teacher', 'third-pass', 'third-pass'), 'Legacy bootstrap became available after public signup.');
        \Reqsheet\Http\SessionAuth::logout();
        assertContainsValue('action="/login"', (new HomePage())->render(), 'Home login form did not post to /login.');

        $blocked = (new SetupBlockingPage())->render(['is_admin' => false]);
        assertContainsValue("Something's missing...", $blocked, 'Setup blocking heading was not rendered.');
        assertContainsValue('Settings need to be configured. Contact your admin.', $blocked, 'Setup blocking message was not rendered.');
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
        return ['school_name' => 'Test School', 'working_days' => [1, 2, 3, 4, 5], 'first_day_of_week' => 1, 'periods_per_day' => 6, 'rooms' => $this->rooms, 'custom_day_settings' => [], 'separators' => [], 'allow_double_periods' => false];
    }

    public function save(int $organisationId, array $settings, array $rooms): void
    {
        $this->saved = $settings;
        $this->rooms = $rooms;
    }
}
