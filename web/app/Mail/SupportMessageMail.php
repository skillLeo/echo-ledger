<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A message to support from someone of this server (v1.8 P6.4, App\Services\Help\SupportMessages); reply goes to them. */
final class SupportMessageMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param  list<array<string, mixed>>  $reports */
    public function __construct(
        public readonly string $fromName,
        public readonly string $fromEmail,
        public readonly string $topic,
        public readonly string $body,
        public readonly array $reports,
        public readonly ?string $platform,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: '['.Brand::name().' support] '.($this->topic !== '' ? $this->topic : 'A question'),
            replyTo: [new Address($this->fromEmail, $this->fromName)],
        );
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.support-message', with: [
            'name' => $this->fromName, 'email' => $this->fromEmail, 'topic' => $this->topic, 'body' => $this->body,
            'platform' => $this->platform, 'reported' => $this->reports !== [],
        ]);
    }

    /** @return list<Attachment> the device reports, when ticked, as one JSON file */
    public function attachments(): array
    {
        return $this->reports === [] ? [] : [
            Attachment::fromData(fn (): string => (string) json_encode($this->reports, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'device-report.json')
                ->withMime('application/json'),
        ];
    }
}
