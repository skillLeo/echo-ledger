<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AssistSession;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Assist\AssistReport;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * The report of an ended Live Assist session (GET /api/assist/sessions/{id}/report, v1.9 L7: App\Services\Assist\
 * AssistReport). It runs about 30 seconds after the end, so the relay's last posts are in; one that arrives later
 * anyway marks the report stale and it is made again (stale()).
 */
final class MakeAssistReportJob implements ShouldQueue
{
    use Queueable;

    /** Once: a retry would bill the model again (a report made again is counted in its runs instead). */
    public int $tries = 1;

    /** Longer than one model request (ClaudeAssistReportAi::TIMEOUT_SECONDS), so the worker never cuts one off. */
    public int $timeout = 420;

    /** The queue (and its connection) of its own: config/queue.php `assist-reports`, retry_after 480 s. */
    public const QUEUE = 'assist-reports';

    public function __construct(public readonly string $sessionId)
    {
        $this->onConnection(self::QUEUE)->onQueue(self::QUEUE);
    }

    /** Queues the report of [$session], after the wait for the relay's last posts. */
    public static function enqueue(AssistSession $session): void
    {
        self::dispatch($session->id)->delay(now()->addSeconds((int) config('assist.report_delay_seconds', 30)));
    }

    /**
     * The relay posted to an ended session (a suggestion's words, the facts or the summary): its report is made again
     * with it. A report already queued reads it when it runs; one being made sees the count change and runs again.
     */
    public static function stale(AssistSession $session): void
    {
        if (! $session->ended()) {
            return;
        }
        $requeue = DB::transaction(function () use ($session): bool {
            $locked = AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->withTrashed()->lockForUpdate()->find($session->id);
            if (! $locked instanceof AssistSession || $locked->trashed()) {
                return false;
            }
            $ready = $locked->report_status === 'ready';
            $locked->forceFill(['report_rev' => $locked->report_rev + 1, 'report_status' => 'queued'])->save();

            return $ready;
        });
        if ($requeue) {
            self::enqueue($session);
        }
    }

    public function handle(): void
    {
        $session = AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->find($this->sessionId);
        if (! $session instanceof AssistSession || ! $session->ended()) {
            return;
        }
        $rev = $session->report_rev;
        $report = app(AssistReport::class)->make($session, $session->report);
        $again = DB::transaction(function () use ($session, $rev, $report): bool {
            $locked = AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->lockForUpdate()->find($session->id);
            if (! $locked instanceof AssistSession) {
                return false;
            }
            // The relay posted while this report was being made: keep it for now and make it again.
            $changed = $locked->report_rev !== $rev;
            $locked->forceFill(['report' => $report, 'report_status' => $changed ? 'queued' : 'ready'])->save();

            return $changed;
        });
        if ($again) {
            self::enqueue($session);
        }
    }
}
