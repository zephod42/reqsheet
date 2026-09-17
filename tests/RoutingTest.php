<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\ApplicationRoute;

final class RoutingTest
{
    public static function run(): void
    {
        assertSameValue(ApplicationRoute::ROOT, ApplicationRoute::match('GET', '/'), 'GET / was not routed to the root.');
        assertSameValue(ApplicationRoute::HEALTH, ApplicationRoute::match('GET', '/health'), 'GET /health was not routed to health.');
        assertSameValue(ApplicationRoute::HEALTH, ApplicationRoute::match('POST', '/health'), 'POST /health lost its method handling.');

        foreach ([
            '/unknown',
            '/.git/HEAD',
            '/composer.json',
            '/AGENTS.md',
            '/PRODUCT_DESIGN.md',
            '/ROADMAP.md',
            '/SETUP.md',
            '/SECURITY.md',
            '/database/migrations/0002_create_application_domain.sql',
            '/reference/REQSHEET_REFERENCE.md',
            '/.env',
            '/.env.migrate',
        ] as $path) {
            assertSameValue(ApplicationRoute::NOT_FOUND, ApplicationRoute::match('GET', $path), 'Unknown path was not routed to 404: ' . $path);
        }
    }
}
