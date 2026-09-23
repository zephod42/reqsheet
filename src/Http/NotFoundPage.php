<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class NotFoundPage
{
    public static function school(string $canonicalHost): string
    {
        $canonicalHost = strtolower(trim($canonicalHost));
        if ($canonicalHost === 'pumba' || $canonicalHost === 'localhost' || str_ends_with($canonicalHost, '.duckdns.org')) $canonicalHost = 'reqsheet.com';
        $home = 'https://' . trim($canonicalHost, '/') . '/';
        $body = '<section class="content-narrow not-found-page"><p class="eyebrow">Reqsheet</p><h1>School not found</h1><p>We couldn\'t find a Reqsheet school at this address. Please check the school code in the website address and try again.</p><p><a class="button" href="' . self::e($home) . '">Go to Reqsheet</a></p><p class="muted">If you already use Reqsheet, check the unique login address supplied by your administrator.</p></section>';
        return PageLayout::render('School not found', $body);
    }

    private static function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
