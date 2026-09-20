<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\ApplicationRoute;
use Reqsheet\Http\RequestExceptionLogger;

final class RoutingTest
{
    public static function run(): void
    {
        assertSameValue(ApplicationRoute::ROOT, ApplicationRoute::match('GET', '/'), 'GET / was not routed to the root.');
        assertSameValue(ApplicationRoute::HEALTH, ApplicationRoute::match('GET', '/health'), 'GET /health was not routed to health.');
        assertSameValue(ApplicationRoute::HEALTH, ApplicationRoute::match('POST', '/health'), 'POST /health lost its method handling.');
        assertSameValue(ApplicationRoute::ADMIN_TIMETABLE, ApplicationRoute::match('GET', '/admin/timetable'), 'Admin timetable route was not recognised.');
        assertSameValue(ApplicationRoute::ADMIN_TIMETABLE_EXPORT, ApplicationRoute::match('GET', '/admin/timetable/export.csv'), 'Blank timetable CSV export route was not recognised.');
        assertSameValue(ApplicationRoute::NOT_FOUND, ApplicationRoute::match('POST', '/admin/timetable/export.csv'), 'Blank timetable CSV export accepted a non-GET request.');
        assertSameValue(ApplicationRoute::ADMIN_TIMETABLE_IMPORT, ApplicationRoute::match('POST', '/admin/timetable/import'), 'Timetable CSV import route was not recognised.');
        assertSameValue(ApplicationRoute::NOT_FOUND, ApplicationRoute::match('GET', '/admin/timetable/import'), 'Timetable CSV import accepted a non-POST request.');
        assertSameValue(ApplicationRoute::ADMIN_TIMETABLE_IMPORT_CONFIRM, ApplicationRoute::match('POST', '/admin/timetable/import/confirm'), 'Timetable CSV confirmation route was not recognised.');
        assertSameValue(ApplicationRoute::NOT_FOUND, ApplicationRoute::match('GET', '/admin/timetable/import/confirm'), 'Timetable CSV confirmation accepted a non-POST request.');
        assertSameValue(ApplicationRoute::ADMIN_TIMETABLE_RESOURCES, ApplicationRoute::match('GET', '/admin/timetable/resources.csv'), 'Timetable resource CSV route was not recognised.');
        assertSameValue(ApplicationRoute::TEACHER_WEEK, ApplicationRoute::match('GET', '/teacher'), 'Teacher route was not recognised.');
        assertSameValue(ApplicationRoute::TEACHER_WEEK, ApplicationRoute::match('GET', '/teacher/week'), 'Teacher week route was not recognised.');
        assertSameValue(ApplicationRoute::TEACHER_DAY, ApplicationRoute::match('GET', '/teacher/day'), 'Teacher day route was not recognised.');
        assertSameValue(ApplicationRoute::SETUP, ApplicationRoute::match('GET', '/setup'), 'Setup route was not recognised.');
        assertSameValue(ApplicationRoute::LOGIN, ApplicationRoute::match('GET', '/login'), 'Login route was not recognised.');
        assertSameValue(ApplicationRoute::ACCOUNT_RECOVERY, ApplicationRoute::match('GET', '/account-recovery'), 'Account recovery route was not recognised.');
        assertSameValue(ApplicationRoute::RECOVERY_KEY, ApplicationRoute::match('GET', '/recovery-key'), 'Recovery-key management route was not recognised.');
        assertSameValue(ApplicationRoute::LOGOUT, ApplicationRoute::match('GET', '/logout'), 'Logout route was not recognised.');
        assertSameValue(ApplicationRoute::TECHNICIAN, ApplicationRoute::match('GET', '/technician'), 'Technician route was not recognised.');
        assertSameValue(ApplicationRoute::ADMIN_PEOPLE, ApplicationRoute::match('GET', '/admin/people'), 'Admin people route was not recognised.');
        assertSameValue(ApplicationRoute::SIGNUP, ApplicationRoute::match('GET', '/signup'), 'Signup route was not recognised.');
        assertSameValue(ApplicationRoute::ONBOARDING, ApplicationRoute::match('GET', '/onboarding'), 'Onboarding route was not recognised.');
        assertSameValue(ApplicationRoute::SETTINGS, ApplicationRoute::match('GET', '/settings'), 'Settings route was not recognised.');
        assertSameValue(ApplicationRoute::MY_ACCOUNT, ApplicationRoute::match('GET', '/account'), 'My Account route was not recognised.');

        $requestId = RequestExceptionLogger::requestId('request-1234');
        assertSameValue('request-1234', $requestId, 'A safe incoming request ID was not preserved.');
        $log = RequestExceptionLogger::format('/teacher', new \RuntimeException("token=private-value\nquery failed"), $requestId);
        assertContainsValue('request_id=request-1234', $log, 'Exception log omitted the request correlation ID.');
        assertContainsValue('route=/teacher', $log, 'Exception log omitted the affected route.');
        assertContainsValue('exception=RuntimeException', $log, 'Exception log omitted the exception class.');
        assertContainsValue('origin=', $log, 'Exception log omitted the originating file and line.');
        assertContainsValue('token=[redacted]', $log, 'Exception log did not redact a sensitive token value.');
        assertNotContainsValue('private-value', $log, 'Exception log retained a sensitive token value.');

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
