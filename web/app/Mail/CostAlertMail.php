<?php

declare(strict_types=1);

namespace App\Mail;

/** To staff who see usage (since v1.6, P2.6): this month's estimated cost passed a level of the budget alert. */
final class CostAlertMail extends AccountMail
{
    public function __construct(
        public readonly int $level,
        public readonly float $spent,
        public readonly float $budget,
        public readonly string $url,
    ) {}

    protected function subjectLine(): string
    {
        return $this->level >= 100 ? 'EchoLedger: the monthly budget is reached' : "EchoLedger: {$this->level}% of the monthly budget";
    }

    protected function template(): string
    {
        return 'mail.cost-alert';
    }

    protected function data(): array
    {
        return ['level' => $this->level, 'spent' => $this->spent, 'budget' => $this->budget, 'url' => $this->url];
    }
}
