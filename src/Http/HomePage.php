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
        $warning = '<p class="public-landing-warning">Reqsheet alpha. Testing phase. Expect the unexpected. Do not rely upon this resource (yet). Feedback appreciated <a href="mailto:feedback@reqsheet.com">feedback@reqsheet.com</a></p>';
        return PageLayout::render('Home', '<section class="public-landing"><div class="public-landing-content"><h1>Reqsheet.</h1><p class="public-landing-tagline">Fast. Clean. Simple.</p>' . $signup . $guidance . '</div>' . $warning . '</section>', $user);
    }
}
