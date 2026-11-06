<?php

declare(strict_types=1);

namespace App\Mail;

/** A new device signed in to the account, or the console was opened from a browser it had not seen before. */
final class LoginAlertMail extends AccountMail
{
    public function __construct(
        public readonly string $what,
        public readonly string $when,
        public readonly ?string $ip,
        public readonly ?string $country,
        public readonly string $devicesUrl,
    ) {}

    protected function subjectLine(): string
    {
        return "New sign-in: {$this->what}";
    }

    protected function template(): string
    {
        return 'mail.login-alert';
    }

    protected function data(): array
    {
        return ['what' => $this->what, 'when' => $this->when, 'ip' => $this->ip, 'country' => $this->country, 'devicesUrl' => $this->devicesUrl];
    }
}
