<?php

declare(strict_types=1);

namespace App\Mail;

/** "Export my data" finished: the ZIP's link, which works for 24 hours. */
final class ExportReadyMail extends AccountMail
{
    public function __construct(public readonly string $url, public readonly string $expires, public readonly string $size) {}

    protected function subjectLine(): string
    {
        return 'Your EchoLedger export is ready';
    }

    protected function template(): string
    {
        return 'mail.export-ready';
    }

    protected function data(): array
    {
        return ['url' => $this->url, 'expires' => $this->expires, 'size' => $this->size];
    }
}
