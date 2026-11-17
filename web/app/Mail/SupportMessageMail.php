<?php

declare(strict_types=1);

namespace App\Mail;

use App\Services\Assist\AssistRecords;
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
        // Their words shown as written, never read as Markdown or HTML: in code blocks, where nothing is a link (not even a
        // bare address, which Markdown would otherwise link), an image, a heading or a tag. Names of their own: a public
        // property of the mail is given to the view too, and would replace a value of the same name.
        return new Content(markdown: 'mail.support-message', with: [
            'nameCode' => self::inline((string) preg_replace('/\s+/u', ' ', $this->fromName)), 'emailText' => AssistRecords::md($this->fromEmail),
            'subjectBlock' => self::fenced($this->topic !== '' ? (string) preg_replace('/\s+/u', ' ', $this->topic) : 'A question'), 'messageBlock' => self::fenced($this->body),
            'platform' => $this->platform, 'reported' => $this->reports !== [],
        ]);
    }

    /** [$text] as a fenced code block whose fence is longer than any run of backticks in it. */
    public static function fenced(string $text): string
    {
        $fence = str_repeat('`', max(3, self::longestRun($text) + 1));

        return $fence."\n".str_replace(["\r\n", "\r"], "\n", trim($text))."\n".$fence;
    }

    /** [$text] as inline code, likewise fenced. */
    public static function inline(string $text): string
    {
        $fence = str_repeat('`', self::longestRun($text) + 1);

        return $fence.' '.$text.' '.$fence;
    }

    private static function longestRun(string $text): int
    {
        preg_match_all('/`+/', $text, $runs);

        return max([0, ...array_map('strlen', $runs[0])]);
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
