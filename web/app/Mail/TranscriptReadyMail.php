<?php

declare(strict_types=1);

namespace App\Mail;

/** A recording's transcript is ready (only for people who turned this email on in their profile). */
final class TranscriptReadyMail extends AccountMail
{
    public function __construct(public readonly string $title, public readonly string $url) {}

    protected function subjectLine(): string
    {
        return "Transcript ready: {$this->title}";
    }

    protected function template(): string
    {
        return 'mail.transcript-ready';
    }

    protected function data(): array
    {
        return ['title' => $this->title, 'url' => $this->url];
    }
}
