<?php

declare(strict_types=1);

namespace Reqsheet\Auth;

interface OnboardingHandoffStore
{
    public function create(string $tokenHash, int $userId, int $organisationId, string $expiresAt): void;

    /** @return array{user_id:int,organisation_id:int}|null */
    public function consume(string $tokenHash, int $organisationId): ?array;
}
