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
        string $staffIdentifier,
        string $role,
        string $password,
        string $confirmation,
        ?string $tenantSlug = null,
    ): int {
        $this->validateIdentity($organisationName, $displayName, $role);
        $staffIdentifier = StaffIdentifier::normalise((string) $staffIdentifier);
        $this->validatePassword($password, $confirmation);
        $tenantSlug = $this->validatedTenantSlug($organisationName, $tenantSlug);
        if (!$this->setupAvailable()) throw new AccountValidationException(['First-run setup is no longer available.']);
        return $this->store->createFirstOrganisation(
            trim($organisationName), trim($displayName), $staffIdentifier, $role,
            password_hash($password, PASSWORD_DEFAULT), $tenantSlug,
        );
    }

    public function createOrganisationAdmin(
        string $organisationName,
        string $displayName,
        string $staffIdentifier,
        string $role,
        string $password,
        string $confirmation,
        ?string $tenantSlug = null,
    ): int {
        $this->validateIdentity($organisationName, $displayName, $role);
        $staffIdentifier = StaffIdentifier::normalise($staffIdentifier);
        $this->validatePassword($password, $confirmation);
        $tenantSlug = $this->validatedTenantSlug($organisationName, $tenantSlug);
        return $this->store->createOrganisationAdmin(
            trim($organisationName), trim($displayName), $staffIdentifier, $role,
            password_hash($password, PASSWORD_DEFAULT), $tenantSlug,
        );
    }

    public function createUser(int $organisationId, string $displayName, string $staffIdentifier, string $role, bool $isAdmin): int
    {
        $roles = [$role];
        if ($isAdmin) $roles[] = 'administrator';
        return $this->createPerson($organisationId, $displayName, $staffIdentifier, null, $roles);
    }

    /** @return list<array<string, mixed>> */
    public function people(int $organisationId): array
    {
        if ($organisationId < 1 || !$this->store->organisationExists($organisationId)) throw new AccountValidationException(['Organisation is invalid.']);
        return $this->store->findPeopleForOrganisation($organisationId);
    }

    /** @param list<string> $roles */
    public function createPerson(int $organisationId, string $displayName, string $staffIdentifier, ?string $email, array $roles): int
    {
        $this->validatePerson($organisationId, $displayName, $email, $roles);
        $staffIdentifier = StaffIdentifier::normalise($staffIdentifier);
        if ($this->store->findLogin($staffIdentifier, $organisationId) !== null) throw new AccountValidationException(['Those initials are already in use in this organisation.']);
        return $this->store->createPerson($organisationId, trim($displayName), $staffIdentifier, $this->nullable($email), $roles);
    }

    /** @param list<string> $roles */
    public function updatePerson(int $organisationId, int $userId, string $displayName, string $staffIdentifier, ?string $email, array $roles): void
    {
        $this->validatePerson($organisationId, $displayName, $email, $roles);
        $before = $this->store->findUserById($userId);
        if ($before === null || (int) ($before['organisation_id'] ?? 0) !== $organisationId) throw new AccountValidationException(['That person is not part of this organisation.']);
        $wasAdmin = in_array('administrator', (array) ($before['roles'] ?? []), true) || (bool) ($before['is_admin'] ?? false);
        if ($wasAdmin && !in_array('administrator', $roles, true) && $this->store->activeAdministratorCount($organisationId) < 2) {
            throw new AccountValidationException(['The organisation must retain at least one active administrator.']);
        }
        $staffIdentifier = StaffIdentifier::normalise($staffIdentifier);
        $existing = $this->store->findLogin($staffIdentifier, $organisationId);
        if ($existing !== null && (int) ($existing['id'] ?? 0) !== $userId) throw new AccountValidationException(['Those initials are already in use in this organisation.']);
        $this->store->updatePerson($organisationId, $userId, trim($displayName), $staffIdentifier, $this->nullable($email), $roles);
    }

    /** @param list<string> $roles */
    private function validatePerson(int $organisationId, string $displayName, ?string $email, array $roles): void
    {
        if ($organisationId < 1 || !$this->store->organisationExists($organisationId)) throw new AccountValidationException(['Organisation is invalid.']);
        if (trim($displayName) === '') throw new AccountValidationException(['User name/login must not be blank.']);
        $roles = array_values(array_unique(array_map('strval', $roles)));
        if (array_diff($roles, ['teacher', 'technician', 'administrator']) !== []) throw new AccountValidationException(['Role selection is invalid.']);
        if ($roles === []) throw new AccountValidationException(['Select at least one role.']);
        if ($email !== null && trim($email) !== '' && filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) throw new AccountValidationException(['Email address is invalid.']);
    }

    /** @return array<string, mixed> */
    public function authenticate(string $login, string $password, ?int $organisationId = null): array
    {
        if ($organisationId === null) throw new AccountValidationException(['Open your school’s Reqsheet address to log in.']);
        try { $login = StaffIdentifier::normalise($login); } catch (AccountValidationException) { throw new AccountValidationException(['Invalid login details.']); }
        $account = $this->store->findLogin($login, $organisationId);
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
        if ($organisationId === null) return false;
        try { $login = StaffIdentifier::normalise($login); } catch (AccountValidationException) { return false; }
        $account = $this->store->findLogin($login, $organisationId);
        return $account !== null && ($account['is_active'] ?? false)
            && ($organisationId === null || (int) ($account['organisation_id'] ?? 0) === $organisationId)
            && ($account['account_state'] ?? '') === 'awaiting_first_login'
            && ($account['password_hash'] ?? null) === null;
    }

    public function claimFirstLogin(string $login, string $password, string $confirmation, ?int $organisationId = null): array
    {
        if ($organisationId === null) throw new AccountValidationException(['Open your school’s Reqsheet address to log in.']);
        try { $login = StaffIdentifier::normalise($login); } catch (AccountValidationException) { throw new AccountValidationException(['Invalid login details.']); }
        $account = $this->store->findLogin($login, $organisationId);
        if ($account === null || !($account['is_active'] ?? false) || (int) ($account['organisation_id'] ?? 0) !== $organisationId || ($account['account_state'] ?? '') !== 'awaiting_first_login' || ($account['password_hash'] ?? null) !== null) {
            throw new AccountValidationException(['This account has already been claimed or is unavailable.']);
        }
        $this->validatePassword($password, $confirmation);
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $this->store->claimFirstLogin((int) $account['id'], $passwordHash);
        $account['password_hash'] = $passwordHash;
        $account['account_state'] = 'claimed';
        return $account;
    }

    public function changePassword(int $userId, int $organisationId, string $currentPassword, string $newPassword, string $confirmation): void
    {
        $account = $this->store->findUserById($userId);
        if ($account === null || (int) ($account['organisation_id'] ?? 0) !== $organisationId || !($account['is_active'] ?? false)) {
            throw new AccountValidationException(['Your account is unavailable.']);
        }
        if (!is_string($account['password_hash'] ?? null) || !password_verify($currentPassword, $account['password_hash'])) {
            throw new AccountValidationException(['Current password is incorrect.']);
        }
        $this->validatePassword($newPassword, $confirmation);
        $this->store->updatePassword($userId, $organisationId, password_hash($newPassword, PASSWORD_DEFAULT));
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
