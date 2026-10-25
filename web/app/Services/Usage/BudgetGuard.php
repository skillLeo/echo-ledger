<?php

declare(strict_types=1);

namespace App\Services\Usage;

/**
 * Answers whether the monthly transcription limit is reached (docs/API.md "Usage and monthly limit").
 *
 * Until the usage ledger exists no limit can be reached.
 */
final class BudgetGuard
{
    public function limitReached(): bool
    {
        return false;
    }
}
