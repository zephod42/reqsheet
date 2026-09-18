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
        ?string $tenantSlug = null,
    ): int {
        $this->validateIdentity($organisationName, $displayName, $role);
        $this->validatePassword($password, $confirmation);
        $tenantSlug = $this->validatedTenantSlug($organisationName, $tenantSlug);
        if (!$this->setupAvailable()) throw new AccountValidationException(['First-run setup is no longer available.']);
        return $this->store->createFirstOrganisation(
            trim($organisationName), trim($displayName), $this->nullable($staffIdentifier), $role,
            password_hash($password, PASSWORD_DEFAULT), $tenantSlug,
        );
    }

    public function createOrganisationAdmin(
        string $organisationName,
        string $displayName,
        string $role,
        string $password,
        string $confirmation,
        ?string $tenantSlug = null,
    ): int {
        $this->validateIdentity($organisationName, $displayName, $role);
        $this->validatePassword($password, $confirmation);
        $tenantSlug = $this->validatedTenantSlug($organisationName, $tenantSlug);
        return $this->store->createOrganisationAdmin(
            trim($organisationName), trim($displayName), null, $role,
            password_hash($password, PASSWORD_DEFAULT), $tenantSlug,
        );
    }

    public function createUser(int $organisationId, string $displayName, ?string $staffIdentifier, string $role, bool $isAdmin): int
    {
        $this->validateIdentity('Organisation', $displayName, $role);
        if ($organisationId < 1 || !$this->store->organisationExists($organisationId)) throw new AccountValidationException(['Organisation is invalid.']);
        return $this->store->createUser($organisationId, trim($displayName), $this->nullable($staffIdentifier), $role, $isAdmin);
    }

    /** @return array<string, mixed> */
    public function authenticate(string $login, string $password, ?int $organisationId = null): array
    {
        $account = $this->store->findLogin(trim($login));
        if ($account === null || !($account['is_active'] ?? false) || ($organisationId !== null && (int) ($account['organisation_id'] ?? 0) !== $organisationId)) throw new AccountValidationException(['Invalid login details.']);
        if (($account['account_state'] ?? '') === 'awaiting_first_login' && ($account['password_hash'] ?? null) === null) {
            throw new AccountValidationException(['This account is awaiting its first login password.']);
        }
        if (!is_string($account['password_hash'] ?? null) || !password_verify($password, $account['password_hash'])) {
            throw new AccountValidationException(['Invalid login details.']);
        }
        return $account;
    }

    /** @return array<string, mixed>|null */
    public function findUserById(int $userId): ?array
    {
        return $this->store->findUserById($userId);
    }

    public function organisationTenantSlug(int $organisationId): ?string
    {
        return $this->store->findOrganisationTenantSlug($organisationId);
    }

    public function needsFirstLogin(string $login, ?int $organisationId = null): bool
    {
        $account = $this->store->findLogin(trim($login));
        return $account !== null && ($account['is_active'] ?? false)
            && ($organisationId === null || (int) ($account['organisation_id'] ?? 0) === $organisationId)
            && ($account['account_state'] ?? '') === 'awaiting_first_login'
            && ($account['password_hash'] ?? null) === null;
    }

    public function claimFirstLogin(string $login, string $password, string $confirmation, ?int $organisationId = null): array
    {
        $account = $this->store->findLogin(trim($login));
        if ($account === null || !($account['is_active'] ?? false) || ($organisationId !== null && (int) ($account['organisation_id'] ?? 0) !== $organisationId) || ($account['account_state'] ?? '') !== 'awaiting_first_login' || ($account['password_hash'] ?? null) !== null) {
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

    private function validatedTenantSlug(string $organisationName, ?string $tenantSlug): string
    {
        $candidate = $tenantSlug === null || trim($tenantSlug) === ''
            ? TenantSlug::suggest($organisationName)
            : trim($tenantSlug);
        try {
            $slug = TenantSlug::normalise($candidate);
        } catch (AccountValidationException $exception) {
            throw $exception;
        }
        if ($this->store->organisationTenantSlugExists($slug)) throw new AccountValidationException(['That tenant slug is already in use. Choose another slug.']);
        return $slug;
    }
}
