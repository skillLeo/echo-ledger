<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;

/**
 * To the account's address after a change (since the security review of 2.0.0-l): "Put my address back" was sent to
 * the address before (LINK_SENT), or used (RESTORED: this address no longer signs in). Whoever controls the old mailbox
 * cannot use the link without this address hearing of it.
 */
final class EmailRestoreNoticeMail extends AccountMail
{
    public const LINK_SENT = 'link_sent';

    public const RESTORED = 'restored';

    public function __construct(public readonly string $kind, public readonly string $maskedOld) {}

    protected function subjectLine(): string
    {
        return $this->kind === self::RESTORED
            ? 'Your '.Brand::name().' account went back to its previous address'
            : 'Your previous address can undo the change of your '.Brand::name().' account';
    }

    protected function template(): string
    {
        return 'mail.email-restore-notice';
    }

    protected function data(): array
    {
        return ['restored' => $this->kind === self::RESTORED, 'oldAddress' => $this->maskedOld];
    }
}
