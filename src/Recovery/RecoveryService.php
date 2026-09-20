<?php

declare(strict_types=1);

namespace Reqsheet\Recovery;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Account\StaffIdentifier;

final class RecoveryService
{
    public const FLOW_LIFETIME_SECONDS = 900;
    private const KEY_PREFIX = 'rk1_';
    private const DIGEST_DOMAIN = "Reqsheet organisation recovery key v1\0";

    public function __construct(private readonly RecoveryStore $store, private readonly AccountService $accounts)
    {
    }

    /** @return array{key:string,generation:int} */
    public function issueForAdministrator(int $organisationId, int $administratorId, bool $onlyIfMissing = false): array
    {
        $key = self::generateKey();
        $generation = $this->store->issueKey($organisationId, $administratorId, self::digest($key), $onlyIfMissing);
        return ['key' => $key, 'generation' => $generation];
    }

    /** @return array{key:string,generation:int} */
    public function replaceAuthenticated(int $organisationId, int $administratorId, string $currentPassword): array
    {
        $account = $this->accounts->verifyPassword($administratorId, $organisationId, $currentPassword);
        if (!(bool) ($account['is_admin'] ?? false)) throw new RecoveryException('You are not authorised to manage the organisation recovery key.');
        return $this->issueForAdministrator($organisationId, $administratorId);
    }

    /** @return array{token:string,user_id:int,generation:int} */
    public function begin(int $organisationId, string $staffIdentifier, string $key, string $clientIdentity, ?\DateTimeImmutable $now = null): array
    {
        try {
            $staffIdentifier = StaffIdentifier::normalise(strtoupper(trim($staffIdentifier)));
        } catch (AccountValidationException) {
            $staffIdentifier = '---';
        }
        $key = trim($key);
        if (strlen($key) > 128) $key = '';
        $token = self::randomToken();
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $result = $this->store->createFlow(
            $organisationId,
            $staffIdentifier,
            self::digest($key),
            hash('sha256', $token, true),
            hash('sha256', "Reqsheet recovery rate limit v1\0" . $clientIdentity, true),
            $now->modify('+' . self::FLOW_LIFETIME_SECONDS . ' seconds'),
        );
        if ($result === null) throw new RecoveryException('Recovery could not be verified. Check the details and try again later.');
        return ['token' => $token, 'user_id' => $result['user_id'], 'generation' => $result['generation']];
    }

    /** @return array{key:string,user_id:int,generation:int} */
    public function complete(int $organisationId, string $flowToken, string $password, string $confirmation): array
    {
        $this->accounts->validatePassword($password, $confirmation);
        $replacement = self::generateKey();
        $result = $this->store->completeFlow(
            $organisationId,
            hash('sha256', $flowToken, true),
            password_hash($password, PASSWORD_DEFAULT),
            self::digest($replacement),
        );
        if ($result === null) throw new RecoveryException('This recovery attempt has expired or is no longer valid. Start Account Recovery again.');
        return ['key' => $replacement, 'user_id' => $result['user_id'], 'generation' => $result['generation']];
    }

    public function acknowledge(int $organisationId, int $administratorId, int $generation): void
    {
        $this->store->acknowledge($organisationId, $administratorId, $generation);
    }

    public function cancel(int $organisationId, string $flowToken): void
    {
        $this->store->cancelFlow($organisationId, hash('sha256', $flowToken, true));
    }

    /** @return array{digest:?string,generation:int,acknowledged:bool} */
    public function metadata(int $organisationId): array
    {
        return $this->store->metadata($organisationId);
    }

    public static function generateKey(): string
    {
        return self::KEY_PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function digest(string $key): string
    {
        return hash('sha256', self::DIGEST_DOMAIN . $key, true);
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
