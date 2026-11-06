<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The emails EchoLedger sends to people (since v1.5): Markdown templates in resources/views/mail, styled with the
 * console's theme (resources/views/vendor/mail), always queued. They carry plain strings only, never a model, so a
 * queued email cannot leak more than it shows; secrets and recordings never go into an email.
 */
abstract class AccountMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public int $tries = 3;

    abstract protected function subjectLine(): string;

    abstract protected function template(): string;

    /** @return array<string, mixed> */
    abstract protected function data(): array;

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine());
    }

    public function content(): Content
    {
        return new Content(markdown: $this->template(), with: $this->data());
    }
}
