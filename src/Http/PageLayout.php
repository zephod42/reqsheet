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
        $nav = '<nav class="site-nav"><a class="wordmark" href="/">Reqsheet.</a><ul><li><a href="/about">About</a></li><li><a href="/demo">Demo</a></li><li><a href="/signup">Sign up</a></li><li><a href="/contact">Contact</a></li>';
        if ($user !== null) {
            if ((bool) ($user['is_admin'] ?? false)) $nav .= '<li><a href="/settings">Settings</a></li><li><a href="/admin/people">People</a></li>';
            $nav .= '<li><a href="/logout">Log out</a></li>';
        }
        $nav .= '</ul></nav>';
        $tenant = self::$tenantOrganisation === null ? '' : '<p class="tenant-name">' . self::e(self::$tenantOrganisation['name']) . '</p>';
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . self::e($title) . ' · Reqsheet</title><link rel="stylesheet" href="/assets/app.css"></head><body><div class="site-shell">' . $nav . '<main class="site-main">' . $tenant . $body . '</main></div></body></html>';
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
