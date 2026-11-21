<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;

/**
 * To the old address once an email change is done (since the security review of 2.0.0-l): the new address, masked,
 * and a link that needs no sign-in to put the old one back and sign out everywhere. Encrypted while queued, as the
 * link is a key.
 */
final class EmailChangedMail extends AccountMail
{
    public function __construct(
        public readonly string $maskedNew,
        public readonly string $restoreUrl,
        public readonly int $days,
    ) {}

    protected function subjectLine(): string
    {
        return 'The email address of your '.Brand::name().' account was changed';
    }

    protected function template(): string
    {
        return 'mail.email-changed';
    }

    protected function data(): array
    {
        return ['newAddress' => $this->maskedNew, 'url' => $this->restoreUrl, 'linkDays' => $this->days];
    }
}
