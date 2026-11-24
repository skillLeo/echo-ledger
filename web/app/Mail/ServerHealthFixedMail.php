<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the super admins, once, when the scheduled health check passes again after a failure they were emailed about
 * (W-INC-1125). Sent at once, not queued, like ServerHealthMail.
 */
final class ServerHealthFixedMail extends Mailable
{
    public function __construct(
        public readonly string $php,
        public readonly string $failingSince,
        public readonly string $url,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: Brand::name().': fixed, the server stores recordings again');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.server-health-fixed', with: ['php' => $this->php, 'url' => $this->url]);
    }
}
