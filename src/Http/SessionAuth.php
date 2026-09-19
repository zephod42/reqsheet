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
            'display_name' => (string) ($account['display_name'] ?? ''),
            'staff_identifier' => $account['staff_identifier'] ?? null,
            'operational_role' => isset($account['operational_role']) ? (string) $account['operational_role'] : null,
            'is_admin' => (bool) ($account['is_admin'] ?? false),
            'roles' => array_values(array_unique(array_map('strval', (array) ($account['roles'] ?? [])))),
        ];
    }

    public static function regenerate(): void
    {
        self::start();
        session_regenerate_id(true);
    }

    /** @return array{id:int,organisation_id:int,display_name:string,staff_identifier:?string,operational_role:?string,is_admin:bool,roles:list<string>}|null */
    public static function current(): ?array
    {
        self::start();
        $user = $_SESSION['user'] ?? null;
        if (!is_array($user) || !isset($user['id'], $user['organisation_id'])) return null;
        return [
            'id' => (int) $user['id'], 'organisation_id' => (int) $user['organisation_id'],
            'display_name' => (string) ($user['display_name'] ?? ''), 'staff_identifier' => isset($user['staff_identifier']) ? (string) $user['staff_identifier'] : null,
            'operational_role' => isset($user['operational_role']) ? (string) $user['operational_role'] : null,
            'is_admin' => (bool) ($user['is_admin'] ?? false),
            'roles' => array_values(array_unique(array_map('strval', (array) ($user['roles'] ?? [])))),
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
        $roles = (array) ($user['roles'] ?? []);
        if ($roles === [] && ($user['operational_role'] ?? null) !== null) $roles[] = (string) $user['operational_role'];
        if (in_array('technician', $roles, true) && !in_array('teacher', $roles, true)) return '/technician';
        if (in_array('teacher', $roles, true)) return '/teacher';
        return '/settings';
    }

    /** @param array<string, mixed>|null $user */
    public static function hasRole(?array $user, string $role): bool
    {
        if ($user === null) return false;
        $roles = (array) ($user['roles'] ?? []);
        if ($roles === [] && ($user['operational_role'] ?? null) !== null) $roles[] = (string) $user['operational_role'];
        if ($role === 'administrator' && (($user['is_admin'] ?? false) === true)) return true;
        return in_array($role, $roles, true);
    }

    /** @param array<string, mixed>|null $user */
    public static function isAdmin(?array $user): bool
    {
        return self::hasRole($user, 'administrator');
    }
}
