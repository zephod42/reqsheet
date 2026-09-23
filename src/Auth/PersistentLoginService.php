<?php

declare(strict_types=1);

namespace Reqsheet\Auth;

use Reqsheet\Account\AccountStore;
use DateTimeImmutable;
use DateTimeZone;

final class PersistentLoginService
{
    public const COOKIE_NAME = 'reqsheet_remember';
    public const MAX_LIFETIME = 2592000;
    private const CONCURRENT_GRACE_SECONDS = 30;

    public function __construct(
        private readonly PersistentLoginStore $tokens,
        private readonly AccountStore $accounts,
    ) {
    }

    /** @param array<string, mixed> $account */
    public function issue(array $account): string
    {
        $selector = bin2hex(random_bytes(16));
        $verifier = bin2hex(random_bytes(32));
        $now = $this->now();
        $expires = $now->modify('+' . self::MAX_LIFETIME . ' seconds');
        $this->tokens->insert(
            (int) $account['id'],
            (int) $account['organisation_id'],
            $selector,
            hash('sha256', $verifier, true),
            (int) ($account['auth_version'] ?? 1),
            $now->format('Y-m-d H:i:s.u'),
            $expires->format('Y-m-d H:i:s.u'),
        );
        return $selector . '.' . $verifier;
    }

    /** @return array{account:array<string,mixed>,cookie:?string}|null */
    public function restore(string $cookie, int $organisationId): ?array
    {
        [$selector, $verifier] = $this->split($cookie);
        if ($selector === null || $verifier === null || $organisationId < 1) return null;
        $row = $this->tokens->find($selector, $organisationId);
        if ($row === null) return null;
        $now = $this->now();
        if (!$this->validExpiry((string) ($row['expires_at'] ?? ''), $now)) return null;
        $account = $this->accounts->findUserById((int) ($row['user_id'] ?? 0));
        if ($account === null || !(bool) ($account['is_active'] ?? false)
            || (int) ($account['organisation_id'] ?? 0) !== $organisationId
            || (int) ($row['auth_version'] ?? 0) !== (int) ($account['auth_version'] ?? 1)) return null;

        $presentedHash = hash('sha256', $verifier, true);
        $currentHash = $this->binary((string) ($row['verifier_hash'] ?? ''));
        if ($currentHash !== null && hash_equals($currentHash, $presentedHash)) {
            $newVerifier = bin2hex(random_bytes(32));
            $rotated = $this->tokens->rotate(
                $selector,
                $presentedHash,
                hash('sha256', $newVerifier, true),
                $now->modify('+' . self::CONCURRENT_GRACE_SECONDS . ' seconds')->format('Y-m-d H:i:s.u'),
                $now->format('Y-m-d H:i:s.u'),
            );
            if ($rotated) return ['account' => $account, 'cookie' => $selector . '.' . $newVerifier];
            $concurrentRow = $this->tokens->find($selector, $organisationId);
            $concurrentPrevious = $concurrentRow === null ? null : $this->binary((string) ($concurrentRow['previous_verifier_hash'] ?? ''));
            $concurrentUntil = (string) ($concurrentRow['previous_valid_until'] ?? '');
            if ($concurrentPrevious !== null && hash_equals($concurrentPrevious, $presentedHash) && $this->validExpiry($concurrentUntil, $now)) {
                return ['account' => $account, 'cookie' => null];
            }
            return null;
        }

        $previousHash = $this->binary((string) ($row['previous_verifier_hash'] ?? ''));
        $previousUntil = (string) ($row['previous_valid_until'] ?? '');
        if ($previousHash !== null && hash_equals($previousHash, $presentedHash) && $this->validExpiry($previousUntil, $now)) {
            return ['account' => $account, 'cookie' => null];
        }
        return null;
    }

    public function revokeCookie(?string $cookie, int $organisationId): void
    {
        [$selector] = $this->split((string) $cookie);
        if ($selector !== null && $organisationId > 0) $this->tokens->revoke($selector, $organisationId);
    }

    public static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE_NAME, $value, [
            'expires' => $expires,
            'path' => '/',
            'secure' => true,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearCookie(): void
    {
        self::setCookie('', time() - 3600);
    }

    private function split(string $cookie): array
    {
        if (!preg_match('/\A([a-f0-9]{32})\.([a-f0-9]{64})\z/D', $cookie, $matches)) return [null, null];
        return [$matches[1], $matches[2]];
    }

    private function validExpiry(string $value, DateTimeImmutable $now): bool
    {
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s.u', $value, new DateTimeZone('UTC'));
        return $expiry !== false && $expiry > $now;
    }

    private function binary(string $value): ?string
    {
        return strlen($value) === 32 ? $value : null;
    }

    private function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

}
