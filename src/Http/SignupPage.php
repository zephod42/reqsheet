<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Auth\OnboardingHandoffService;

final class SignupPage
{
    public function __construct(private readonly AccountService $accounts, private readonly string $baseHost = '', private readonly ?OnboardingHandoffService $handoffs = null)
    {
    }

    /** @param array<string, mixed> $input */
    public function handle(string $method, array $input): string
    {
        $message = null;
        if ($method === 'POST') {
            try {
                if (trim((string) ($input['contact_email'] ?? '')) === '') throw new AccountValidationException(['Organisation contact email is required.']);
                $organisationId = $this->accounts->createOrganisationAdmin(
                    (string) ($input['school_name'] ?? ''), (string) ($input['display_name'] ?? ''),
                    (string) ($input['staff_identifier'] ?? ''),
                    (string) ($input['operational_role'] ?? 'teacher'), (string) ($input['password'] ?? ''),
                    (string) ($input['password_confirmation'] ?? ''),
                    (string) ($input['tenant_slug'] ?? ''),
                    (string) ($input['contact_email'] ?? ''),
                );
                $account = $this->accounts->authenticate((string) $input['staff_identifier'], (string) $input['password'], $organisationId);
                if ($this->handoffs === null || $this->baseHost === '') throw new \RuntimeException('School onboarding is not configured.');
                $token = $this->handoffs->issue((int) $account['id'], $organisationId);
                $tenantSlug = $this->accounts->organisationTenantSlug($organisationId);
                if ($tenantSlug === null) throw new \RuntimeException('Created school has no school address code.');
                $destination = 'https://' . $tenantSlug . '.' . $this->baseHost . '/onboarding?token=' . rawurlencode($token);
                SessionAuth::logout();
                return '<meta http-equiv="refresh" content="0;url=' . $this->e($destination) . '"><p>Continuing to your school…</p>';
            } catch (AccountValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }
        $notice = $message === null ? '' : '<p class="notice error">' . $this->e($message) . '</p>';
        $schoolName = (string) ($input['school_name'] ?? '');
        $suggestedSlug = (string) ($input['tenant_slug'] ?? \Reqsheet\Account\TenantSlug::suggest($schoolName));
        $preview = $this->baseHost !== '' ? '<small id="school-address-example">Example: <code>sch4</code> → <code>sch4.' . $this->e($this->baseHost) . '</code></small>' : '';
        $describedBy = $this->baseHost !== '' ? 'school-code-help school-address-example' : 'school-code-help';
        $body = '<section class="content-narrow"><h1>Sign up</h1><p>Create a school and its first admin account.</p>' . $notice . '<form method="post"><label>School name<input name="school_name" value="' . $this->e($schoolName) . '" required></label><label>Organisation contact email<input type="email" name="contact_email" value="' . $this->e((string) ($input['contact_email'] ?? '')) . '" required></label><label>School short code<input name="tenant_slug" value="' . $this->e($suggestedSlug) . '" aria-describedby="' . $describedBy . '" required pattern="[a-z0-9][a-z0-9-]{0,61}[a-z0-9]?"><small id="school-code-help">Choose a short code for your school. This will form part of your school\'s unique Reqsheet address.</small>' . $preview . '</label><label>Your name<input name="display_name" value="' . $this->e((string) ($input['display_name'] ?? '')) . '" required></label><label>Initials<input class="staff-identifier" name="staff_identifier" value="' . $this->e((string) ($input['staff_identifier'] ?? '')) . '" maxlength="3" pattern="[A-Za-z]{3}" autocomplete="username" required></label><label>Operational role<select name="operational_role"><option value="teacher">Teacher</option><option value="technician">Technician</option></select></label><label>Password<input type="password" name="password" minlength="8" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="8" required></label><button>Create school and admin account</button></form></section>';
        return PageLayout::render('Sign up', $body);
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
