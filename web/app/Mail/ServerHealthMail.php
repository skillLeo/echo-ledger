<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the super admins, once an hour while the scheduled health check fails (W-INC-1124). Sent at once, not queued: the
 * queue runs on the same PHP that is failing.
 */
final class ServerHealthMail extends Mailable
{
    /** @param list<string> $missing */
    public function __construct(
        public readonly array $missing,
        public readonly ?string $cipher,
        public readonly string $php,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Brand::name().': the server cannot store recordings safely');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.server-health', with: [
            'missing' => $this->missing, 'cipher' => $this->cipher, 'php' => $this->php, 'url' => $this->url,
        ]);
    }
}
