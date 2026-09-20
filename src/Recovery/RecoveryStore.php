<?php

declare(strict_types=1);

namespace Reqsheet\Recovery;

interface RecoveryStore
{
    /** @return array{digest:?string,generation:int,acknowledged:bool} */
    public function metadata(int $organisationId): array;

    public function issueKey(int $organisationId, int $administratorId, string $digest, bool $onlyIfMissing): int;

    /** @return array{user_id:int,generation:int}|null */
    public function createFlow(int $organisationId, string $staffIdentifier, string $keyDigest, string $flowTokenHash, string $clientHash, \DateTimeImmutable $expiresAt): ?array;

    /** @return array{user_id:int,generation:int}|null */
    public function completeFlow(int $organisationId, string $flowTokenHash, string $passwordHash, string $replacementDigest): ?array;

    public function acknowledge(int $organisationId, int $administratorId, int $generation): void;

    public function cancelFlow(int $organisationId, string $flowTokenHash): void;
}
