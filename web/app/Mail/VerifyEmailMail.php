<?php

declare(strict_types=1);

namespace App\Mail;

/** The link that confirms an account's email (sign-up, a changed email, "send it again"). */
final class VerifyEmailMail extends AccountMail
{
    public function __construct(public readonly string $url) {}

    protected function subjectLine(): string
    {
        return 'Confirm your email for EchoLedger';
    }

    protected function template(): string
    {
        return 'mail.verify-email';
    }

    protected function data(): array
    {
        return ['url' => $this->url, 'minutes' => (int) config('auth.verification.expire', 60)];
    }
}
