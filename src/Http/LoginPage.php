<?php

declare(strict_types=1);

namespace Reqsheet\Http;

final class LoginPage
{
    public function form(?string $message = null, string $login = ''): string
    {
        $notice = $message === null ? '' : '<p class="error">' . $this->e($message) . '</p>';
        return $this->layout('<h1>Reqsheet login</h1>' . $notice . '<form method="post"><label>Name/login<input name="login" value="' . $this->e($login) . '" required autofocus></label><label>Password<input type="password" name="password" required></label><button>Log in</button></form>');
    }

    public function firstLogin(string $login, ?string $message = null): string
    {
        $notice = $message === null ? '' : '<p class="error">' . $this->e($message) . '</p>';
        return $this->layout('<h1>Set your first password</h1><p>This account is awaiting its first login password.</p>' . $notice . '<form method="post"><input type="hidden" name="action" value="claim"><input type="hidden" name="login" value="' . $this->e($login) . '"><label>Password<input type="password" name="password" minlength="8" required autofocus></label><label>Confirm password<input type="password" name="password_confirmation" minlength="8" required></label><button>Set password</button></form>');
    }

    private function layout(string $body): string { return '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Reqsheet login</title><style>body{font:16px system-ui,sans-serif;margin:2rem;max-width:32rem}label{display:block;margin:1rem 0}input,button{font:inherit;padding:.45rem;width:100%;box-sizing:border-box}.error{background:#fee;padding:.7rem}</style><main>' . $body . '</main>'; }
    private function e(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}
