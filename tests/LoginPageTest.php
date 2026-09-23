<?php

declare(strict_types=1);

namespace Reqsheet\Tests;

use Reqsheet\Http\LoginPage;
use Reqsheet\Http\PageLayout;

final class LoginPageTest
{
    public static function run(): void
    {
        PageLayout::setTenantOrganisation(null);
        $generic = (new LoginPage())->form();
        assertContainsValue('Initials', $generic, 'Generic login did not use initials as the login label.');
        assertNotContainsValue('School 4', $generic, 'Generic login invented a school identity.');

        $school = (new LoginPage(['id' => 4, 'name' => 'School & Four', 'tenant_slug' => 'sch4']))->form();
        assertContainsValue('School &amp; Four', $school, 'School login did not escape the trusted organisation name.');
        assertContainsValue('(sch4)', $school, 'School login did not show the tenant short code.');
        assertContainsValue('maxlength="3"', $school, 'Login initials field has no three-character limit.');
        assertContainsValue('class="staff-identifier"', $school, 'Login initials field is not compactly styled.');
        assertContainsValue('Remember me', $school, 'School login did not expose Remember me.');
        assertNotContainsValue('name="remember_me" value="1" checked', $school, 'Remember me was checked by default.');
        assertContainsValue('href="/account-recovery">Account Recovery', $school, 'School login did not expose tenant account recovery.');
        assertContainsValue('class="alpha-banner"', $school, 'School login did not render the global alpha banner.');
        assertContainsValue('href="/alpha">here</a>', $school, 'School login alpha banner did not link to the information page.');
        assertNotContainsValue('Account Recovery', $generic, 'Generic login exposed organisation account recovery.');
        assertNotContainsValue('Remember me', $generic, 'Generic login exposed school-only Remember me.');
        assertContainsValue('/assets/app.css?v=', $school, 'Rendered pages do not version the stylesheet asset.');
    }
}
