<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * To the super admins, once an hour while the scheduled health check fails (W-INC-1124). Sent at once, not queued: the
 * queue runs on the same PHP that is failing. Since W-INC-1125 it names what each PHP lacks (the scheduled tasks' CLI
 * PHP and the web PHP) and the command that turns it back on.
 */
final class ServerHealthMail extends Mailable
{
    /**
     * @param  list<string>  $missing  what the scheduled tasks' PHP lacks
     * @param  list<string>  $webMissing  what the web PHP lacks (since W-INC-1125)
     */
    public function __construct(
        public readonly array $missing,
        public readonly ?string $cipher,
        public readonly string $php,
        public readonly string $url,
        public readonly array $webMissing = [],
        public readonly ?string $webCipher = null,
        public readonly bool $webFails = false,
        public readonly ?string $fix = null,
    ) {}

    public function envelope(): Envelope
    {
        $names = array_values(array_unique([...$this->missing, ...$this->webMissing]));

        return new Envelope(subject: Brand::name().': '.($names !== [] ? 'PHP is missing '.implode(', ', $names).', so ' : '').'the server cannot store recordings safely');
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.server-health', with: [
            'missing' => $this->missing, 'cipher' => $this->cipher, 'php' => $this->php, 'url' => $this->url,
            'webMissing' => $this->webMissing, 'webCipher' => $this->webCipher, 'webFails' => $this->webFails, 'fix' => $this->fix,
        ]);
    }
}
