<?php

declare(strict_types=1);

namespace Reqsheet;

final class HealthCheck
{
    public static function status(): string
    {
        return 'ok';
    }
}
