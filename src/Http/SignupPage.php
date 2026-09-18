<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;

final class SignupPage
{
    public function __construct(private readonly AccountService $accounts)
    {
    }

    /** @param array<string, mixed> $input */
    public function handle(string $method, array $input): string
    {
        $message = null;
        if ($method === 'POST') {
            try {
                $this->accounts->createOrganisationAdmin(
                    (string) ($input['school_name'] ?? ''), (string) ($input['display_name'] ?? ''),
                    (string) ($input['operational_role'] ?? 'teacher'), (string) ($input['password'] ?? ''),
                    (string) ($input['password_confirmation'] ?? ''),
                );
                $account = $this->accounts->authenticate((string) $input['display_name'], (string) $input['password']);
                SessionAuth::login($account);
                return '<meta http-equiv="refresh" content="0;url=/settings"><p>Continuing to settings…</p>';
            } catch (AccountValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }
        $notice = $message === null ? '' : '<p class="notice error">' . $this->e($message) . '</p>';
        $body = '<section class="content-narrow"><h1>Sign up</h1><p>Create a school and its first admin account.</p>' . $notice . '<form method="post"><label>School name<input name="school_name" required></label><label>Your name/login<input name="display_name" required></label><label>Operational role<select name="operational_role"><option value="teacher">Teacher</option><option value="technician">Technician</option></select></label><label>Password<input type="password" name="password" minlength="8" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="8" required></label><button>Create school and admin account</button></form></section>';
        return PageLayout::render('Sign up', $body);
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
