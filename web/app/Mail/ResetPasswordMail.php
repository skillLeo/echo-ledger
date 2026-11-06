<?php

declare(strict_types=1);

namespace App\Mail;

/** "Forgot password?" on the sign-in page: a link to choose a new password. */
final class ResetPasswordMail extends AccountMail
{
    public function __construct(public readonly string $url) {}

    protected function subjectLine(): string
    {
        return 'Reset your EchoLedger password';
    }

    protected function template(): string
    {
        return 'mail.reset-password';
    }

    protected function data(): array
    {
        $broker = (string) config('auth.defaults.passwords', 'users');

        return ['url' => $this->url, 'minutes' => (int) config("auth.passwords.{$broker}.expire", 60)];
    }
}
