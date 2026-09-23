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
        $assetPath = dirname(__DIR__, 2) . '/public/assets/app.css';
        $assetVersion = is_file($assetPath) ? (string) filemtime($assetPath) : '1';
        $currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $active = static fn (string $path): string => ($path === '/teacher' ? in_array($currentPath, ['/teacher', '/teacher/week'], true) : ($path === '/how-to' ? str_starts_with($currentPath, '/how-to') : $currentPath === $path)) ? ' class="active" aria-current="page"' : '';
        $nav = '<nav class="site-nav"><a class="wordmark" href="/">Reqsheet α</a><ul><li><a' . $active('/about') . ' href="/about">About</a></li><li><a' . $active('/demo') . ' href="/demo">Demo</a></li>' . ($user === null && self::$tenantOrganisation === null ? '<li><a' . $active('/signup') . ' href="/signup">Sign up</a></li>' : '') . '<li><a' . $active('/contact') . ' href="/contact">Contact</a></li>';
        if ($user !== null) {
            $nav .= '<li class="nav-separator" role="separator" aria-hidden="true"></li>';
            $landing = SessionAuth::landingPath($user);
            $landingLabel = $landing === '/technician' ? 'Technician' : ($landing === '/teacher' ? 'View My Timetable' : 'Settings');
            $nav .= '<li class="nav-divider"><a' . $active($landing) . ' href="' . $landing . '">' . $landingLabel . '</a></li>';
            if (SessionAuth::hasRole($user, 'teacher')) $nav .= '<li class="nav-subitem"><a' . $active('/teacher/day') . ' href="/teacher/day">Day View</a></li><li class="nav-subitem"><a' . $active('/teacher/class') . ' href="/teacher/class">Class View</a></li>';
            $admin = SessionAuth::isAdmin($user);
            $adminLink = fn (string $path, string $label): string => $admin ? '<a' . $active($path) . ' href="' . $path . '">' . $label . '</a>' : '<span class="nav-disabled" aria-disabled="true" title="Administrators only">' . $label . '</span>';
            $nav .= '<li>' . $adminLink('/settings', 'Settings') . '</li><li>' . $adminLink('/admin/people', 'People') . '</li><li>' . $adminLink('/admin/timetable', 'Timetable') . '</li>';
            $nav .= '<li><a' . $active('/account') . ' href="/account">My Account</a></li>';
            $nav .= '<li class="nav-divider"><a' . $active('/how-to') . ' href="/how-to">How-to</a></li>';
            if (str_starts_with($currentPath, '/how-to')) {
                $teacherGuide = SessionAuth::hasRole($user, 'teacher');
                $technicianGuide = SessionAuth::hasRole($user, 'technician');
                $administratorGuide = SessionAuth::isAdmin($user);
                $guideLink = static fn (string $path, string $label, bool $allowed): string => '<li class="nav-subitem">' . ($allowed ? '<a' . $active($path) . ' href="' . $path . '">' . $label . '</a>' : '<span class="nav-disabled" aria-disabled="true" title="This guide is not available for your role">' . $label . '</span>') . '</li>';
                $nav .= $guideLink('/how-to/teacher', 'Teacher', $teacherGuide) . $guideLink('/how-to/technician', 'Technician', $technicianGuide) . $guideLink('/how-to/administrator', 'Administrator', $administratorGuide);
            }
            $nav .= '<li><a href="/logout">Log out</a></li>';
        }
        $nav .= '</ul></nav>';
        $tenant = self::$tenantOrganisation === null ? '' : '<p class="tenant-name">' . self::e(self::$tenantOrganisation['name']) . '</p>';
        $metadata = self::metadata($title, $currentPath);
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($title) . ' · Reqsheet</title>' . $metadata . '<link rel="stylesheet" href="/assets/app.css?v=' . rawurlencode($assetVersion) . '"></head><body><div class="site-shell">' . $nav . '<main class="site-main">' . self::alphaBanner() . $tenant . $body . '</main></div></body></html>';
    }

    public static function alphaBanner(): string
    {
        return '<aside class="alpha-banner" role="note">Reqsheet is currently in alpha testing. Do not rely solely on Reqsheet at this stage. Read more about what this means <a href="/alpha">here</a>.</aside>';
    }

    private static function metadata(string $title, string $path): string
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        try {
            $baseHosts = TenantHostResolver::configuredBaseHosts($environment, $_SERVER);
            $host = TenantHostResolver::canonicalPublicHost($environment, $baseHosts) ?? 'reqsheet.com';
            if ($host === 'pumba' || $host === 'localhost' || str_ends_with($host, '.duckdns.org')) $host = 'reqsheet.com';
        } catch (\Throwable) {
            $host = 'reqsheet.com';
        }
        $base = 'https://' . $host;
        $image = $base . '/assets/reqsheet-og.png';
        $description = 'Science requisitions, simplified';
        $escapedTitle = self::e($title . ' · Reqsheet');
        $escapedDescription = self::e($description);
        $escapedImage = self::e($image);
        $escapedUrl = self::e($base . ($path === '/' ? '/' : $path));
        return '<meta name="description" content="' . $escapedDescription . '"><link rel="canonical" href="' . $escapedUrl . '"><meta property="og:title" content="' . $escapedTitle . '"><meta property="og:description" content="' . $escapedDescription . '"><meta property="og:type" content="website"><meta property="og:url" content="' . $escapedUrl . '"><meta property="og:image" content="' . $escapedImage . '"><meta property="og:image:width" content="1200"><meta property="og:image:height" content="630"><meta property="og:image:alt" content="Reqsheet — Science requisitions, simplified"><meta name="twitter:card" content="summary_large_image"><meta name="twitter:title" content="' . $escapedTitle . '"><meta name="twitter:description" content="' . $escapedDescription . '"><meta name="twitter:image" content="' . $escapedImage . '">';
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
