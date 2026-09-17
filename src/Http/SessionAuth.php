<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class SessionAuth
{
    public static function start(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) return;
        $https = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && strtolower((string) $_SERVER['HTTPS']) !== 'off';
        session_set_cookie_params([
            'httponly' => true,
            'secure' => $https,
            'samesite' => 'Lax',
            'path' => '/',
        ]);
        session_start();
    }

    /** @param array<string, mixed> $account */
    public static function login(array $account): void
    {
        self::start();
        session_regenerate_id(true);
        $_SESSION['user'] = [
            'id' => (int) $account['id'],
            'organisation_id' => (int) $account['organisation_id'],
            'operational_role' => (string) $account['operational_role'],
            'is_admin' => (bool) $account['is_admin'],
        ];
    }

    /** @return array{id:int,organisation_id:int,operational_role:string,is_admin:bool}|null */
    public static function current(): ?array
    {
        self::start();
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user) || !isset($user['id'], $user['organisation_id'], $user['operational_role'])) return null;
        return [
            'id' => (int) $user['id'], 'organisation_id' => (int) $user['organisation_id'],
            'operational_role' => (string) $user['operational_role'], 'is_admin' => (bool) ($user['is_admin'] ?? false),
        ];
    }

    public static function logout(): void
    {
        self::start();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    /** @param array<string, mixed>|null $user */
    public static function landingPath(?array $user): string
    {
        return ($user['operational_role'] ?? '') === 'technician' ? '/technician' : '/teacher';
    }

    /** @param array<string, mixed>|null $user */
    public static function hasRole(?array $user, string $role): bool
    {
        return $user !== null && ($user['operational_role'] ?? null) === $role;
    }

    /** @param array<string, mixed>|null $user */
    public static function isAdmin(?array $user): bool
    {
        return $user !== null && ($user['is_admin'] ?? false) === true;
    }
}
