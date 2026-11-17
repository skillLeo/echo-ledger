<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\MakeAssistReportJob;
use App\Models\AssistSession;
use App\Models\AssistUsage;
use App\Services\Assist\AssistBudget;
use Illuminate\Console\Command;

/**
 * Live Assist's leftovers (v1.9, every 5 minutes): a reservation whose call never settled (its process died) is settled
 * at its worst case, marked lost, so it reaches the month's ledger; a report stuck in the queue past its wait and its
 * time is queued once more, then marked failed.
 */
final class SweepAssist extends Command
{
    protected $signature = 'assist:sweep';

    protected $description = 'Settle Live Assist reservations left behind and queue reports again that never came';

    /** A call's limit and a margin, in seconds: Ask 60 s, a report 420 s (its job's timeout). */
    public const PENDING_AFTER = ['ask' => 60 + 60, 'report' => 420 + 60];

    public function handle(AssistBudget $budget): int
    {
        $settled = 0;
        foreach (self::PENDING_AFTER as $kind => $seconds) {
            AssistUsage::query()->where('kind', $kind)->where('outcome', AssistBudget::PENDING)->where('occurred_at', '<', now()->subSeconds($seconds))
                ->each(function (AssistUsage $row) use ($budget, $kind, &$settled): void {
                    $model = (string) config("assist.{$kind}.model", $row->model);
                    $budget->settle($row, $model, $row->model, ['input' => 0, 'cached' => 0, 'written' => 0, 'output' => 0], lost: true);
                    $settled++;
                });
        }

        $stuckAfter = (int) config('assist.report_delay_seconds', 30) + (new MakeAssistReportJob(''))->timeout + 60;
        $requeued = 0;
        AssistSession::query()->withoutGlobalScopes()->where('status', 'ended')->where('report_status', 'queued')
            ->where('updated_at', '<', now()->subSeconds($stuckAfter))
            ->each(function (AssistSession $session) use (&$requeued): void {
                MakeAssistReportJob::retryOrFail($session);
                $requeued++;
            });
        $this->info("Settled {$settled} reservations; {$requeued} reports queued again or marked failed.");

        return self::SUCCESS;
    }
}
