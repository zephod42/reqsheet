<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class LoginPage
{
    public function __construct(private readonly ?array $organisation = null) {}

    public function form(?string $message = null, string $login = ''): string
    {
        $notice = $message === null ? '' : '<p class="error">' . $this->e($message) . '</p>';
        $identity = '';
        if ($this->organisation !== null) {
            $identity = '<p class="login-school">' . $this->e((string) ($this->organisation['name'] ?? '')) . ' <span>(' . $this->e((string) ($this->organisation['tenant_slug'] ?? '')) . ')</span></p><p>Please log in below:</p>';
        }
        return $this->layout($identity . '<h1>Log in</h1>' . $notice . self::fields($login));
    }

    public function firstLogin(string $login, ?string $message = null): string
    {
        $notice = $message === null ? '' : '<p class="error">' . $this->e($message) . '</p>';
        return $this->layout('<h1>Set your first password</h1><p>This account is awaiting its first login password.</p>' . $notice . '<form method="post"><input type="hidden" name="action" value="claim"><input type="hidden" name="login" value="' . $this->e($login) . '"><label>Password<input type="password" name="password" minlength="8" required autofocus></label><label>Confirm password<input type="password" name="password_confirmation" minlength="8" required></label><button>Set password</button></form>');
    }

    public static function fields(string $login = ''): string { return '<form method="post" action="/login"><label>Initials<input class="staff-identifier" name="login" value="' . htmlspecialchars($login, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '" maxlength="3" pattern="[A-Za-z]{3}" autocomplete="username" required autofocus></label><label>Password<input type="password" name="password" autocomplete="current-password" required></label><button>Log in</button></form>'; }
    private function layout(string $body): string { return PageLayout::render('Login', '<section class="content-narrow">' . $body . '</section>'); }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
