<?php

declare(strict_types=1);

namespace App\Services\Usage;

/**
 * Answers whether the monthly transcription limit is reached (docs/API.md "Usage and monthly limit"). The answer is
 * remembered by this instance only, which the container builds per request or job.
 */
final class BudgetGuard
{
    private ?bool $reached = null;

    public function __construct(private readonly UsageSummary $usage) {}

    public function limitReached(): bool
    {
        return $this->reached ??= $this->usage->toArray()['limit_reached'];
    }
}
