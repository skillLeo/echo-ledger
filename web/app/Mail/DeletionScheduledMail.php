<?php

declare(strict_types=1);

namespace App\Mail;

/** The account asked to be deleted: when it happens, and how to keep it until then. */
final class DeletionScheduledMail extends AccountMail
{
    public function __construct(public readonly string $date, public readonly string $keepUrl) {}

    protected function subjectLine(): string
    {
        return "Your EchoLedger account will be deleted on {$this->date}";
    }

    protected function template(): string
    {
        return 'mail.deletion-scheduled';
    }

    protected function data(): array
    {
        return ['date' => $this->date, 'keepUrl' => $this->keepUrl];
    }
}
