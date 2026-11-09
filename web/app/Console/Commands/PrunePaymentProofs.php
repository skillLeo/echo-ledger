<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Entitlements\PaymentProofs;
use Illuminate\Console\Command;

/**
 * Payment receipts (since v1.7): the files of requests approved, declined or cancelled 90 days ago are erased; the
 * rows stay, marked erased. Runs daily.
 */
final class PrunePaymentProofs extends Command
{
    protected $signature = 'echoledger:prune-payment-proofs';

    protected $description = 'Erases payment receipt files 90 days after their request was decided or cancelled';

    public function handle(PaymentProofs $proofs): int
    {
        $erased = $proofs->prune();
        $this->info("{$erased} receipt(s) erased.");

        return self::SUCCESS;
    }
}
