<?php

declare(strict_types=1);

namespace App\Mail;

/** A teammate sent a recording or a project (Send to a teammate, v1.5): it waits in Shared with me. */
final class ShareReceivedMail extends AccountMail
{
    public function __construct(
        public readonly string $sender,
        public readonly string $what,
        public readonly bool $project,
        public readonly string $url,
        public readonly ?string $note = null,
    ) {}

    protected function subjectLine(): string
    {
        return "{$this->sender} sent you {$this->what}";
    }

    protected function template(): string
    {
        return 'mail.share-received';
    }

    protected function data(): array
    {
        return ['sender' => $this->sender, 'what' => $this->what, 'project' => $this->project, 'url' => $this->url, 'note' => $this->note];
    }
}
