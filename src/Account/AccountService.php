<?php

declare(strict_types=1);

namespace Reqsheet\Account;

final class AccountService
{
    public const MIN_PASSWORD_LENGTH = 8;

    public function __construct(private readonly AccountStore $store)
    {
    }

    public function setupAvailable(): bool
    {
        return $this->store->organisationCount() === 0;
    }

    public function createFirstOrganisation(
        string $organisationName,
        string $displayName,
        ?string $staffIdentifier,
        string $role,
        string $password,
        string $confirmation,
    ): int {
        $this->validateIdentity($organisationName, $displayName, $role);
        $this->validatePassword($password, $confirmation);
        if (!$this->setupAvailable()) throw new AccountValidationException(['First-run setup is no longer available.']);
        return $this->store->createFirstOrganisation(
            trim($organisationName), trim($displayName), $this->nullable($staffIdentifier), $role,
            password_hash($password, PASSWORD_DEFAULT),
        );
    }

    public function createOrganisationAdmin(
        string $organisationName,
        string $displayName,
        string $role,
        string $password,
        string $confirmation,
    ): int {
        $this->validateIdentity($organisationName, $displayName, $role);
        $this->validatePassword($password, $confirmation);
        return $this->store->createOrganisationAdmin(
            trim($organisationName), trim($displayName), null, $role,
            password_hash($password, PASSWORD_DEFAULT),
        );
    }

    public function createUser(int $organisationId, string $displayName, ?string $staffIdentifier, string $role, bool $isAdmin): int
    {
        $this->validateIdentity('Organisation', $displayName, $role);
        if ($organisationId < 1 || !$this->store->organisationExists($organisationId)) throw new AccountValidationException(['Organisation is invalid.']);
        return $this->store->createUser($organisationId, trim($displayName), $this->nullable($staffIdentifier), $role, $isAdmin);
    }

    /** @return array<string, mixed> */
    public function authenticate(string $login, string $password): array
    {
        $account = $this->store->findLogin(trim($login));
        if ($account === null || !($account['is_active'] ?? false)) throw new AccountValidationException(['Invalid login details.']);
        if (($account['account_state'] ?? '') === 'awaiting_first_login' && ($account['password_hash'] ?? null) === null) {
            throw new AccountValidationException(['This account is awaiting its first login password.']);
        }
        if (!is_string($account['password_hash'] ?? null) || !password_verify($password, $account['password_hash'])) {
            throw new AccountValidationException(['Invalid login details.']);
        }
        return $account;
    }

    public function needsFirstLogin(string $login): bool
    {
        $account = $this->store->findLogin(trim($login));
        return $account !== null && ($account['is_active'] ?? false)
            && ($account['account_state'] ?? '') === 'awaiting_first_login'
            && ($account['password_hash'] ?? null) === null;
    }

    public function claimFirstLogin(string $login, string $password, string $confirmation): array
    {
        $account = $this->store->findLogin(trim($login));
        if ($account === null || !($account['is_active'] ?? false) || ($account['account_state'] ?? '') !== 'awaiting_first_login' || ($account['password_hash'] ?? null) !== null) {
            throw new AccountValidationException(['This account has already been claimed or is unavailable.']);
        }
        $this->validatePassword($password, $confirmation);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $this->store->claimFirstLogin((int) $account['id'], $passwordHash);
        $account['password_hash'] = $passwordHash;
        $account['account_state'] = 'claimed';
        return $account;
    }

    private function validateIdentity(string $organisation, string $displayName, string $role): void
    {
        $errors = [];
        if (trim($organisation) === '') $errors[] = 'Organisation name must not be blank.';
        if (trim($displayName) === '') $errors[] = 'User name/login must not be blank.';
        if (!in_array($role, ['teacher', 'technician'], true)) $errors[] = 'Operational role must be Teacher or Technician.';
        if ($errors !== []) throw new AccountValidationException($errors);
    }

    private function validatePassword(string $password, string $confirmation): void
    {
        if (strlen($password) < self::MIN_PASSWORD_LENGTH) throw new AccountValidationException(['Password must be at least 8 characters.']);
        if ($password !== $confirmation) throw new AccountValidationException(['Password confirmation does not match.']);
    }

    private function nullable(?string $value): ?string
    {
        $value = $value === null ? '' : trim($value);
        return $value === '' ? null : $value;
    }
}
