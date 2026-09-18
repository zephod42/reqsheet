<?php

declare(strict_types=1);

namespace Reqsheet\Account;

interface AccountStore extends TenantStore
{
    public function organisationCount(): int;

    public function organisationExists(int $organisationId): bool;

    public function organisationTenantSlugExists(string $tenantSlug): bool;

    /** @return array<string, mixed>|null */
    public function findLogin(string $login): ?array;

    public function createFirstOrganisation(
        string $organisationName,
        string $displayName,
        ?string $staffIdentifier,
        string $role,
        string $passwordHash,
        string $tenantSlug = '',
    ): int;

    public function createOrganisationAdmin(
        string $organisationName,
        string $displayName,
        ?string $staffIdentifier,
        string $role,
        string $passwordHash,
        string $tenantSlug = '',
    ): int;

    public function createUser(
        int $organisationId,
        string $displayName,
        ?string $staffIdentifier,
        string $role,
        bool $isAdmin,
    ): int;

    public function claimFirstLogin(int $userId, string $passwordHash): void;
}
