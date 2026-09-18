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
        assertSameValue(ApplicationRoute::ADMIN_TIMETABLE, ApplicationRoute::match('GET', '/admin/timetable'), 'Admin timetable route was not recognised.');
        assertSameValue(ApplicationRoute::TEACHER_WEEK, ApplicationRoute::match('GET', '/teacher'), 'Teacher route was not recognised.');
        assertSameValue(ApplicationRoute::TEACHER_WEEK, ApplicationRoute::match('GET', '/teacher/week'), 'Teacher week route was not recognised.');
        assertSameValue(ApplicationRoute::SETUP, ApplicationRoute::match('GET', '/setup'), 'Setup route was not recognised.');
        assertSameValue(ApplicationRoute::LOGIN, ApplicationRoute::match('GET', '/login'), 'Login route was not recognised.');
        assertSameValue(ApplicationRoute::LOGOUT, ApplicationRoute::match('GET', '/logout'), 'Logout route was not recognised.');
        assertSameValue(ApplicationRoute::TECHNICIAN, ApplicationRoute::match('GET', '/technician'), 'Technician route was not recognised.');
        assertSameValue(ApplicationRoute::ADMIN_PEOPLE, ApplicationRoute::match('GET', '/admin/people'), 'Admin people route was not recognised.');
        assertSameValue(ApplicationRoute::SIGNUP, ApplicationRoute::match('GET', '/signup'), 'Signup route was not recognised.');
        assertSameValue(ApplicationRoute::ONBOARDING, ApplicationRoute::match('GET', '/onboarding'), 'Onboarding route was not recognised.');
        assertSameValue(ApplicationRoute::SETTINGS, ApplicationRoute::match('GET', '/settings'), 'Settings route was not recognised.');

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
