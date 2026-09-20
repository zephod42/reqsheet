<?php

declare(strict_types=1);

namespace Reqsheet\Recovery;

use Reqsheet\Http\SessionAuth;

final class RecoverySession
{
    private const PRESENTATION_LIFETIME = 1800;

    /** @param array{key:string,generation:int} $issued */
    public static function present(int $organisationId, int $userId, array $issued): void
    {
        SessionAuth::start();
        $_SESSION['recovery_key_presentation'] = [
            'organisation_id' => $organisationId,
            'user_id' => $userId,
            'key' => $issued['key'],
            'generation' => $issued['generation'],
            'expires_at' => time() + self::PRESENTATION_LIFETIME,
        ];
    }

    /** @return array{organisation_id:int,user_id:int,key:string,generation:int,expires_at:int}|null */
    public static function presentation(int $organisationId, int $userId): ?array
    {
        SessionAuth::start();
        $value = $_SESSION['recovery_key_presentation'] ?? null;
        if (!is_array($value) || (int) ($value['organisation_id'] ?? 0) !== $organisationId || (int) ($value['user_id'] ?? 0) !== $userId || (int) ($value['expires_at'] ?? 0) < time()) {
            unset($_SESSION['recovery_key_presentation']);
            return null;
        }
        return [
            'organisation_id' => $organisationId,
            'user_id' => $userId,
            'key' => (string) $value['key'],
            'generation' => (int) $value['generation'],
            'expires_at' => (int) $value['expires_at'],
        ];
    }

    public static function clearPresentation(): void
    {
        SessionAuth::start();
        unset($_SESSION['recovery_key_presentation']);
    }

    public static function setFlow(int $organisationId, string $token): void
    {
        SessionAuth::start();
        $_SESSION['account_recovery_flow'] = ['organisation_id' => $organisationId, 'token' => $token, 'expires_at' => time() + RecoveryService::FLOW_LIFETIME_SECONDS];
    }

    public static function flow(int $organisationId): ?string
    {
        SessionAuth::start();
        $value = $_SESSION['account_recovery_flow'] ?? null;
        if (!is_array($value) || (int) ($value['organisation_id'] ?? 0) !== $organisationId || (int) ($value['expires_at'] ?? 0) < time()) {
            unset($_SESSION['account_recovery_flow']);
            return null;
        }
        return (string) ($value['token'] ?? '');
    }

    public static function clearFlow(): void
    {
        SessionAuth::start();
        unset($_SESSION['account_recovery_flow']);
    }
}
