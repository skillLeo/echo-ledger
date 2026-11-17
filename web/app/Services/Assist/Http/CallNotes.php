<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Jobs\MakeAssistReportJob;
use App\Jobs\UpdateLiveFactsJob;
use App\Jobs\UpdateLiveSummaryJob;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\Scopes\WorkspaceScope;
use App\Services\Assist\Http\Ai\ModelFailure;
use App\Services\Assist\Http\Prompt\Transcript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * What the server keeps of a call beside its suggestions, in the background (services/assist-relay/src/background.rs,
 * docs/API_ASSIST.md "Practice, facts and summary"), on the queue so that it never holds up a suggestion:
 *
 * - the facts ledger: after every `facts_every_finals` final lines of either side (an "okay" does not count), the new
 *   facts in them, added to the ledger the next suggestions read and stored for the report;
 * - the running summary: at most every `summary_every_secs`, when the call moved on, rewritten from the last one and
 *   the new lines.
 *
 * Each runs on the Fast model within the budget: its worst case on both models must fit what is free beside the
 * suggestions' reserve (one suggestion in Fast, `reserve_suggestions` otherwise), else it waits with its lines kept for
 * the next chance. Not in captions-only mode. A call that failed after it started loses the lines it took, as the relay's.
 */
class CallNotes
{
    /** The lines a call keeps for the next facts call or summary at most, and their tokens (the relay's LineBuffer). */
    public const MAX_LINES = 200;

    public const MAX_LINE_TOKENS = 12000;

    public function __construct(
        private readonly LiveContext $context,
        private readonly ModeChoice $modes,
        private readonly BackgroundCalls $calls,
        private readonly Pricing $pricing,
    ) {}

    /** A final line of the call (not a backchannel) was stored: it counts toward the next facts call and summary. */
    public function lineSeen(AssistSession $session): void
    {
        $id = $session->id;
        $every = max(1, (int) config('assist.background.facts_every_finals', 3));
        if ($this->counted('facts.lines.'.$id) >= $every && Cache::add('assist.live.facts.busy.'.$id, 1, 120)) {
            UpdateLiveFactsJob::dispatch($id);
        }
        $lines = $this->counted('summary.lines.'.$id);
        $due = Cache::get('assist.live.summary.due.'.$id);
        $due = is_int($due) ? $due : $session->started_at->getTimestamp() + (int) config('assist.background.summary_every_secs', 300);
        if ($lines > 0 && now()->getTimestamp() >= $due && Cache::add('assist.live.summary.busy.'.$id, 1, 120)) {
            UpdateLiveSummaryJob::dispatch($id);
        }
    }

    /** The facts call (the queue job). */
    public function facts(string $sessionId): void
    {
        $session = $this->session($sessionId);
        try {
            if ($session === null || $session->ended()) {
                return;
            }
            $this->runFacts($session);
        } finally {
            Cache::forget('assist.live.facts.busy.'.$sessionId);
        }
    }

    /** The summary call (the queue job). */
    public function summary(string $sessionId): void
    {
        $session = $this->session($sessionId);
        try {
            if ($session === null || $session->ended()) {
                return;
            }
            $this->runSummary($session);
        } finally {
            Cache::forget('assist.live.summary.busy.'.$sessionId);
        }
    }

    private function runFacts(AssistSession $session): void
    {
        $id = $session->id;
        $mode = $this->modes->choose($session, $this->modes->requested($session))['mode'];
        if ($mode === 'captions_only') {
            return;
        }
        [$lines, $last] = $this->lines($session, (int) Cache::get('assist.live.facts.cursor.'.$id, 0));
        if ($lines === []) {
            $this->took('facts', $session, $last);

            return;
        }
        try {
            $result = $this->calls->run(
                $session, 'facts', 'facts-'.self::id(), Background::facts($this->context->get($session), $lines),
                (int) config('assist.background.max_output_tokens', 400), keepCents: $this->keep($mode),
            );
        } catch (ModelFailure) {
            // The lines left their buffer when the call started: they are not asked again.
            $this->took('facts', $session, $last);

            return;
        }
        if ($result === null) {
            // No room for it beside the suggestions: it waits, its lines kept, for the next chance.
            return;
        }
        $this->took('facts', $session, $last);
        $new = Background::parseFacts($result['text']);
        if ($new !== []) {
            $ledger = $this->context->addFacts($session, $new);
            AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($id)->first()?->forceFill(['facts' => $ledger, 'facts_at' => now()])->save();
            MakeAssistReportJob::stale($session);
        }
    }

    private function runSummary(AssistSession $session): void
    {
        $id = $session->id;
        $mode = $this->modes->choose($session, $this->modes->requested($session))['mode'];
        if ($mode === 'captions_only') {
            return;
        }
        [$lines, $last] = $this->lines($session, (int) Cache::get('assist.live.summary.cursor.'.$id, 0));
        if ($lines === []) {
            $this->took('summary', $session, $last);

            return;
        }
        try {
            $result = $this->calls->run(
                $session, 'summary', 'summary-'.self::id(), Background::summary($this->context->get($session), $lines),
                (int) config('assist.background.max_output_tokens', 400), keepCents: $this->keep($mode),
            );
        } catch (ModelFailure) {
            $this->took('summary', $session, $last);

            return;
        }
        if ($result === null) {
            return;
        }
        $this->took('summary', $session, $last);
        $summary = Background::cleanSummary($result['text']);
        if ($summary !== null) {
            $this->context->setSummary($session, $summary);
            AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->whereKey($id)->first()?->forceFill(['summary' => $summary, 'summary_at' => now()])->save();
            MakeAssistReportJob::stale($session);
        }
    }

    /**
     * The final lines after [$cursor] that count (not "okay" and the like), at most MAX_LINES and MAX_LINE_TOKENS of the
     * newest, and the id of the last turn looked at.
     *
     * @return array{0: list<string>, 1: int}
     */
    private function lines(AssistSession $session, int $cursor): array
    {
        $lines = [];
        $last = $cursor;
        foreach (AssistTurn::query()->where('assist_session_id', $session->id)->where('id', '>', $cursor)->orderBy('id')->cursor() as $turn) {
            $last = $turn->id;
            if (! Text::isBackchannel($turn->text)) {
                $lines[] = Transcript::line(['id' => $turn->client_ref, 'channel' => $turn->channel, 'text' => $turn->text, 'start_ms' => $turn->start_ms]);
            }
        }
        $lines = array_slice($lines, -self::MAX_LINES);
        while (count($lines) > 1 && array_sum(array_map(Pricing::estimateTokens(...), $lines)) > self::MAX_LINE_TOKENS) {
            array_shift($lines);
        }

        return [$lines, $last];
    }

    /** The call took its lines (or had none): its cursor moves on, its counter starts again, the summary is next due later. */
    private function took(string $what, AssistSession $session, int $last): void
    {
        $id = $session->id;
        Cache::put("assist.live.{$what}.cursor.{$id}", $last, LiveState::TTL_SECONDS);
        Cache::put("assist.live.{$what}.lines.{$id}", 0, LiveState::TTL_SECONDS);
        if ($what === 'summary') {
            Cache::put('assist.live.summary.due.'.$id, now()->getTimestamp() + (int) config('assist.background.summary_every_secs', 300), LiveState::TTL_SECONDS);
        }
    }

    /** What the suggestions need left beside a background call, in cents: one suggestion in Fast, the usual reserve otherwise. */
    private function keep(string $mode): int
    {
        $count = $mode === 'fast' ? 1 : (int) config('assist.http.reserve_suggestions', 3);

        return (int) ceil($this->pricing->modeWorst($mode) * $count / 10_000);
    }

    private function counted(string $name): int
    {
        $key = 'assist.live.'.$name;
        Cache::add($key, 0, LiveState::TTL_SECONDS);

        return (int) Cache::increment($key);
    }

    private function session(string $id): ?AssistSession
    {
        return AssistSession::query()->withoutGlobalScope(WorkspaceScope::class)->find($id);
    }

    private static function id(): string
    {
        return Str::lower((string) Str::ulid());
    }
}
