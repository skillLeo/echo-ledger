<?php

declare(strict_types=1);

namespace App\Mail;

/** Staff need another payment receipt for a person's upgrade request (since v1.7), with their note. */
final class UpgradeReceiptRequestedMail extends AccountMail
{
    public function __construct(
        public readonly string $plan,
        public readonly string $reference,
        public readonly string $note,
        public readonly string $url,
    ) {}

    protected function subjectLine(): string
    {
        return "We need another receipt for {$this->plan}";
    }

    protected function template(): string
    {
        return 'mail.upgrade-receipt-requested';
    }

    protected function data(): array
    {
        return ['plan' => $this->plan, 'reference' => $this->reference, 'note' => $this->note, 'url' => $this->url];
    }
}
