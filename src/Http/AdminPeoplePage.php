<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;

final class AdminPeoplePage
{
    public function __construct(private readonly AccountService $accounts, private readonly int $organisationId)
    {
    }

    /** @param array<string, mixed> $input */
    public function handle(string $method, array $input): string
    {
        $message = null;
        if ($method === 'POST') {
            try {
                $id = $this->accounts->createUser(
                    $this->organisationId,
                    (string) ($input['display_name'] ?? ''),
                    isset($input['staff_identifier']) ? (string) $input['staff_identifier'] : null,
                    (string) ($input['operational_role'] ?? ''),
                    ($input['is_admin'] ?? '') === '1',
                );
                $message = 'User created: ' . $id . '. They can set a password on first login.';
            } catch (AccountValidationException $exception) {
                $message = implode(' ', $exception->errors());
            }
        }
        $notice = $message === null ? '' : '<p>' . $this->e($message) . '</p>';
        return '<!doctype html><meta charset="utf-8"><title>Reqsheet people</title><style>body{font:16px system-ui,sans-serif;margin:2rem;max-width:38rem}label{display:block;margin:1rem 0}input,select,button{font:inherit;padding:.45rem;width:100%;box-sizing:border-box}</style><main><h1>People</h1>' . $notice . '<form method="post"><label>Name/login<input name="display_name" required></label><label>Staff abbreviation (optional)<input name="staff_identifier"></label><label>Operational role<select name="operational_role"><option value="teacher">Teacher</option><option value="technician">Technician</option></select></label><label>Admin permission<select name="is_admin"><option value="0">No</option><option value="1">Yes</option></select></label><button>Create user</button></form><p><a href="/admin/timetable">Back to timetable</a></p></main>';
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
