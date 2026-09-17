<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Reqsheet\HealthCheck;

header('Content-Type: text/plain; charset=UTF-8');
echo 'Reqsheet ' . HealthCheck::status() . PHP_EOL;
