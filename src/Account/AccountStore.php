<?php

declare(strict_types=1);

namespace Reqsheet\Account;

interface AccountStore extends TenantStore
{
    public function organisationCount(): int;

    public function organisationExists(int $organisationId): bool;

    public function organisationTenantSlugExists(string $tenantSlug): bool;

    public function findOrganisationTenantSlug(int $organisationId): ?string;

    /** @return array<string, mixed>|null */
    public function findLogin(string $login, ?int $organisationId = null): ?array;

    /** @return array<string, mixed>|null */
    public function findUserById(int $userId): ?array;

    /** @return list<array<string, mixed>> */
    public function findPeopleForOrganisation(int $organisationId): array;

    /** @param list<string> $roles */
    public function createPerson(int $organisationId, string $displayName, string $staffIdentifier, array $roles): int;

    /** @param list<string> $roles */
    public function updatePerson(int $organisationId, int $userId, string $displayName, string $staffIdentifier, array $roles): void;

    public function activeAdministratorCount(int $organisationId): int;

    public function createFirstOrganisation(
        string $organisationName,
        string $displayName,
        string $staffIdentifier,
        string $role,
        string $passwordHash,
        string $tenantSlug = '',
    ): int;

    public function createOrganisationAdmin(
        string $organisationName,
        string $displayName,
        string $staffIdentifier,
        string $role,
        string $passwordHash,
        string $tenantSlug = '',
    ): int;

    public function createUser(
        int $organisationId,
        string $displayName,
        string $staffIdentifier,
        string $role,
        bool $isAdmin,
    ): int;

    public function claimFirstLogin(int $userId, string $passwordHash): void;

    public function updatePassword(int $userId, int $organisationId, string $passwordHash): void;

    public function resetPassword(int $userId, int $organisationId): void;

    /** @return array{state:?string,until:?string} */
    public function organisationAccountStatus(int $organisationId): array;
}
