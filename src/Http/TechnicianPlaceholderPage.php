<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class TechnicianPlaceholderPage
{
    public function render(?array $user = null): string
    {
        return PageLayout::render('Technician', '<section class="content-narrow technician-placeholder"><p class="eyebrow">Technician</p><h1>Technician interface</h1><p class="notice">The technician day view is not yet implemented.</p><p><a class="button secondary" href="/logout">Log out</a></p></section>', $user);
    }
}
