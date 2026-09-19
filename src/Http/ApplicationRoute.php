<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class ApplicationRoute
{
    public const ROOT = 'root';
    public const SIGNUP = 'signup';
    public const SETTINGS = 'settings';
    public const ABOUT = 'about';
    public const DEMO = 'demo';
    public const CONTACT = 'contact';
    public const HEALTH = 'health';
    public const ADMIN_TIMETABLE = 'admin_timetable';
    public const ADMIN_TIMETABLE_EXPORT = 'admin_timetable_export';
    public const ADMIN_TIMETABLE_IMPORT = 'admin_timetable_import';
    public const ADMIN_TIMETABLE_IMPORT_CONFIRM = 'admin_timetable_import_confirm';
    public const ADMIN_TIMETABLE_RESOURCES = 'admin_timetable_resources';
    public const TEACHER_WEEK = 'teacher_week';
    public const TEACHER_DAY = 'teacher_day';
    public const SETUP = 'setup';
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const TECHNICIAN = 'technician';
    public const ADMIN_PEOPLE = 'admin_people';
    public const NOT_FOUND = 'not_found';
    public const ONBOARDING = 'onboarding';
    public const MY_ACCOUNT = 'my_account';

    public static function match(string $method, string $path): string
    {
        if ($path === '/health') {
            return self::HEALTH;
        }
        if ($path === '/admin/timetable') {
            return self::ADMIN_TIMETABLE;
        }
        if ($method === 'GET' && $path === '/admin/timetable/export.csv') {
            return self::ADMIN_TIMETABLE_EXPORT;
        }
        if ($method === 'POST' && $path === '/admin/timetable/import') {
            return self::ADMIN_TIMETABLE_IMPORT;
        }
        if ($method === 'POST' && $path === '/admin/timetable/import/confirm') {
            return self::ADMIN_TIMETABLE_IMPORT_CONFIRM;
        }
        if ($method === 'GET' && $path === '/admin/timetable/resources.csv') {
            return self::ADMIN_TIMETABLE_RESOURCES;
        }
        if ($path === '/teacher' || $path === '/teacher/week') {
            return self::TEACHER_WEEK;
        }
        if ($path === '/teacher/day') {
            return self::TEACHER_DAY;
        }
        if ($path === '/setup') return self::SETUP;
        if ($path === '/login') return self::LOGIN;
        if ($path === '/logout') return self::LOGOUT;
        if ($path === '/technician') return self::TECHNICIAN;
        if ($path === '/admin/people') return self::ADMIN_PEOPLE;
        if ($path === '/signup') return self::SIGNUP;
        if ($path === '/onboarding') return self::ONBOARDING;
        if ($path === '/settings') return self::SETTINGS;
        if ($path === '/account') return self::MY_ACCOUNT;
        if ($path === '/about') return self::ABOUT;
        if ($path === '/demo') return self::DEMO;
        if ($path === '/contact') return self::CONTACT;
        if ($method === 'GET' && $path === '/') {
            return self::ROOT;
        }

        return self::NOT_FOUND;
    }
}
