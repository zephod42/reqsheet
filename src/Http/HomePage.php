<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class HomePage
{
    public function render(): string
    {
        return PageLayout::render('Home', '<section class="content-narrow"><h1>Reqsheet.</h1><h2>Log in</h2>' . LoginPage::fields() . '</section>');
    }
}
