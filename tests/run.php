<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\HealthCheck;

// Temporary dependency-free smoke runner used before PHPUnit is installed.
if (HealthCheck::status() !== 'ok') {
    throw new RuntimeException('HealthCheck did not return the expected status.');
}

fwrite(STDOUT, "1 test passed.\n");
