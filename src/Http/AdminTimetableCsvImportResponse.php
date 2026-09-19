<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final readonly class AdminTimetableCsvImportResponse
{
    public function __construct(public int $status, public string $html, public ?string $location = null) {}
}
