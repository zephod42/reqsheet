<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class HomePage
{
    /** @param array<string, mixed>|null $user */
    public function render(?array $user = null): string
    {
        $signup = $user === null ? '<a class="button" href="/signup">Sign up</a>' : '';
        $guidance = $user === null ? '<p class="public-landing-guidance">Already have an account? Talk to your admin to get your unique school login.</p>' : '';
        return PageLayout::render('Home', '<section class="public-landing"><h1>Reqsheet.</h1><p class="public-landing-tagline">Fast. Clean. Simple.</p>' . $signup . $guidance . '</section>', $user);
    }
}
