<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * Someone was added to a workspace, or invited to make an account and join it. A new account's one-time password is
 * never in the email: the person who added them shares it, or the invitation link lets them choose their own.
 */
final class InvitationMail extends AccountMail
{
    public function __construct(
        public readonly string $workspace,
        public readonly string $inviter,
        public readonly string $url,
        public readonly bool $signUp,
        public readonly ?string $expires = null,
    ) {}

    protected function subjectLine(): string
    {
        return "{$this->inviter} invited you to {$this->workspace} on EchoLedger";
    }

    protected function template(): string
    {
        return 'mail.invitation';
    }

    protected function data(): array
    {
        return ['workspace' => $this->workspace, 'inviter' => $this->inviter, 'url' => $this->url, 'signUp' => $this->signUp, 'expires' => $this->expires];
    }
}
