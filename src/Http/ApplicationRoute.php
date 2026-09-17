<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class ApplicationRoute
{
    public const ROOT = 'root';
    public const HEALTH = 'health';
    public const ADMIN_TIMETABLE = 'admin_timetable';
    public const TEACHER_WEEK = 'teacher_week';
    public const SETUP = 'setup';
    public const LOGIN = 'login';
    public const LOGOUT = 'logout';
    public const TECHNICIAN = 'technician';
    public const ADMIN_PEOPLE = 'admin_people';
    public const NOT_FOUND = 'not_found';

    public static function match(string $method, string $path): string
    {
        if ($path === '/health') {
            return self::HEALTH;
        }
        if ($path === '/admin/timetable') {
            return self::ADMIN_TIMETABLE;
        }
        if ($path === '/teacher' || $path === '/teacher/week') {
            return self::TEACHER_WEEK;
        }
        if ($path === '/setup') return self::SETUP;
        if ($path === '/login') return self::LOGIN;
        if ($path === '/logout') return self::LOGOUT;
        if ($path === '/technician') return self::TECHNICIAN;
        if ($path === '/admin/people') return self::ADMIN_PEOPLE;
        if ($method === 'GET' && $path === '/') {
            return self::ROOT;
        }

        return self::NOT_FOUND;
    }
}
