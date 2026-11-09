<?php

declare(strict_types=1);

namespace App\Mail;

/** The 6-digit code that signs someone in or starts their new account (since v1.6). */
final class EmailCodeMail extends AccountMail
{
    public function __construct(
        public readonly string $code,
        public readonly string $purpose,
        public readonly int $minutes,
    ) {}

    protected function subjectLine(): string
    {
        return "Your EchoLedger code: {$this->code}";
    }

    protected function template(): string
    {
        return 'mail.email-code';
    }

    protected function data(): array
    {
        return ['code' => $this->code, 'signUp' => $this->purpose === 'sign_up', 'minutes' => $this->minutes];
    }
}
