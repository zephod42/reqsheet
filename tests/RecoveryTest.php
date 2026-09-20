<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Account\AccountService;
use Reqsheet\Recovery\RecoveryException;
use Reqsheet\Recovery\RecoveryService;
use Reqsheet\Recovery\RecoveryStore;
use Reqsheet\Recovery\RecoverySession;
use Reqsheet\Http\AccountRecoveryPage;
use Reqsheet\Http\RecoveryKeyPage;
use Reqsheet\Http\SessionAuth;
use Reqsheet\Http\CsrfToken;

final class RecoveryTest
{
    public static function run(): void
    {
        $accountStore = new AccountStoreFake();
        $accounts = new AccountService($accountStore);
        $organisationId = $accounts->createOrganisationAdmin('Recovery School', 'ADM', 'teacher', 'old-password', 'old-password', 'recoveryschl');
        $administratorId = (int) $accountStore->accounts['ADM']['id'];
        $store = new RecoveryStoreFake();
        $service = new RecoveryService($store, $accounts);

        $first = $service->issueForAdministrator($organisationId, $administratorId, true);
        assertSameValue(47, strlen($first['key']), 'Recovery key did not encode a 256-bit secret in the expected format.');
        assertSameValue(true, str_starts_with($first['key'], 'rk1_'), 'Recovery key format prefix was missing.');
        assertSameValue(32, strlen((string) $store->digest), 'Persistent recovery digest was not 256 bits.');
        assertSameValue(false, str_contains((string) $store->digest, $first['key']), 'Plaintext recovery key was persisted.');
        assertSameValue(false, $store->acknowledged, 'A presented key was marked acknowledged automatically.');
        $store->acknowledge($organisationId, $administratorId, $first['generation']);

        self::expectRecovery(static fn () => $service->begin($organisationId, 'ADM', 'wrong-key', 'client-a'), 'Incorrect recovery key was accepted.');
        self::expectRecovery(static fn () => $service->begin($organisationId, 'NON', $first['key'], 'client-b'), 'Non-administrator recovery was accepted.');
        self::expectRecovery(static fn () => $service->begin($organisationId, 'DEL', $first['key'], 'client-deleted'), 'Deleted account initials were reused.');
        self::expectRecovery(static fn () => $service->begin(2, 'ADM', $first['key'], 'client-cross'), 'Recovery key was accepted in another organisation.');
        $abandoned = $service->begin($organisationId, 'NEW', $first['key'], 'client-abandoned');
        assertSameValue(null, $abandoned['user_id'], 'Unknown initials created an account before password selection.');
        assertSameValue([], $store->created, 'Abandoned recovery left an account behind.');
        $service->cancel($organisationId, $abandoned['token']);
        self::expectRecovery(static fn () => $service->complete($organisationId, $abandoned['token'], 'new-admin-pass', 'new-admin-pass'), 'Cancelled new-account recovery created an administrator.');
        assertSameValue(1, $store->generation, 'Failed recovery rotated the organisation key.');

        $attemptOne = $service->begin($organisationId, 'ADM', $first['key'], 'client-c');
        $attemptTwo = $service->begin($organisationId, 'ADM', $first['key'], 'client-d');
        $completed = $service->complete($organisationId, $attemptOne['token'], 'new-password', 'new-password');
        assertSameValue(2, $completed['generation'], 'Successful recovery did not rotate the key generation.');
        assertSameValue(false, hash_equals(RecoveryService::digest($first['key']), (string) $store->digest), 'Old key digest remained active after recovery.');
        self::expectRecovery(static fn () => $service->complete($organisationId, $attemptTwo['token'], 'attacker-pass', 'attacker-pass'), 'Concurrent recovery attempt survived key rotation.');
        self::expectRecovery(static fn () => $service->begin($organisationId, 'ADM', $first['key'], 'client-e'), 'Rotated old key remained usable.');
        $store->acknowledge($organisationId, $administratorId, $completed['generation']);
        $next = $service->begin($organisationId, 'ADM', $completed['key'], 'client-f');
        assertSameValue($administratorId, $next['user_id'], 'Replacement key did not recover the existing administrator.');
        $service->cancel($organisationId, $next['token']);
        self::expectRecovery(static fn () => $service->complete($organisationId, $next['token'], 'cancelled-pass', 'cancelled-pass'), 'Cancelled recovery flow remained usable.');

        $replacement = $service->replaceAuthenticated($organisationId, $administratorId, 'old-password');
        assertSameValue(3, $replacement['generation'], 'Authenticated replacement did not rotate the key.');
        self::expectRecovery(static fn () => $service->replaceAuthenticated($organisationId, $administratorId, 'wrong-password'), 'Authenticated replacement accepted an incorrect password.');

        RecoverySession::clearFlow();
        RecoverySession::clearPresentation();
        $publicPage = new AccountRecoveryPage($service, $accounts, ['id' => $organisationId, 'name' => 'Recovery School', 'tenant_slug' => 'recoveryschl'], 'page-client');
        $publicView = $publicPage->handle('GET', []);
        assertContainsValue('Recovery School', $publicView, 'Account Recovery did not show the current school identity.');
        assertContainsValue('type="password" name="recovery_key"', $publicView, 'Recovery key input was not protected as a password field.');
        assertNotContainsValue('?recovery_key=', $publicView, 'Recovery key was included in a URL.');

        $account = $accounts->findUserById($administratorId);
        if ($account === null) throw new \RuntimeException('Recovery test administrator disappeared.');
        SessionAuth::login($account);
        RecoverySession::present($organisationId, $administratorId, $replacement);
        $keyPage = new RecoveryKeyPage($service, $accounts, $account);
        $presentationView = $keyPage->handle('GET', []);
        assertContainsValue('WARNING: This private recovery key is the only way', $presentationView, 'Required recovery-key warning was missing.');
        assertContainsValue($replacement['key'], $presentationView, 'Replacement recovery key was not presented to the authorised administrator.');
        assertNotContainsValue('type="hidden" name="recovery_key"', $presentationView, 'Recovery key acknowledgement relied on a hidden key field.');
        $acknowledgedView = $keyPage->handle('POST', ['csrf_token' => CsrfToken::value(), 'action' => 'acknowledge', 'saved' => 'yes']);
        assertContainsValue('Recovery key saved', $acknowledgedView, 'Recovery key acknowledgement did not complete.');
        assertNotContainsValue($replacement['key'], $acknowledgedView, 'Recovery key was displayed again after acknowledgement.');
        assertSameValue(null, RecoverySession::presentation($organisationId, $administratorId), 'Acknowledged plaintext key remained in server-side presentation state.');
        $createFlow = $service->begin($organisationId, 'NEW', $replacement['key'], 'client-new');
        self::expectRecovery(static fn () => $service->complete($organisationId, $createFlow['token'], 'short', 'short'), 'Weak password created a recovered administrator.');
        assertSameValue([], $store->created, 'Failed password validation created an account.');
        $newAdmin = $service->complete($organisationId, $createFlow['token'], 'new-admin-pass', 'new-admin-pass');
        assertSameValue(['administrator'], $store->created['roles'], 'Recovery-created administrator was assigned operational roles.');
        assertSameValue('recovery_pending', $store->created['account_state'], 'Recovery-created administrator bypassed acknowledgement.');
        assertSameValue(true, password_verify('new-admin-pass', $store->created['password_hash']), 'Recovery-created administrator did not receive the selected password.');
        self::expectRecovery(static fn () => $service->complete($organisationId, $createFlow['token'], 'new-admin-pass', 'new-admin-pass'), 'New-account recovery flow was replayable.');
        assertSameValue(4, $newAdmin['generation'], 'Creating an administrator did not rotate the key.');
        SessionAuth::logout();
    }

    private static function expectRecovery(callable $operation, string $message): void
    {
        try { $operation(); } catch (RecoveryException | \Reqsheet\Account\AccountValidationException) { return; }
        throw new \RuntimeException($message);
    }
}

final class RecoveryStoreFake implements RecoveryStore
{
    public ?string $digest = null;
    public int $generation = 0;
    public bool $acknowledged = false;
    public array $created = [];
    /** @var array<string,array{user_id:int,generation:int,used:bool}> */
    private array $flows = [];

    public function metadata(int $organisationId): array { return ['digest' => $this->digest, 'generation' => $this->generation, 'acknowledged' => $this->acknowledged]; }
    public function issueKey(int $organisationId, int $administratorId, string $digest, bool $onlyIfMissing): int
    {
        if ($onlyIfMissing && $this->digest !== null) throw new RecoveryException('Already issued.');
        foreach ($this->flows as &$flow) if ($flow['generation'] === $this->generation) $flow['used'] = true;
        unset($flow);
        $this->digest = $digest; $this->generation++; $this->acknowledged = false; return $this->generation;
    }
    public function createFlow(int $organisationId, string $staffIdentifier, string $keyDigest, string $flowTokenHash, string $clientHash, \DateTimeImmutable $expiresAt): ?array
    {
        if ($organisationId !== 1 || !$this->acknowledged || $this->digest === null || !hash_equals($this->digest, $keyDigest)) return null;
        if (in_array($staffIdentifier, ['NON', 'DEL'], true)) throw new RecoveryException('Choose different initials.');
        $userId = $staffIdentifier === 'ADM' ? 1 : null;
        $key = bin2hex($flowTokenHash); $this->flows[$key] = ['user_id' => $userId, 'generation' => $this->generation, 'used' => false, 'expires_at' => $expiresAt];
        return ['user_id' => $userId, 'generation' => $this->generation];
    }
    public function completeFlow(int $organisationId, string $flowTokenHash, string $passwordHash, string $replacementDigest): ?array
    {
        $key = bin2hex($flowTokenHash); $flow = $this->flows[$key] ?? null;
        if ($organisationId !== 1 || $flow === null || $flow['used'] || $flow['generation'] !== $this->generation || $flow['expires_at'] <= new \DateTimeImmutable()) return null;
        if ($flow['user_id'] === null) $this->created = ['roles' => ['administrator'], 'account_state' => 'recovery_pending', 'password_hash' => $passwordHash];
        $oldGeneration = $this->generation;
        foreach ($this->flows as &$candidate) if ($candidate['generation'] === $oldGeneration) $candidate['used'] = true;
        unset($candidate);
        $this->digest = $replacementDigest; $this->generation++; $this->acknowledged = false;
        return ['user_id' => $flow['user_id'] ?? 2, 'generation' => $this->generation];
    }
    public function acknowledge(int $organisationId, int $administratorId, int $generation): void
    {
        if ($generation !== $this->generation) throw new RecoveryException('Stale presentation.');
        $this->acknowledged = true;
    }
    public function cancelFlow(int $organisationId, string $flowTokenHash): void
    {
        $key = bin2hex($flowTokenHash);
        if (isset($this->flows[$key])) $this->flows[$key]['used'] = true;
    }
}
