<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Auth\OnboardingHandoffService;
use Reqsheet\Auth\OnboardingHandoffStore;

final class OnboardingHandoffTest
{
    public static function run(): void
    {
        $store = new OnboardingHandoffStoreFake();
        $service = new OnboardingHandoffService($store);
        $token = $service->issue(12, 34);
        assertSameValue(64, strlen($token), 'Onboarding handoff token was not high entropy.');
        assertSameValue(null, $service->consume($token, 35), 'A handoff was consumable by another organisation.');
        assertSameValue(['user_id' => 12, 'organisation_id' => 34], $service->consume($token, 34), 'A valid handoff was not consumed by its tenant.');
        assertSameValue(null, $service->consume($token, 34), 'A consumed handoff could be reused.');
        assertSameValue(null, $service->consume('not-a-token', 34), 'An invalid handoff token was accepted.');
    }
}

final class OnboardingHandoffStoreFake implements OnboardingHandoffStore
{
    private ?array $handoff = null;
    private bool $consumed = false;

    public function create(string $tokenHash, int $userId, int $organisationId, string $expiresAt): void
    {
        $this->handoff = ['token_hash' => $tokenHash, 'user_id' => $userId, 'organisation_id' => $organisationId];
    }

    public function consume(string $tokenHash, int $organisationId): ?array
    {
        if ($this->consumed || $this->handoff === null || !hash_equals($this->handoff['token_hash'], $tokenHash) || $this->handoff['organisation_id'] !== $organisationId) return null;
        $this->consumed = true;
        return ['user_id' => $this->handoff['user_id'], 'organisation_id' => $this->handoff['organisation_id']];
    }
}
