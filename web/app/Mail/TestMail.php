<?php

declare(strict_types=1);

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Settings → Email → "Send a test email": sent at once (not queued), so the page can say whether it worked. */
final class TestMail extends Mailable
{
    public function __construct(public readonly string $host) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'EchoLedger test email');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.test', with: ['host' => $this->host]);
    }
}
