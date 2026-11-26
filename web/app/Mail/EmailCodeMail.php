<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;

/**
 * The 6-digit code that signs someone in or starts their new account (since v1.6); since 2.0.0-l also the proof for a
 * change to the account, and the new address of an email change with the console's confirmation link beside the code.
 */
final class EmailCodeMail extends AccountMail
{
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $minutes,
        public readonly ?string $url = null,
    ) {}

    protected function subjectLine(): string
    {
        return 'Your '.Brand::name()." code: {$this->code}";
    }

    protected function template(): string
    {
        return 'mail.email-code';
    }

    protected function data(): array
    {
        // Since 2.0.0-l also `confirm` (proof for an account change) and `email_change` (the new address of a change).
        $heading = match ($this->purpose) {
            'sign_up' => 'Your code to create your account',
            'confirm' => 'Your code to confirm it is you',
            'email_change' => 'Your code to confirm your new email address',
            default => 'Your code to sign in',
        };

        return ['code' => $this->code, 'signUp' => $this->purpose === 'sign_up', 'heading' => $heading, 'minutes' => $this->minutes, 'url' => $this->url,
            'signIn' => in_array($this->purpose, ['sign_in', 'sign_up'], true)];
    }
}
