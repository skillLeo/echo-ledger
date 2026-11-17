<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistTurn;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * The report of an ended Live Assist session (GET /api/assist/sessions/{id}/report): how long it ran, the questions
 * asked, each suggestion shown and what was done with it, the running summary and the facts the relay kept. Made from
 * what the session stored, with no model call (v1.9 L1). It runs about 30 seconds after the end, so the relay's last
 * posts are in; one that arrives later anyway marks the report stale and it is made again (stale()).
 */
final class MakeAssistReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $sessionId) {}

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
        $report = $this->build($session);
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

    /** @return array<string, mixed> */
    private function build(AssistSession $session): array
    {
        $turns = $session->turns()->orderBy('start_ms')->get();
        $suggestions = $session->suggestions()->orderBy('id')->get();

        return [
            'mode' => $session->mode,
            'practice' => $session->practice,
            'started_at' => $session->started_at->toAtomString(),
            'ended_at' => $session->ended_at?->toAtomString(),
            'seconds' => $session->seconds(),
            'turns' => $turns->count(),
            'questions' => $turns->filter(fn (AssistTurn $t): bool => $t->channel === 'other' && $t->is_question)
                ->map(fn (AssistTurn $t): array => ['at_ms' => $t->start_ms, 'text' => $t->text])->values()->all(),
            'suggestions' => $suggestions->filter(fn (AssistSuggestion $s): bool => $s->text !== null)
                ->map(fn (AssistSuggestion $s): array => [
                    'id' => $s->suggestion_id, 'turn_id' => $s->turn_id, 'kind' => $s->kind, 'mode' => $s->mode,
                    'text' => $s->text, 'action' => $s->action,
                ])->values()->all(),
            'used' => $suggestions->where('action', 'used')->count(),
            'summary' => $session->summary,
            'facts' => $session->facts ?? [],
        ];
    }
}
