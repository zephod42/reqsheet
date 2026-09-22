<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\ApplicationRoute;
use Reqsheet\Http\DemoPage;
use Reqsheet\Http\HowToPage;

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

        $teacher = ['roles' => ['teacher'], 'is_admin' => false];
        $_SERVER['REQUEST_URI'] = '/how-to/teacher';
        $teacherGuide = HowToPage::render('teacher', $teacher);
        if (!str_contains($teacherGuide, 'Nothing required') || !str_contains($teacherGuide, '/teacher/day')) throw new \RuntimeException('Teacher how-to content is incomplete.');
        $_SERVER['REQUEST_URI'] = '/how-to/administrator';
        HowToPage::render('administrator', $teacher);
        if (http_response_code() !== 403) throw new \RuntimeException('Unauthorised how-to section was not denied.');

        $multiRole = ['roles' => ['teacher', 'technician', 'administrator'], 'is_admin' => true];
        $_SERVER['REQUEST_URI'] = '/how-to';
        $landing = HowToPage::render('', $multiRole);
        foreach (['/how-to/teacher', '/how-to/technician', '/how-to/administrator'] as $link) if (!str_contains($landing, 'href="' . $link . '"')) throw new \RuntimeException('Combined-role how-to link is missing: ' . $link);

        $routes = ['/how-to' => ApplicationRoute::HOW_TO, '/how-to/teacher' => ApplicationRoute::HOW_TO_TEACHER, '/how-to/technician' => ApplicationRoute::HOW_TO_TECHNICIAN, '/how-to/administrator' => ApplicationRoute::HOW_TO_ADMINISTRATOR];
        foreach ($routes as $path => $expected) if (ApplicationRoute::match('GET', $path) !== $expected) throw new \RuntimeException('How-to route did not match: ' . $path);
    }
}
