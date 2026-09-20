<?php

declare(strict_types=1);

namespace Reqsheet\Http;

use Reqsheet\Account\AccountService;
use Reqsheet\Account\AccountValidationException;
use Reqsheet\Recovery\RecoveryException;
use Reqsheet\Recovery\RecoveryService;
use Reqsheet\Recovery\RecoverySession;

final class RecoveryKeyPage
{
    /** @param array<string,mixed> $user */
    public function __construct(private readonly RecoveryService $recovery, private readonly AccountService $accounts, private readonly array $user)
    {
    }

    /** @param array<string,mixed> $input */
    public function handle(string $method, array $input): string
    {
        $organisationId = (int) $this->user['organisation_id'];
        $userId = (int) $this->user['id'];
        $message = null;
        $error = false;
        if ($method === 'POST') {
            try {
                if (!CsrfToken::valid($input['csrf_token'] ?? null)) throw new RecoveryException('The form expired. Please try again.');
                $action = (string) ($input['action'] ?? '');
                if ($action === 'acknowledge') {
                    $presentation = RecoverySession::presentation($organisationId, $userId);
                    if ($presentation === null || ($input['saved'] ?? '') !== 'yes') throw new RecoveryException('Confirm that you have saved the complete recovery key.');
                    $this->recovery->acknowledge($organisationId, $userId, $presentation['generation']);
                    RecoverySession::clearPresentation();
                    $fresh = $this->accounts->findUserById($userId);
                    if ($fresh === null) throw new RecoveryException('Your account is unavailable.');
                    SessionAuth::login($fresh);
                    return PageLayout::render('Recovery key saved', '<section class="content-narrow"><h1>Recovery key saved</h1><p>The organisation recovery key is active. The original key will not be shown again.</p><p><a class="button" href="' . $this->e(SessionAuth::landingPath($fresh)) . '">Continue to Reqsheet</a></p></section>', $fresh);
                }
                if ($action !== 'replace' || ($input['confirm_replace'] ?? '') !== 'yes') throw new RecoveryException('Confirm that you want to issue a new recovery key.');
                $issued = $this->recovery->replaceAuthenticated($organisationId, $userId, (string) ($input['current_password'] ?? ''));
                $fresh = $this->accounts->findUserById($userId);
                if ($fresh === null) throw new RecoveryException('Your account is unavailable.');
                SessionAuth::login($fresh);
                RecoverySession::present($organisationId, $userId, $issued);
            } catch (RecoveryException | AccountValidationException $exception) {
                $message = $exception->getMessage();
                $error = true;
            }
        }

        $presentation = RecoverySession::presentation($organisationId, $userId);
        if ($presentation !== null) return PageLayout::render('Organisation Recovery Key', $this->presentation($presentation['key'], $message, $error), $this->user);
        $metadata = $this->recovery->metadata($organisationId);
        $pending = ($this->user['account_state'] ?? 'claimed') === 'recovery_pending';
        return PageLayout::render('Organisation Recovery Key', $this->replacementForm($metadata['digest'] === null, $pending, $message, $error), $this->user);
    }

    private function presentation(string $key, ?string $message, bool $error): string
    {
        $notice = $message === null ? '' : '<p class="notice ' . ($error ? 'error' : '') . '">' . $this->e($message) . '</p>';
        return '<section class="content-narrow recovery-key"><h1>Organisation Recovery Key</h1>' . $notice . '<div class="notice error"><strong>WARNING: This private recovery key is the only way to recover your organisation if all administrators lose access.<br><br>Anyone with this private recovery key can take control of your organisation\'s Reqsheet accounts.<br><br>Keep it secret. Keep it safe.</strong></div><p>Reqsheet cannot retrieve the original key. Losing both administrator access and this key may make the organisation unrecoverable. The key can reset any existing administrator account, so share it only with authorised people.</p><label>Your new recovery key<textarea id="recovery-key-value" rows="3" readonly spellcheck="false" autocomplete="off">' . $this->e($key) . '</textarea></label><p><button type="button" class="secondary" data-copy-recovery-key>Copy complete key</button></p><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="acknowledge"><label class="check-label"><input type="checkbox" name="saved" value="yes" required> I have saved the complete recovery key somewhere secure.</label><button>Acknowledge and continue</button></form></section><script>document.querySelector("[data-copy-recovery-key]").addEventListener("click",function(){var value=document.getElementById("recovery-key-value");navigator.clipboard.writeText(value.value);});</script>';
    }

    private function replacementForm(bool $firstSetup, bool $pending, ?string $message, bool $error): string
    {
        $notice = $message === null ? '' : '<p class="notice ' . ($error ? 'error' : '') . '">' . $this->e($message) . '</p>';
        $heading = $firstSetup ? 'Set up Organisation Recovery Key' : 'Replace Recovery Key';
        $explanation = $pending
            ? 'The previous key presentation was interrupted. Issue another replacement key to continue; any key from the interrupted presentation will become invalid.'
            : ($firstSetup ? 'This school does not yet have a recovery key. Establish one now.' : 'Use this if the current key has been lost or may have been compromised. The previous key will immediately become invalid.');
        return '<section class="content-narrow"><h1>' . $heading . '</h1>' . $notice . '<p>' . $this->e($explanation) . '</p><form method="post"><input type="hidden" name="csrf_token" value="' . $this->e(CsrfToken::value()) . '"><input type="hidden" name="action" value="replace"><label>Current password<input type="password" name="current_password" autocomplete="current-password" required></label><label class="check-label"><input type="checkbox" name="confirm_replace" value="yes" required> I understand that issuing this key invalidates the previous recovery key.</label><button>' . $heading . '</button></form></section>';
    }

    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
