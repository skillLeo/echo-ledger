<?php

declare(strict_types=1);

namespace App\Mail;

/** The super admin approved or declined a person's upgrade request (since v1.6). */
final class UpgradeDecidedMail extends AccountMail
{
    public function __construct(
        public readonly bool $approved,
        public readonly string $plan,
        public readonly ?string $until,
        public readonly ?string $reply,
        public readonly string $url,
    ) {}

    protected function subjectLine(): string
    {
        return $this->approved ? "You are on {$this->plan} now" : "About your request for {$this->plan}";
    }

    protected function template(): string
    {
        return 'mail.upgrade-decided';
    }

    protected function data(): array
    {
        return ['approved' => $this->approved, 'plan' => $this->plan, 'until' => $this->until, 'reply' => $this->reply, 'url' => $this->url];
    }
}
