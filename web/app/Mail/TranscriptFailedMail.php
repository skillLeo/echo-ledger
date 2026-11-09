<?php

declare(strict_types=1);

namespace App\Mail;

/** "Notify me when ready" (since v1.6): the transcript the person was waiting for could not be made. */
final class TranscriptFailedMail extends AccountMail
{
    public function __construct(public readonly string $title, public readonly string $url) {}

    protected function subjectLine(): string
    {
        return "The transcript could not be made: {$this->title}";
    }

    protected function template(): string
    {
        return 'mail.transcript-failed';
    }

    protected function data(): array
    {
        return ['title' => $this->title, 'url' => $this->url];
    }
}
