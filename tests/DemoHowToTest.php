<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\ApplicationRoute;
use Reqsheet\Http\DemoPage;
use Reqsheet\Http\HowToPage;
use Reqsheet\Http\PageLayout;

final class DemoHowToTest
{
    public static function run(): void
    {
        $_SERVER['REQUEST_URI'] = '/demo';
        $demo = DemoPage::render(null);
        foreach (['See Reqsheet in action', 'Your teaching week at a glance', 'Everything you need for the day', 'Plan ahead and look back', 'Technician Day View', 'Mark as Prepped', 'Configure the timetable template', 'Download the Reqsheet CSV template', 'Add and manage teachers, technicians, and admins with ease.', 'teacher-week-view.png', 'admin-csv-import.png'] as $fragment) {
            if (!str_contains($demo, $fragment)) throw new \RuntimeException('Demo page is missing: ' . $fragment);
        }
        if (str_contains($demo, 'No pop-ups, no page changes.')) throw new \RuntimeException('Demo page claims modal editing is inline.');
        if (preg_match('/<figcaption|<\/figcaption>/i', $demo) === 1) throw new \RuntimeException('Demo page still renders permanent screenshot captions.');
        if (!str_contains($demo, 'alt="Teacher Week View showing a teaching week')) throw new \RuntimeException('Demo screenshot alt text was removed.');

        $teacher = ['roles' => ['teacher'], 'is_admin' => false];
        $_SERVER['REQUEST_URI'] = '/how-to/teacher';
        $teacherGuide = HowToPage::render('teacher', $teacher);
        if (!str_contains($teacherGuide, 'Nothing required') || !str_contains($teacherGuide, '/teacher/day')) throw new \RuntimeException('Teacher how-to content is incomplete.');
        $_SERVER['REQUEST_URI'] = '/how-to/administrator';
        HowToPage::render('administrator', $teacher);
        if (http_response_code() !== 403) throw new \RuntimeException('Unauthorised how-to section was not denied.');
        if (preg_match('/<figcaption|<\/figcaption>/i', $teacherGuide) === 1) throw new \RuntimeException('How-to page still renders permanent screenshot captions.');
        if (!str_contains($teacherGuide, 'alt="Teacher Week View lesson tiles')) throw new \RuntimeException('How-to screenshot alt text was removed.');

        $multiRole = ['roles' => ['teacher', 'technician', 'administrator'], 'is_admin' => true];
        $_SERVER['REQUEST_URI'] = '/how-to';
        $landing = HowToPage::render('', $multiRole);
        foreach (['/how-to/teacher', '/how-to/technician', '/how-to/administrator'] as $link) if (!str_contains($landing, 'href="' . $link . '"')) throw new \RuntimeException('Combined-role how-to link is missing: ' . $link);

        $routes = ['/how-to' => ApplicationRoute::HOW_TO, '/how-to/teacher' => ApplicationRoute::HOW_TO_TEACHER, '/how-to/technician' => ApplicationRoute::HOW_TO_TECHNICIAN, '/how-to/administrator' => ApplicationRoute::HOW_TO_ADMINISTRATOR];
        foreach ($routes as $path => $expected) if (ApplicationRoute::match('GET', $path) !== $expected) throw new \RuntimeException('How-to route did not match: ' . $path);

        PageLayout::setTenantOrganisation(['id' => 1, 'name' => 'School', 'tenant_slug' => 'school']);
        $_SERVER['REQUEST_URI'] = '/login';
        $tenantPage = DemoPage::render(null);
        if (str_contains($tenantPage, 'href="/signup"')) throw new \RuntimeException('Tenant navigation still exposes Sign up.');
        PageLayout::setTenantOrganisation(null);
        $genericPage = DemoPage::render(null);
        if (!str_contains($genericPage, 'href="/signup"')) throw new \RuntimeException('Generic navigation lost Sign up.');

        $notFound = \Reqsheet\Http\NotFoundPage::school('reqsheet.com');
        foreach (['School not found', "We couldn't find a Reqsheet school at this address.", 'Go to Reqsheet', 'https://reqsheet.com/'] as $fragment) {
            if (!str_contains($notFound, $fragment)) throw new \RuntimeException('Styled school 404 is missing: ' . $fragment);
        }
        if (!str_contains($notFound, 'og:image')) throw new \RuntimeException('Public metadata is missing from the styled school 404.');
        if (!str_contains($notFound, 'https://reqsheet.com/assets/reqsheet-og.png') || str_contains($notFound, 'Fast. Clean. Simple.')) throw new \RuntimeException('Open Graph metadata is not using the agreed public image/description.');
        if (!is_file(dirname(__DIR__) . '/public/assets/reqsheet-og.png')) throw new \RuntimeException('Open Graph image asset is missing.');
    }
}
