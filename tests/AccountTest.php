<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountStore;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Http\SessionAuth;
use Reqsheet\Http\SetupAccess;

final class AccountTest
{
    public static function run(): void
    {
        $store = new AccountStoreFake();
        $accounts = new AccountService($store);
        assertSameValue(true, $accounts->setupAvailable(), 'Setup was not available with no organisations.');
        assertSameValue(true, SetupAccess::allowed(['REQSHEET_SETUP_KEY' => 'setup'], ['PHP_AUTH_USER' => 'setup', 'PHP_AUTH_PW' => 'setup']), 'Setup key was not accepted.');

        $organisationId = $accounts->createFirstOrganisation('Test School', 'Niall Evans', 'NE', 'teacher', 'pilot-pass', 'pilot-pass');
        assertSameValue(1, $organisationId, 'First organisation was not created.');
        assertSameValue(false, $accounts->setupAvailable(), 'Setup remained available after organisation creation.');
        assertSameValue(true, password_verify('pilot-pass', (string) $store->accounts['Niall Evans']['password_hash']), 'Password was not hashed.');
        assertSameValue(false, str_contains((string) $store->accounts['Niall Evans']['password_hash'], 'pilot-pass'), 'Plaintext password was stored.');
        assertSameValue(true, (bool) $store->accounts['Niall Evans']['is_admin'], 'First user was not made admin.');
        assertSameValue('NE', $store->accounts['Niall Evans']['staff_identifier'], 'First-user abbreviation was not stored.');

        $account = $accounts->authenticate('Niall Evans', 'pilot-pass');
        assertSameValue('/teacher', SessionAuth::landingPath($account), 'Teacher landing path was incorrect.');
        assertSameValue('/technician', SessionAuth::landingPath(['operational_role' => 'technician', 'is_admin' => true]), 'Technician admin landing path was incorrect.');
        assertSameValue(true, SessionAuth::hasRole($account, 'teacher'), 'Teacher role was not recognised.');
        assertSameValue(false, SessionAuth::hasRole($account, 'technician'), 'Teacher was granted technician access.');
        assertSameValue(true, SessionAuth::isAdmin($account), 'Admin permission was not recognised.');
        self::expectValidation(static fn () => $accounts->authenticate('Niall Evans', 'wrong-pass'));
        $store->accounts['Niall Evans']['is_active'] = false;
        self::expectValidation(static fn () => $accounts->authenticate('Niall Evans', 'pilot-pass'));
        $store->accounts['Niall Evans']['is_active'] = true;

        $created = $accounts->createUser(1, 'New Technician', null, 'technician', false);
        assertSameValue(2, $created, 'Admin-created user was not created.');
        assertSameValue('awaiting_first_login', $store->accounts['New Technician']['account_state'], 'Unclaimed account state was not explicit.');
        self::expectValidation(static fn () => $accounts->authenticate('New Technician', 'anything'));
        $claimed = $accounts->claimFirstLogin('New Technician', 'new-pass', 'new-pass');
        assertSameValue('claimed', $claimed['account_state'], 'First-login claim did not claim account.');
        assertSameValue(true, password_verify('new-pass', (string) $store->accounts['New Technician']['password_hash']), 'Claimed password was not hashed.');
        self::expectValidation(static fn () => $accounts->claimFirstLogin('New Technician', 'again-pass', 'again-pass'));

        session_save_path(sys_get_temp_dir());
        SessionAuth::login($account);
        assertSameValue($account['id'], SessionAuth::current()['id'], 'Authenticated session did not retain the user.');
        SessionAuth::logout();
        assertSameValue(null, SessionAuth::current(), 'Logout did not clear authentication.');
    }

    private static function expectValidation(callable $operation): void
    {
        try { $operation(); } catch (AccountValidationException) { return; }
        throw new \RuntimeException('Invalid account operation was accepted.');
    }
}

final class AccountStoreFake implements AccountStore
{
    /** @var array<string, array<string, mixed>> */
    public array $accounts = [];
    private int $nextId = 1;

    public function organisationCount(): int { return $this->accounts === [] ? 0 : 1; }
    public function organisationExists(int $organisationId): bool { return $organisationId === 1 && $this->organisationCount() > 0; }
    public function findLogin(string $login): ?array { return $this->accounts[$login] ?? null; }
    public function createFirstOrganisation(string $organisationName, string $displayName, ?string $staffIdentifier, string $role, string $passwordHash): int { $this->accounts[$displayName] = ['id' => $this->nextId++, 'organisation_id' => 1, 'display_name' => $displayName, 'staff_identifier' => $staffIdentifier, 'operational_role' => $role, 'is_admin' => true, 'password_hash' => $passwordHash, 'account_state' => 'claimed', 'is_active' => true]; return 1; }
    public function createUser(int $organisationId, string $displayName, ?string $staffIdentifier, string $role, bool $isAdmin): int { $id = $this->nextId++; $this->accounts[$displayName] = ['id' => $id, 'organisation_id' => $organisationId, 'display_name' => $displayName, 'operational_role' => $role, 'is_admin' => $isAdmin, 'password_hash' => null, 'account_state' => 'awaiting_first_login', 'is_active' => true]; return $id; }
    public function claimFirstLogin(int $userId, string $passwordHash): void { foreach ($this->accounts as &$account) { if ($account['id'] === $userId) { $account['password_hash'] = $passwordHash; $account['account_state'] = 'claimed'; } } unset($account); }
}
