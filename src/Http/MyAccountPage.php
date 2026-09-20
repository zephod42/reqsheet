<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;

final class MyAccountPage
{
    public function __construct(private readonly AccountService $accounts, private readonly array $user, private readonly ?\DateTimeImmutable $today = null)
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
                    $this->accounts->changePassword(
                        (int) $this->user['id'],
                        (int) $this->user['organisation_id'],
                        (string) ($input['current_password'] ?? ''),
                        (string) ($input['new_password'] ?? ''),
                        (string) ($input['new_password_confirmation'] ?? ''),
                    );
                    $fresh = $this->accounts->findUserById((int) $this->user['id']);
                    if ($fresh !== null) SessionAuth::login($fresh);
                    $message = 'Password changed successfully.';
                } catch (AccountValidationException $exception) {
                    $message = implode(' ', $exception->errors());
                    $error = true;
                }
            }
        }
        $account = $this->accounts->findUserById((int) $this->user['id']);
        if ($account === null || (int) ($account['organisation_id'] ?? 0) !== (int) $this->user['organisation_id']) {
            return PageLayout::render('My Account', '<p class="notice error">Your account is unavailable.</p>', $this->user);
        }
        return PageLayout::render('My Account', $this->render($account, $message, $error), $this->user);
    }

    /** @param array<string, mixed> $account */
    private function render(array $account, ?string $message, bool $error): string
    {
        $notice = $message === null ? '' : '<p class="notice ' . ($error ? 'error' : '') . '">' . $this->e($message) . '</p>';
        $name = trim((string) ($account['display_name'] ?? '')) ?: 'Unnamed user';
        $code = trim((string) ($account['staff_identifier'] ?? ''));
        $roles = array_values(array_unique(array_map('strval', (array) ($account['roles'] ?? []))));
        if ($roles === [] && ($account['operational_role'] ?? null) !== null) $roles[] = (string) $account['operational_role'];
        if (($account['is_admin'] ?? false) && !in_array('administrator', $roles, true)) $roles[] = 'administrator';
        $roleLabels = array_map(static fn (string $role): string => match ($role) {
            'teacher' => 'Teacher',
            'technician' => 'Technician',
            'administrator' => 'Administrator',
            default => ucfirst(str_replace('_', ' ', $role)),
        }, $roles);
        $status = $this->statusLabel($this->accounts->organisationAccountStatus((int) $account['organisation_id']));
        $identity = '<dl class="account-identity"><dt>Full/display name</dt><dd>' . $this->e($name) . '</dd><dt>Initials/teacher code</dt><dd>' . ($code === '' ? '<span class="muted">Not entered</span>' : $this->e($code)) . '</dd><dt>Roles</dt><dd>' . ($roleLabels === [] ? '<span class="muted">No roles assigned.</span>' : $this->e(implode(', ', $roleLabels))) . '</dd><dt>School Account Status:</dt><dd>' . $this->e($status) . '</dd></dl>';
        $form = '<section class="settings-section"><h2>Change password</h2><p>You can change your own password. Name, code and roles are managed by an administrator.</p><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><label>New password<input type="password" name="new_password" minlength="8" required autocomplete="new-password"></label><label>Confirm new password<input type="password" name="new_password_confirmation" minlength="8" required autocomplete="new-password"></label><button>Change password</button></form></section>';
        return '<section class="content-narrow"><h1>My Account</h1>' . $notice . '<section class="settings-section"><h2>Your identity</h2>' . $identity . '</section>' . $form . '</section>';
    }

    /** @param array{state:?string,until:?string} $status */
    private function statusLabel(array $status): string
    {
        if ($status['state'] === null || $status['until'] === null) return 'Not configured';
        $until = \DateTimeImmutable::createFromFormat('!Y-m-d', $status['until'], new \DateTimeZone('UTC'));
        if ($until === false) return 'Not configured';
        $today = ($this->today ?? new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $days = (int) $today->diff($until)->format('%r%a');
        if ($status['state'] === 'free_trial') return 'Free Trial — ' . max(0, $days) . ' days remaining';
        if ($status['state'] === 'paid') return 'Paid Member — ' . max(0, $days) . ' days until renewal';
        return 'Not configured';
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
