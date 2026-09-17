<?php

declare(strict_types=1);

namespace Reqsheet;

use Reqsheet\Database\Database;

final class HealthCheck
{
    public static function status(): string
    {
        return 'ok';
    }

    public static function databaseIsHealthy(Database $database): bool
    {
        try {
            return $database->ping();
        } catch (\Throwable) {
            return false;
        }
    }
}
