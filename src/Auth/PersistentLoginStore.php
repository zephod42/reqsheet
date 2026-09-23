<?php

declare(strict_types=1);

namespace Reqsheet\Auth;

interface PersistentLoginStore
{
    /** @return array<string, mixed>|null */
    public function find(string $selector, int $organisationId): ?array;

    public function insert(
        int $userId,
        int $organisationId,
        string $selector,
        string $verifierHash,
        int $authVersion,
        string $createdAt,
        string $expiresAt,
    ): void;

    public function rotate(
        string $selector,
        string $oldVerifierHash,
        string $newVerifierHash,
        string $previousValidUntil,
        string $lastUsedAt,
    ): bool;

    public function revoke(string $selector, int $organisationId): void;
}
