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
        $view = (new MyAccountPage($service, $user))->handle('GET', []);
        assertContainsValue('My Account', $view, 'My Account heading was not rendered.');
        assertContainsValue('Alex Smith', $view, 'Own identity was not rendered.');
        assertContainsValue('AS', $view, 'Own staff code was not rendered.');
        assertContainsValue('No email address has been entered.', $view, 'Missing optional email was not explained.');
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
    public function __construct() { $this->account = ['id' => 7, 'organisation_id' => 3, 'display_name' => 'Alex Smith', 'staff_identifier' => 'AS', 'operational_role' => 'teacher', 'is_admin' => false, 'is_active' => true, 'password_hash' => password_hash('old-pass', PASSWORD_DEFAULT), 'account_state' => 'claimed']; }
    public function organisationCount(): int { return 1; }
    public function organisationExists(int $organisationId): bool { return $organisationId === 3; }
    public function organisationTenantSlugExists(string $tenantSlug): bool { return false; }
    public function findOrganisationTenantSlug(int $organisationId): ?string { return $organisationId === 3 ? 'school' : null; }
    public function findOrganisationByTenantSlug(string $tenantSlug): ?array { return $tenantSlug === 'school' ? ['id' => 3, 'name' => 'School', 'tenant_slug' => 'school'] : null; }
    public function findLogin(string $login): ?array { return $login === 'Alex Smith' ? $this->account : null; }
    public function findUserById(int $userId): ?array { return $userId === 7 ? $this->account : null; }
    public function createFirstOrganisation(string $organisationName, string $displayName, ?string $staffIdentifier, string $role, string $passwordHash, string $tenantSlug = ''): int { return 3; }
    public function createOrganisationAdmin(string $organisationName, string $displayName, ?string $staffIdentifier, string $role, string $passwordHash, string $tenantSlug = ''): int { return 3; }
    public function createUser(int $organisationId, string $displayName, ?string $staffIdentifier, string $role, bool $isAdmin): int { return 8; }
    public function claimFirstLogin(int $userId, string $passwordHash): void {}
    public function updatePassword(int $userId, int $organisationId, string $passwordHash): void { $this->account['password_hash'] = $passwordHash; }
}
