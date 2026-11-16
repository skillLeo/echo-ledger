<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistTurn;
use App\Models\Scopes\WorkspaceScope;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The report of an ended Live Assist session (GET /api/assist/sessions/{id}/report): how long it ran, the questions
 * asked, each suggestion shown and what was done with it, the running summary and the facts the relay kept. Made from
 * what the session stored, with no model call (v1.9 L1).
 */
final class MakeAssistReportJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $sessionId) {}

    public function handle(): void
    {
        $session = AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->find($this->sessionId);
        if (! $session instanceof AssistSession || ! $session->ended()) {
            return;
        }
        $turns = $session->turns()->orderBy('start_ms')->get();
        $suggestions = $session->suggestions()->orderBy('id')->get();
        $session->forceFill([
            'report_status' => 'ready',
            'report' => [
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
            ],
        ])->save();
    }
}
