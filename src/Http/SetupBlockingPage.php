<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class SetupBlockingPage
{
    public function render(?array $user = null): string
    {
        return PageLayout::render('Setup required', '<section class="blocking-state"><div><h1>Something\'s missing...</h1><p>Settings need to be configured. Contact your admin.</p></div></section>', $user);
    }
}
