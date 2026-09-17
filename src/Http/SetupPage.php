<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;

final class SetupPage
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
                $this->accounts->createFirstOrganisation(
                    (string) ($input['organisation_name'] ?? ''),
                    (string) ($input['display_name'] ?? ''),
                    isset($input['staff_identifier']) ? (string) $input['staff_identifier'] : null,
                    (string) ($input['operational_role'] ?? ''),
                    (string) ($input['password'] ?? ''),
                    (string) ($input['password_confirmation'] ?? ''),
                );
                return '<!doctype html><meta charset="utf-8"><title>Reqsheet setup complete</title><main><h1>Setup complete</h1><p>The first account is ready. <a href="/login">Continue to login</a>.</p></main>';
            } catch (AccountValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }
        if (!$this->accounts->setupAvailable()) return $this->simple('Setup is no longer available.');
        return $this->form($message);
    }

    private function form(?string $message): string
    {
        $notice = $message === null ? '' : '<p class="error">' . $this->e($message) . '</p>';
        return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reqsheet first-run setup</title><style>body{font:16px system-ui,sans-serif;margin:2rem;max-width:38rem}label{display:block;margin:1rem 0}input,select,button{font:inherit;padding:.45rem;width:100%;box-sizing:border-box}.error{background:#fee;padding:.7rem}</style><main><h1>First-run setup</h1><p>Create the first Reqsheet organisation and administrator.</p>' . $notice . '<form method="post"><label>Organisation / school name<input name="organisation_name" required></label><label>First user name/login<input name="display_name" required></label><label>Staff abbreviation (optional)<input name="staff_identifier"></label><label>Operational role<select name="operational_role"><option value="teacher">Teacher</option><option value="technician">Technician</option></select></label><label>Password<input type="password" name="password" minlength="8" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="8" required></label><button>Create organisation and admin account</button></form></main>';
    }

    private function simple(string $message): string { return '<!doctype html><meta charset="utf-8"><title>Reqsheet setup</title><main><p>' . $this->e($message) . '</p></main>'; }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
