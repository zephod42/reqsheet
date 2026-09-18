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
                $organisationId = $this->accounts->createOrganisationAdmin(
                    (string) ($input['school_name'] ?? ''), (string) ($input['display_name'] ?? ''),
                    (string) ($input['operational_role'] ?? 'teacher'), (string) ($input['password'] ?? ''),
                    (string) ($input['password_confirmation'] ?? ''),
                    (string) ($input['tenant_slug'] ?? ''),
                );
                $account = $this->accounts->authenticate((string) $input['display_name'], (string) $input['password']);
                if ($this->handoffs === null || $this->baseHost === '') throw new \RuntimeException('Tenant onboarding is not configured.');
                $token = $this->handoffs->issue((int) $account['id'], $organisationId);
                $tenantSlug = $this->accounts->organisationTenantSlug($organisationId);
                if ($tenantSlug === null) throw new \RuntimeException('Created organisation has no tenant slug.');
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
        $preview = $suggestedSlug !== '' && $this->baseHost !== '' ? '<p>Preview: <code>' . $this->e(strtolower(trim($suggestedSlug) . '.' . $this->baseHost)) . '</code></p>' : '';
        $body = '<section class="content-narrow"><h1>Sign up</h1><p>Create a school and its first admin account.</p>' . $notice . '<form method="post"><label>School name<input name="school_name" value="' . $this->e($schoolName) . '" required></label><label>Tenant slug<input name="tenant_slug" value="' . $this->e($suggestedSlug) . '" required pattern="[a-z0-9][a-z0-9-]{0,61}[a-z0-9]?"><small>Use a short lowercase hostname label. The deployment-configured domain is not part of the tenant identity.</small></label>' . $preview . '<label>Your name/login<input name="display_name" value="' . $this->e((string) ($input['display_name'] ?? '')) . '" required></label><label>Operational role<select name="operational_role"><option value="teacher">Teacher</option><option value="technician">Technician</option></select></label><label>Password<input type="password" name="password" minlength="8" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="8" required></label><button>Create school and admin account</button></form></section>';
        return PageLayout::render('Sign up', $body);
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
