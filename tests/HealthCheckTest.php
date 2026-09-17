<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\HealthCheck;

final class HealthCheckTest
{
    public static function run(): void
    {
        if (HealthCheck::status() !== 'ok') {
            throw new \RuntimeException('HealthCheck did not return the expected status.');
        }
    }
}
