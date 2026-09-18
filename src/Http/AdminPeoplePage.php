<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;

final class AdminPeoplePage
{
    /** @param array<string, mixed> $user */
    public function __construct(private readonly AccountService $accounts, private readonly int $organisationId, private readonly array $user = [])
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
        return PageLayout::render('People', '<section class="content-narrow"><div class="page-header"><div><p class="eyebrow">Admin</p><h1>People</h1></div><a class="button secondary" href="/admin/timetable">Timetable</a></div>' . $notice . '<p>Create a teacher or technician independently of timetable population. A new teacher may have an empty timetable.</p><form method="post"><label>Name/login<input name="display_name" required></label><label>Staff abbreviation (optional)<input name="staff_identifier"></label><label>Operational role<select name="operational_role"><option value="teacher">Teacher</option><option value="technician">Technician</option></select></label><label>Admin permission<select name="is_admin"><option value="0">No</option><option value="1">Yes</option></select></label><button>Create user</button></form></section>', $this->user);
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
