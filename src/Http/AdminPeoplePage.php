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
        $error = false;
        if ($method === 'POST') {
            if (!CsrfToken::valid($input['csrf_token'] ?? null)) {
                $message = 'The form expired. Please try again.';
                $error = true;
            } else {
                try {
                    if (($input['action'] ?? '') === 'reset_password') {
                        if (($input['confirm_reset'] ?? '') !== 'yes') throw new AccountValidationException(['Confirm the password reset before continuing.']);
                        $this->accounts->resetPassword((int) ($this->user['id'] ?? 0), $this->organisationId, (int) ($input['person_id'] ?? 0));
                        $message = 'Password reset. The account is ready for initial password setup.';
                    } else {
                    $roles = is_array($input['roles'] ?? null) ? array_values(array_map('strval', $input['roles'])) : [];
                    if (($input['action'] ?? '') === 'edit') {
                        $this->accounts->updatePerson($this->organisationId, (int) ($input['person_id'] ?? 0), (string) ($input['display_name'] ?? ''), (string) ($input['staff_identifier'] ?? ''), $roles);
                        $message = 'Person updated.';
                    } else {
                        $id = $this->accounts->createPerson($this->organisationId, (string) ($input['display_name'] ?? ''), (string) ($input['staff_identifier'] ?? ''), $roles);
                        $message = 'Person created. They can set a password on first login.';
                    }
                    }
                } catch (AccountValidationException $exception) {
                    $message = implode(' ', $exception->errors());
                    $error = true;
                }
            }
        }
        return PageLayout::render('People', $this->render($this->accounts->people($this->organisationId), $message, $error), $this->user);
    }

    /** @param list<array<string, mixed>> $people */
    private function render(array $people, ?string $message, bool $error): string
    {
        $notice = $message === null ? '' : '<p class="notice ' . ($error ? 'error' : '') . '">' . $this->e($message) . '</p>';
        $rows = '';
        $dialogs = '';
        foreach ($people as $person) {
            $id = (int) $person['id'];
            $roles = $this->roles($person);
            $reset = ($person['is_admin'] ?? false) ? '' : '<form method="post" class="inline-form" onsubmit="return confirm(\'Reset this password?\')"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="reset_password"><input type="hidden" name="person_id" value="' . $id . '"><input type="hidden" name="confirm_reset" value="yes"><button type="submit" class="secondary">Reset password</button></form>';
            $rows .= '<tr><th scope="row">' . $this->e((string) $person['display_name']) . '</th><td>' . (!empty($person['staff_identifier']) ? $this->e((string) $person['staff_identifier']) : '<span class="muted">—</span>') . '</td><td>' . $this->e($this->roleLabels($roles)) . '</td><td><button type="button" class="secondary" data-open-dialog="person-' . $id . '">' . 'Edit</button>' . $reset . '</td></tr>';
            $dialogs .= str_replace('pattern="[A-Za-z]{3}" autocomplete="username"', 'pattern="[A-Z]{3}" title="Please use three capital letters." autocomplete="username"', $this->personDialog($person, $roles));
        }
        if ($rows === '') $rows = '<tr><td colspan="4">No people have been added yet.</td></tr>';
        $addDialog = str_replace('pattern="[A-Za-z]{3}" autocomplete="username"', 'pattern="[A-Z]{3}" title="Please use three capital letters." autocomplete="username"', $this->personDialog(null, []));
        $body = '<section class="content-wide"><div class="page-header"><div><p class="eyebrow">Admin</p><h1>People</h1></div><a class="button secondary" href="/admin/timetable">Timetable</a></div>' . $notice . '<p>People belong only to this organisation. Staff codes must use three capital letters. Roles are cumulative and can be changed by an administrator.</p><div class="people-table-wrap"><table class="people-table"><caption class="visually-hidden">People in this organisation</caption><thead><tr><th scope="col">Full name</th><th scope="col">Initials</th><th scope="col">Roles / permissions</th><th scope="col"><span class="visually-hidden">Actions</span></th></tr></thead><tbody>' . $rows . '</tbody></table></div><p><button type="button" data-open-dialog="person-add">Add Person</button></p>' . $dialogs . $addDialog . '</section><script>' . $this->script() . '</script>';
        return $body;
    }

    /** @param array<string, mixed>|null $person @param list<string> $roles */
    private function personDialog(?array $person, array $roles): string
    {
        $id = $person === null ? 'add' : (string) (int) $person['id'];
        $title = $person === null ? 'Add Person' : 'Edit Person';
        $action = $person === null ? 'add' : 'edit';
        $roleInputs = '';
        foreach (['teacher' => 'Teacher', 'technician' => 'Technician', 'administrator' => 'Administrator'] as $value => $label) {
            $roleInputs .= '<label class="check-label"><input type="checkbox" name="roles[]" value="' . $value . '"' . (in_array($value, $roles, true) ? ' checked' : '') . '> ' . $label . '</label>';
        }
        return '<dialog id="person-' . $id . '" class="person-dialog"><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="' . $action . '">' . ($person === null ? '' : '<input type="hidden" name="person_id" value="' . (int) $person['id'] . '">') . '<button type="button" class="close secondary" data-close-dialog>Cancel</button><p class="eyebrow">Administrator</p><h2>' . $title . '</h2><label>Full name<input name="display_name" value="' . $this->e((string) ($person['display_name'] ?? '')) . '" required></label><label>Initials<input class="staff-identifier" name="staff_identifier" value="' . $this->e((string) ($person['staff_identifier'] ?? '')) . '" maxlength="3" pattern="[A-Za-z]{3}" autocomplete="username" required></label><fieldset><legend>Roles and permissions</legend>' . $roleInputs . '<small>Select at least one role.</small></fieldset><div class="form-actions"><button type="submit">Save</button><button type="button" class="secondary" data-close-dialog>Cancel</button></div></form></dialog>';
    }

    /** @param array<string, mixed> $person @return list<string> */
    private function roles(array $person): array
    {
        $roles = array_values(array_map('strval', (array) ($person['roles'] ?? [])));
        if ($roles === []) {
            if (($person['operational_role'] ?? null) === 'teacher') $roles[] = 'teacher';
            if (($person['operational_role'] ?? null) === 'technician') $roles[] = 'technician';
            if (($person['is_admin'] ?? false) === true) $roles[] = 'administrator';
        }
        return array_values(array_unique($roles));
    }

    /** @param list<string> $roles */
    private function roleLabels(array $roles): string
    {
        return implode(', ', array_map(static fn (string $role): string => ucfirst($role), $roles));
    }

    private function script(): string
    {
        return "document.addEventListener('click',function(event){var opener=event.target.closest('[data-open-dialog]');if(opener){var dialog=document.getElementById(opener.dataset.openDialog);if(dialog&&dialog.showModal)dialog.showModal();}if(event.target.matches('[data-close-dialog]')){var dialog=event.target.closest('dialog');if(dialog)dialog.close();}});";
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
