<?php

declare(strict_types=1);

namespace App\Mail;

/**
 * A recording's transcript is ready: for people who turned this email on in their profile, and since v1.6 for one
 * recording when its owner chose "Notify me when ready" ([$asked]).
 */
final class TranscriptReadyMail extends AccountMail
{
    public function __construct(public readonly string $title, public readonly string $url, public readonly bool $asked = false) {}

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
        return ['title' => $this->title, 'url' => $this->url, 'asked' => $this->asked];
    }
}
