<?php

declare(strict_types=1);

namespace App\Mail;

/** To the super admin (since v1.6): one person recorded more hours in a day than the alert's threshold. */
final class UnusualUsageMail extends AccountMail
{
    public function __construct(
        public readonly string $name,
        public readonly string $email,
        public readonly float $hours,
        public readonly int $limit,
        public readonly string $url,
    ) {}

    protected function subjectLine(): string
    {
        return "Unusual use: {$this->name} recorded {$this->hours} hours in a day";
    }

    protected function template(): string
    {
        return 'mail.unusual-usage';
    }

    protected function data(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'hours' => $this->hours, 'limit' => $this->limit, 'url' => $this->url];
    }
}
