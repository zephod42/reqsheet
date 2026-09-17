<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class TechnicianPlaceholderPage
{
    public function render(): string
    {
        return '<!doctype html><meta charset="utf-8"><title>Reqsheet technician</title><main><h1>Technician interface</h1><p>The technician interface is not yet implemented.</p><p><a href="/logout">Log out</a></p></main>';
    }
}
