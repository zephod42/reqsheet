<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Auth\PersistentLoginService;
use Reqsheet\Auth\PersistentLoginStore;

final class PersistentLoginTest
{
    public static function run(): void
    {
        $accounts = new AccountStoreFake();
        $organisationId = $accounts->createOrganisationAdmin('Remember School', 'REM', 'teacher', password_hash('remember-pass', PASSWORD_DEFAULT), 'remember');
        $account = $accounts->findUserById(1);
        if ($account === null) throw new \RuntimeException('Remember Me test account was not created.');
        $tokens = new PersistentLoginStoreFake();
        $service = new PersistentLoginService($tokens, $accounts);

        $cookie = $service->issue($account);
        if (preg_match('/\A[a-f0-9]{32}\.[a-f0-9]{64}\z/D', $cookie) !== 1) throw new \RuntimeException('Remember Me cookie credential has the wrong format.');
        $restored = $service->restore($cookie, $organisationId);
        if ($restored === null || (int) $restored['account']['id'] !== 1 || $restored['cookie'] === null) throw new \RuntimeException('Remember Me did not restore and rotate a valid credential.');
        $concurrent = $service->restore($cookie, $organisationId);
        if ($concurrent === null || $concurrent['cookie'] !== null) throw new \RuntimeException('Concurrent Remember Me use was unnecessarily rejected or rotated.');
        if ($service->restore($cookie, 999) !== null) throw new \RuntimeException('Remember Me crossed tenant boundaries.');

        $rotatedCookie = (string) $restored['cookie'];
        $rotatedSelector = explode('.', $rotatedCookie, 2)[0];
        $tokens->rows[$rotatedSelector]['expires_at'] = '2000-01-01 00:00:00.000000';
        if ($service->restore($rotatedCookie, $organisationId) !== null) throw new \RuntimeException('Expired Remember Me credential was accepted.');
        unset($tokens->rows[$rotatedSelector]);
        $rotatedCookie = $service->issue($account);

        $accounts->accounts['REM']['auth_version']++;
        if ($service->restore($rotatedCookie, $organisationId) !== null) throw new \RuntimeException('Remember Me survived account revocation.');
        $accounts->accounts['REM']['auth_version']--;
        $service->revokeCookie($rotatedCookie, $organisationId);
        if ($service->restore($rotatedCookie, $organisationId) !== null) throw new \RuntimeException('Revoked Remember Me credential was accepted.');
        if (count($tokens->rows) !== 0) throw new \RuntimeException('Remember Me revocation did not remove the server-side credential.');
    }
}

final class PersistentLoginStoreFake implements PersistentLoginStore
{
    /** @var array<string, array<string, mixed>> */
    public array $rows = [];

    public function find(string $selector, int $organisationId): ?array
    {
        $row = $this->rows[$selector] ?? null;
        return $row !== null && (int) $row['organisation_id'] === $organisationId ? $row : null;
    }

    public function insert(int $userId, int $organisationId, string $selector, string $verifierHash, int $authVersion, string $createdAt, string $expiresAt): void
    {
        $this->rows[$selector] = compact('userId', 'organisationId', 'selector', 'verifierHash', 'authVersion', 'createdAt', 'expiresAt') + [
            'user_id' => $userId, 'organisation_id' => $organisationId, 'verifier_hash' => $verifierHash,
            'auth_version' => $authVersion, 'expires_at' => $expiresAt, 'previous_verifier_hash' => null, 'previous_valid_until' => null,
        ];
    }

    public function rotate(string $selector, string $oldVerifierHash, string $newVerifierHash, string $previousValidUntil, string $lastUsedAt): bool
    {
        if (!isset($this->rows[$selector]) || !hash_equals((string) $this->rows[$selector]['verifier_hash'], $oldVerifierHash)) return false;
        $this->rows[$selector]['previous_verifier_hash'] = $this->rows[$selector]['verifier_hash'];
        $this->rows[$selector]['previous_valid_until'] = $previousValidUntil;
        $this->rows[$selector]['verifier_hash'] = $newVerifierHash;
        return true;
    }

    public function revoke(string $selector, int $organisationId): void
    {
        if (isset($this->rows[$selector]) && (int) $this->rows[$selector]['organisation_id'] === $organisationId) unset($this->rows[$selector]);
    }
}
