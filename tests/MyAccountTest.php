<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountStore;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Http\MyAccountPage;

final class MyAccountTest
{
    public static function run(): void
    {
        $store = new MyAccountStore();
        $service = new AccountService($store);
        $user = ['id' => 7, 'organisation_id' => 3, 'display_name' => 'Alex Smith', 'staff_identifier' => 'AS', 'operational_role' => 'teacher', 'is_admin' => false];
        $view = (new MyAccountPage($service, $user, new \DateTimeImmutable('2026-09-20', new \DateTimeZone('UTC'))))->handle('GET', []);
        assertContainsValue('My Account', $view, 'My Account heading was not rendered.');
        assertContainsValue('Alex Smith', $view, 'Own identity was not rendered.');
        assertContainsValue('AS', $view, 'Own staff code was not rendered.');
        assertContainsValue('Roles</dt><dd>Teacher, Technician', $view, 'Assigned roles were not rendered as human-readable labels.');
        assertNotContainsValue('email', strtolower($view), 'Individual staff email content remained on My Account.');
        assertContainsValue('School Account Status:</dt><dd>Not configured', $view, 'Missing organisation subscription state was not explicit.');
        $store->status = ['state' => 'free_trial', 'until' => '2026-09-25'];
        $trialView = (new MyAccountPage($service, $user, new \DateTimeImmutable('2026-09-20', new \DateTimeZone('UTC'))))->handle('GET', []);
        assertContainsValue('Free Trial — 5 days remaining', $trialView, 'Trial countdown was not derived from the organisation date.');
        $store->status = ['state' => 'paid', 'until' => '2026-10-01'];
        $paidView = (new MyAccountPage($service, $user, new \DateTimeImmutable('2026-09-20', new \DateTimeZone('UTC'))))->handle('GET', []);
        assertContainsValue('Paid Member — 11 days until renewal', $paidView, 'Paid renewal countdown was not derived from the organisation date.');
        self::expectValidation(static fn () => $service->changePassword(7, 3, 'wrong', 'new-pass', 'new-pass'));
        self::expectValidation(static fn () => $service->changePassword(7, 3, 'old-pass', 'short', 'short'));
        self::expectValidation(static fn () => $service->changePassword(7, 3, 'old-pass', 'new-pass', 'different'));
        self::expectValidation(static fn () => $service->changePassword(7, 9, 'old-pass', 'new-pass', 'new-pass'));
        $service->changePassword(7, 3, 'old-pass', 'new-pass', 'new-pass');
        assertSameValue(true, password_verify('new-pass', $store->account['password_hash']), 'Valid password change did not hash the new password.');
    }

    private static function expectValidation(callable $operation): void
    {
        try { $operation(); } catch (AccountValidationException) { return; }
        throw new \RuntimeException('Invalid password change was accepted.');
    }
}

final class MyAccountStore implements AccountStore
{
    public array $account;
    public array $status = ['state' => null, 'until' => null];
    public function __construct() { $this->account = ['id' => 7, 'organisation_id' => 3, 'display_name' => 'Alex Smith', 'staff_identifier' => 'AS', 'operational_role' => 'teacher', 'roles' => ['teacher', 'technician'], 'is_admin' => false, 'is_active' => true, 'password_hash' => password_hash('old-pass', PASSWORD_DEFAULT), 'account_state' => 'claimed']; }
    public function organisationCount(): int { return 1; }
    public function organisationExists(int $organisationId): bool { return $organisationId === 3; }
    public function organisationTenantSlugExists(string $tenantSlug): bool { return false; }
    public function findOrganisationTenantSlug(int $organisationId): ?string { return $organisationId === 3 ? 'school' : null; }
    public function findOrganisationByTenantSlug(string $tenantSlug): ?array { return $tenantSlug === 'school' ? ['id' => 3, 'name' => 'School', 'tenant_slug' => 'school'] : null; }
    public function findLogin(string $login, ?int $organisationId = null): ?array { return $login === 'Alex Smith' ? $this->account : null; }
    public function findUserById(int $userId): ?array { return $userId === 7 ? $this->account : null; }
    public function findPeopleForOrganisation(int $organisationId): array { return $organisationId === 3 ? [$this->account] : []; }
    public function activeAdministratorCount(int $organisationId): int { return 0; }
    public function createPerson(int $organisationId, string $displayName, string $staffIdentifier, array $roles): int { return 8; }
    public function updatePerson(int $organisationId, int $userId, string $displayName, string $staffIdentifier, array $roles): void {}
    public function createFirstOrganisation(string $organisationName, string $displayName, string $staffIdentifier, string $role, string $passwordHash, string $tenantSlug = ''): int { return 3; }
    public function createOrganisationAdmin(string $organisationName, string $displayName, string $staffIdentifier, string $role, string $passwordHash, string $tenantSlug = ''): int { return 3; }
    public function createUser(int $organisationId, string $displayName, ?string $staffIdentifier, string $role, bool $isAdmin): int { return 8; }
    public function claimFirstLogin(int $userId, string $passwordHash): void {}
    public function updatePassword(int $userId, int $organisationId, string $passwordHash): void { $this->account['password_hash'] = $passwordHash; }
    public function resetPassword(int $userId, int $organisationId): void { $this->account['password_hash'] = null; $this->account['account_state'] = 'awaiting_first_login'; }
    public function organisationAccountStatus(int $organisationId): array { return $organisationId === 3 ? $this->status : ['state' => null, 'until' => null]; }
}
