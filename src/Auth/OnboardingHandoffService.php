<?php

declare(strict_types=1);

namespace Reqsheet\Auth;

use DateTimeImmutable;
use DateTimeZone;

final class OnboardingHandoffService
{
    public const LIFETIME_SECONDS = 300;

    public function __construct(private readonly OnboardingHandoffStore $store)
    {
    }

    public function issue(int $userId, int $organisationId): string
    {
        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token, true);
        $expires = (new DateTimeImmutable('now', new DateTimeZone('UTC')))
            ->modify('+' . self::LIFETIME_SECONDS . ' seconds')
            ->format('Y-m-d H:i:s.u');
        $this->store->create($hash, $userId, $organisationId, $expires);
        return $token;
    }

    /** @return array{user_id:int,organisation_id:int}|null */
    public function consume(string $token, int $organisationId): ?array
    {
        if (!preg_match('/\A[0-9a-f]{64}\z/D', $token)) return null;
        return $this->store->consume(hash('sha256', $token, true), $organisationId);
    }
}
