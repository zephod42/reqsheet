<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class PageLayout
{
    /** @var array{id:int,name:string,tenant_slug:string}|null */
    private static ?array $tenantOrganisation = null;

    /** @param array{id:int,name:string,tenant_slug:string}|null $organisation */
    public static function setTenantOrganisation(?array $organisation): void
    {
        self::$tenantOrganisation = $organisation;
    }

    public static function render(string $title, string $body, ?array $user = null): string
    {
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $active = static fn (string $path): string => ($path === '/teacher' ? str_starts_with($currentPath, '/teacher') : $currentPath === $path) ? ' class="active" aria-current="page"' : '';
        $nav = '<nav class="site-nav"><a class="wordmark" href="/">Reqsheet.</a><ul><li><a' . $active('/about') . ' href="/about">About</a></li><li><a' . $active('/demo') . ' href="/demo">Demo</a></li>' . ($user === null ? '<li><a' . $active('/signup') . ' href="/signup">Sign up</a></li>' : '') . '<li><a' . $active('/contact') . ' href="/contact">Contact</a></li>';
        if ($user !== null) {
            $landing = ($user['operational_role'] ?? '') === 'technician' ? '/technician' : '/teacher';
            $nav .= '<li class="nav-divider"><a' . $active($landing) . ' href="' . $landing . '">' . (($landing === '/technician') ? 'Technician' : 'Teacher week') . '</a></li>';
            $admin = (bool) ($user['is_admin'] ?? false);
            $adminLink = fn (string $path, string $label): string => $admin ? '<a' . $active($path) . ' href="' . $path . '">' . $label . '</a>' : '<span class="nav-disabled" aria-disabled="true" title="Administrators only">' . $label . '</span>';
            $nav .= '<li>' . $adminLink('/settings', 'Settings') . '</li><li>' . $adminLink('/admin/people', 'People') . '</li><li>' . $adminLink('/admin/timetable', 'Timetable') . '</li>';
            $nav .= '<li><a' . $active('/account') . ' href="/account">My Account</a></li>';
            $nav .= '<li><a href="/logout">Log out</a></li>';
        }
        $nav .= '</ul></nav>';
        $tenant = self::$tenantOrganisation === null ? '' : '<p class="tenant-name">' . self::e(self::$tenantOrganisation['name']) . '</p>';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($title) . ' · Reqsheet</title><link rel="stylesheet" href="/assets/app.css"></head><body><div class="site-shell">' . $nav . '<main class="site-main">' . $tenant . $body . '</main></div></body></html>';
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
