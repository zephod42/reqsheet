<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Recovery\RecoveryException;
use Reqsheet\Recovery\RecoveryService;
use Reqsheet\Recovery\RecoverySession;

final class AccountRecoveryPage
{
    /** @param array{id:int,name:string,tenant_slug:string} $organisation */
    public function __construct(private readonly RecoveryService $recovery, private readonly AccountService $accounts, private readonly array $organisation, private readonly string $clientIdentity)
    {
    }

    /** @param array<string,mixed> $input */
    public function handle(string $method, array $input): string
    {
        $organisationId = (int) $this->organisation['id'];
        $message = null;
        $stage = RecoverySession::flow($organisationId) === null ? 'verify' : 'password';
        if ($method === 'POST') {
            try {
                if (!CsrfToken::valid($input['csrf_token'] ?? null)) throw new RecoveryException('The form expired. Please try again.');
                $action = (string) ($input['action'] ?? '');
                if ($action === 'cancel') {
                    $token = RecoverySession::flow($organisationId);
                    if ($token !== null) $this->recovery->cancel($organisationId, $token);
                    RecoverySession::clearFlow();
                    $stage = 'verify';
                } elseif ($action === 'verify') {
                    if (strlen((string) ($input['staff_identifier'] ?? '')) > 16 || strlen((string) ($input['recovery_key'] ?? '')) > 128) throw new RecoveryException('Recovery could not be verified. Check the details and try again later.');
                    $flow = $this->recovery->begin($organisationId, (string) ($input['staff_identifier'] ?? ''), (string) ($input['recovery_key'] ?? ''), $this->clientIdentity);
                    RecoverySession::setFlow($organisationId, $flow['token']);
                    $stage = 'password';
                } elseif ($action === 'password') {
                    $token = RecoverySession::flow($organisationId);
                    if ($token === null) throw new RecoveryException('This recovery attempt has expired or is no longer valid. Start Account Recovery again.');
                    if (strlen((string) ($input['password'] ?? '')) > 4096 || strlen((string) ($input['password_confirmation'] ?? '')) > 4096) throw new RecoveryException('The password is too long.');
                    $completed = $this->recovery->complete($organisationId, $token, (string) ($input['password'] ?? ''), (string) ($input['password_confirmation'] ?? ''));
                    RecoverySession::clearFlow();
                    $account = $this->accounts->findUserById($completed['user_id']);
                    if ($account === null) throw new RecoveryException('The recovered account is unavailable.');
                    SessionAuth::login($account);
                    RecoverySession::present($organisationId, $completed['user_id'], ['key' => $completed['key'], 'generation' => $completed['generation']]);
                    return (new RecoveryKeyPage($this->recovery, $this->accounts, $account))->handle('GET', []);
                } else {
                    throw new RecoveryException('Recovery could not be completed.');
                }
            } catch (RecoveryException | AccountValidationException $exception) {
                $message = $exception instanceof AccountValidationException ? implode(' ', $exception->errors()) : $exception->getMessage();
            }
        }
        return PageLayout::render('Account Recovery', $stage === 'password' ? $this->passwordForm($message) : $this->verificationForm($message));
    }

    private function verificationForm(?string $message): string
    {
        $notice = $message === null ? '' : '<p class="notice error">' . $this->e($message) . '</p>';
        return '<section class="content-narrow"><p class="eyebrow">' . $this->e($this->organisation['name']) . '</p><h1>Account Recovery</h1>' . $notice . '<p>Recover an existing administrator, or enter unused initials to create a new Administrator account for this school. Initials belonging to other staff or deleted accounts cannot be reused.</p><div class="notice"><strong>The recovery key is highly sensitive.</strong> Anyone who has it may be able to take control of administrator accounts. Use it only on this secure school page.</div><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="verify"><label>Administrator initials<input class="staff-identifier" name="staff_identifier" maxlength="3" pattern="[A-Za-z]{3}" autocomplete="username" required autofocus></label><label>Organisation Recovery Key<input type="password" name="recovery_key" maxlength="128" autocomplete="off" required></label><button>Continue account recovery</button></form><p><a href="/login">Return to login</a></p></section>';
    }

    private function passwordForm(?string $message): string
    {
        $notice = $message === null ? '' : '<p class="notice error">' . $this->e($message) . '</p>';
        return '<section class="content-narrow"><p class="eyebrow">' . $this->e($this->organisation['name']) . '</p><h1>Choose a new password</h1>' . $notice . '<p>The recovery details were verified. Choose a password to recover the administrator or create an Administrator-only account. Any existing sessions for the recovered administrator will be revoked. You must save the replacement recovery key before access is restored.</p><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="password"><label>New password<input type="password" name="password" minlength="8" maxlength="4096" autocomplete="new-password" required></label><label>Confirm new password<input type="password" name="password_confirmation" minlength="8" maxlength="4096" autocomplete="new-password" required></label><button>Set password and rotate recovery key</button></form><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="cancel"><button class="secondary">Cancel and start again</button></form></section>';
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
