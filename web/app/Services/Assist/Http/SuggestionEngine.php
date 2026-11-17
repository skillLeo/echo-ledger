<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Jobs\MakeAssistReportJob;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Services\Assist\AssistBudget;
use App\Services\Assist\AssistPlans;
use App\Services\Assist\Http\Ai\ModelFailure;
use App\Services\Assist\Http\Ai\ModelGateway;
use App\Services\Assist\Http\Ai\ModelRequest;
use App\Services\Assist\Http\Prompt\Prompt;
use App\Services\Assist\Http\Prompt\PromptBuilder;
use App\Services\Assist\Http\Prompt\Transcript;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * A suggestion in HTTP mode, from the question to its end: what the relay's session does for one (services/assist-relay
 * /src/session.rs), inside the request that carries the question and with the other requests of the session only
 * through the Card.
 *
 * - The budget: a suggestion's worst case (the primary and the fallback model, Urdu and Arabic priced a token a
 *   character, the longest answer) is reserved before anything is sent (AssistBudget); when the mode's does not fit,
 *   the next cheaper allowed mode answers this one question, with no `mode_changed`; when none fits, a question the
 *   person asked for gets `budget_exhausted` (retry) and one at the end of the other side's turn is held, quiet but
 *   for keep-alives, for at most `hold_max_ms`. The reservation settles to the real cost into the usage ledger.
 * - The card: a new suggestion ends the one still being written on it (`superseded`) before its own start; `cancel`
 *   ends it `cancelled`; the request that was writing it then stops without another word about it.
 * - Models: a model that gives no first word within its first-token timeout, errors, is refused or drops goes to the
 *   fallback; Sonnet and Opus are held until complete (a refusal is never shown); a model answering exactly SKIP shows
 *   nothing; in Balanced a hard question also goes to the upgrade model once the draft is done.
 */
class SuggestionEngine
{
    /** Suggestions kept for the rewrites (the relay's history). */
    private const HISTORY = 20;

    private const NO_TOKENS = ['input' => 0, 'cached' => 0, 'written' => 0, 'output' => 0];

    public function __construct(
        private readonly LiveContext $context,
        private readonly LiveState $state,
        private readonly Card $card,
        private readonly ModeChoice $modes,
        private readonly ModelGateway $models,
        private readonly AssistBudget $budget,
        private readonly AssistPlans $plans,
        private readonly PromptBuilder $builder,
        private readonly CallTranscript $transcripts,
    ) {}

    /**
     * The end of the other side's turn: a suggestion, unless they only said "okay" or Me paused suggestions.
     *
     * @param  int  $finals  how many finals the other side had sent when this one came (a held question ends past it)
     */
    public function answer(AssistSession $session, string $turnId, string $text, int $finals, EventStream $stream): void
    {
        if (($this->context->get($session)['settings']['assist'] ?? 'on') === 'paused' || Text::isBackchannel($text)) {
            return;
        }
        $this->start($session, Origin::answer($turnId, $text), $stream, microtime(true), $finals);
    }

    /** Me asked for help now: for the latest words of the other side (or the turn [$turnId]). */
    public function helpNow(AssistSession $session, ?string $turnId, EventStream $stream): void
    {
        $transcript = $this->transcripts->of($session);
        $turn = $turnId !== null ? $transcript->find($turnId) : $transcript->lastFrom('other');
        $this->start($session, Origin::helpNow($turn['id'] ?? null, $turn['text'] ?? ''), $stream, microtime(true));
    }

    /** A hint for a practice question (Them's turn [$turnId]): as for any question the other side asked. */
    public function hint(AssistSession $session, string $turnId, string $text, EventStream $stream): void
    {
        $this->start($session, Origin::answer($turnId, $text), $stream, microtime(true), $this->state->otherFinals($session));
    }

    /**
     * Me asked for another take on a finished suggestion ([$suggestionId], or the latest): a new suggestion that replaces
     * it on the card, never SKIP and never sent to the upgrade model. Of the last 20 finished suggestions, as the relay keeps.
     */
    public function rewrite(AssistSession $session, string $kind, ?string $suggestionId, ?string $language, EventStream $stream): void
    {
        $history = AssistSuggestion::query()->where('assist_session_id', $session->id)->orderByDesc('id')->limit(self::HISTORY)->get();
        $target = $suggestionId === null ? $history->first() : $history->firstWhere('suggestion_id', $suggestionId);
        if (! $target instanceof AssistSuggestion) {
            $stream->error('nothing_to_rewrite', 'There is no finished suggestion to change yet.');

            return;
        }
        if ($kind === 'translate' && ($language === null || $language === 'off')) {
            return;
        }
        $turn = $target->turn_id !== null ? $this->transcripts->of($session)->find($target->turn_id) : null;
        $this->start($session, Origin::rewrite($kind, $target->turn_id, $turn['text'] ?? '', (string) $target->text, $language), $stream, microtime(true), 0, $target->suggestion_id);
    }

    /** Ends the suggestion being written ([$suggestionId], or whichever is on the card) as cancelled. */
    public function cancel(AssistSession $session, ?string $suggestionId, EventStream $stream): void
    {
        $ended = $this->card->end($session, $suggestionId, 'cancelled');
        if ($ended !== null) {
            $this->endByOther($session, $ended, $stream);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Starting
    // ---------------------------------------------------------------------------------------------------------------

    private function start(AssistSession $session, Origin $origin, EventStream $stream, float $t0, int $finals = 0, ?string $replaces = null): void
    {
        $mode = $this->modes->evaluate($session, $stream, 'request');
        if ($mode === 'captions_only') {
            if ($origin->explicit()) {
                $stream->error('budget_exhausted', $this->offWords($session));
            }

            return;
        }
        $plans = $this->candidates($session, $origin, $this->context->get($session), $this->transcripts->of($session), $mode);
        $run = $this->reserve($session, $origin, $plans, $t0, $replaces);
        if ($run === null) {
            if ($origin->explicit()) {
                // The person asked: they hear why and may ask again.
                $stream->error('budget_exhausted', 'Not enough budget is free for a suggestion right now. Try again in a moment.', true);

                return;
            }
            // The end of a turn: held quietly and started when calls in flight release their reservations.
            $run = $plans === [] ? null : $this->hold($session, $origin, $plans, $stream, $t0, $finals, $replaces);
            if ($run === null) {
                return;
            }
        }
        $claim = $this->card->claim($session, $this->record($run));
        if ($claim['ended'] !== null) {
            // One answer on the card at a time: the one still being written ends before this one starts.
            $this->endByOther($session, $claim['ended'], $stream);
        }
        $this->emitStart($stream, $run);
        $this->execute($session, $run, $stream);
        $this->modes->evaluate($session, $stream, 'spend');
    }

    /**
     * What the gate may start, the mode first and then each cheaper mode the plan allows.
     *
     * @param  array<string, mixed>  $call
     * @return list<array{mode: string, primary: string, fallback: string|null, upgrade: string|null, max: int, prompt: Prompt, cents: int, upgrade_cents: int}>
     */
    private function candidates(AssistSession $session, Origin $origin, array $call, Transcript $transcript, string $mode): array
    {
        $order = array_values((array) config('assist.modes_order', ['max', 'deep', 'balanced', 'fast']));
        $allowed = $this->plans->modes($session->workspace_id, $session->practice);
        $rank = (int) array_search($mode, $order, true);
        $chain = [$mode];
        foreach ($order as $i => $other) {
            if ($i > $rank && in_array($other, $allowed, true)) {
                $chain[] = $other;
            }
        }
        $candidates = [];
        foreach ($chain as $candidate) {
            $config = (array) config("assist.modes.{$candidate}", []);
            $primary = (string) ($config['primary'] ?? '');
            if (! $this->enabled($primary)) {
                continue;
            }
            $fallback = isset($config['fallback']) && $this->enabled((string) $config['fallback']) ? (string) $config['fallback'] : null;
            $upgrade = isset($config['upgrade']) && $this->enabled((string) $config['upgrade']) && in_array($origin->kind, [Origin::ANSWER, Origin::HELP_NOW], true) && $this->hard($origin->question) ? (string) $config['upgrade'] : null;
            $prompt = $this->builder->build($call, $transcript, $origin, (string) ($config['transcript'] ?? 'full'));
            $max = (int) ($config['max_output_tokens'] ?? 400);
            // Both models can bill (the primary failing after its request was taken), upper-bound tokens throughout.
            $worst = fn (string $model): int => Pricing::bound($model, $prompt->upperTokens(), $prompt->upperCacheableTokens(), $max);
            $candidates[] = [
                'mode' => $candidate, 'primary' => $primary, 'fallback' => $fallback, 'upgrade' => $upgrade, 'max' => $max, 'prompt' => $prompt,
                'cents' => self::cents($worst($primary) + ($fallback !== null ? $worst($fallback) : 0)),
                'upgrade_cents' => $upgrade !== null ? self::cents($worst($upgrade)) : 0,
            ];
        }

        return $candidates;
    }

    /**
     * Reserves the first candidate that fits what is free (AssistBudget), with its upgrade when that fits beside it.
     *
     * @param  list<array{mode: string, primary: string, fallback: string|null, upgrade: string|null, max: int, prompt: Prompt, cents: int, upgrade_cents: int}>  $candidates
     */
    private function reserve(AssistSession $session, Origin $origin, array $candidates, float $t0, ?string $replaces): ?Run
    {
        foreach ($candidates as $plan) {
            $id = self::id();
            $reservation = $this->budget->reserve($session, $origin->kind, $plan['primary'], $plan['cents'], $id, $plan['mode']);
            if ($reservation === null) {
                continue;
            }
            $upgrade = null;
            if ($plan['upgrade'] !== null) {
                $upgradeId = self::id();
                $upgrade = ['id' => $upgradeId, 'model' => $plan['upgrade'], 'max' => $plan['max'], 'prompt' => $plan['prompt'], 'cents' => $plan['upgrade_cents'],
                    'reservation' => $this->budget->reserve($session, 'upgrade', $plan['upgrade'], $plan['upgrade_cents'], $upgradeId, $plan['mode'])];
                // No room for the upgrade beside the draft: the draft alone, in this mode.
                $upgrade = $upgrade['reservation'] === null ? null : $upgrade;
            }

            return new Run($id, $origin, $plan['mode'], $plan['primary'], $plan['fallback'], $plan['prompt'], $plan['max'], $reservation, $t0, $replaces, $upgrade);
        }

        return null;
    }

    /**
     * A question the gate turned away waits, quiet but for keep-alives: it starts when calls in flight release their
     * reservations, and is dropped when Me speaks, the other side sends another final, `hold_max_ms` pass, or the
     * stream's time or the app is gone.
     *
     * @param  list<array{mode: string, primary: string, fallback: string|null, upgrade: string|null, max: int, prompt: Prompt, cents: int, upgrade_cents: int}>  $candidates
     */
    private function hold(AssistSession $session, Origin $origin, array $candidates, EventStream $stream, float $t0, int $finals, ?string $replaces): ?Run
    {
        $stream->keepAlive();
        $lastKeepAlive = microtime(true);
        while (true) {
            usleep(max(1, (int) config('assist.http.hold_poll_ms', 150)) * 1000);
            $now = microtime(true);
            $me = $this->context->lastMe($session);
            if ($stream->aborted() || $stream->expired() || ($now - $t0) * 1000 > (int) config('assist.http.hold_max_ms', 5000)
                || ($me !== null && $me > (int) floor($t0 * 1000)) || $this->state->otherFinals($session) > $finals) {
                return null;
            }
            if ($now - $lastKeepAlive >= 1.0) {
                $stream->keepAlive();
                $lastKeepAlive = $now;
            }
            $run = $this->reserve($session, $origin, $candidates, $t0, $replaces);
            if ($run !== null) {
                return $run;
            }
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Writing
    // ---------------------------------------------------------------------------------------------------------------

    private function execute(AssistSession $session, Run $run, EventStream $stream): void
    {
        $current = $run;
        while ($current !== null) {
            try {
                $this->attempt($session, $current, $stream);

                return;
            } catch (ModelFailure $e) {
                $current = $this->failover($session, $current, $stream, $e);
            } catch (\Throwable $e) {
                // Whatever else goes wrong while writing is a failed attempt: the fallback answers, or the suggestion
                // ends with an error, and its reservation is settled. Never the call's words in the log.
                Log::warning('Live Assist suggestion attempt failed', ['session' => $session->id, 'suggestion' => $current->id, 'error' => $e::class]);
                $current = $this->failover($session, $current, $stream, new ModelFailure(ModelFailure::UNAVAILABLE));
            }
        }
    }

    /** One attempt on the run's model: ends the suggestion itself unless the model fails (then it throws). */
    private function attempt(AssistSession $session, Run $run, EventStream $stream): void
    {
        $run->hold = (bool) config("assist.models.{$run->model}.hold_until_complete", false);
        foreach ($this->models->stream(ModelRequest::for($run->model, $run->prompt, $run->max)) as $event) {
            if ($stream->aborted()) {
                $this->gone($session, $run);

                return;
            }
            if ($stream->expired()) {
                throw new ModelFailure(ModelFailure::TIMEOUT);
            }
            if ($event->usage !== null) {
                $run->usage = $event->usage;

                continue;
            }
            $now = microtime(true);
            $run->firstTokenAt ??= $now;
            $run->lastTokenAt = $now;
            $run->text .= (string) $event->text;
            // Somebody else may have ended this suggestion: looked at most every tenth of a second (a token sent
            // after its end changes nothing on the card).
            if ($now - $run->checkedAt >= (int) config('assist.http.card_check_ms', 100) / 1000) {
                $run->checkedAt = $now;
                if (! $this->card->writing($session, $run->id)) {
                    $this->lost($session, $run);

                    return;
                }
            }
            if ($run->hold) {
                continue;
            }
            $release = $run->gate->feed((string) $event->text);
            if ($release !== null) {
                $this->sendText($session, $run, $stream, $release);
            }
        }
        if (! $this->card->writing($session, $run->id)) {
            $this->lost($session, $run);

            return;
        }
        if ($run->hold) {
            // The whole answer arrived without a refusal: now it may be shown.
            $release = $run->gate->feed($run->text);
            if ($release !== null) {
                $this->sendText($session, $run, $stream, $release);
            }
        }
        $rest = $run->gate->finish();
        if ($rest === null) {
            $this->finishSkip($session, $run, $stream);

            return;
        }
        $this->sendText($session, $run, $stream, $rest);
        $this->finishDone($session, $run, $stream);
    }

    private function finishDone(AssistSession $session, Run $run, EventStream $stream): void
    {
        if (! $this->card->finish($session, $run->id, 'done')) {
            $this->lost($session, $run);

            return;
        }
        $stream->send('suggestion_end', [
            'suggestion_id' => $run->id, 'status' => 'done', 'model' => $run->model, 'latency' => $this->latency($run), 'usage' => $this->usageReport($run),
        ]);
        $this->settle($session, $run, 'done');
        $this->remember($session, $run, $run->replaces, $run->origin->kind);
        $this->upgrade($session, $run, $stream);
    }

    private function finishSkip(AssistSession $session, Run $run, EventStream $stream): void
    {
        if (! $this->card->finish($session, $run->id, 'skipped')) {
            $this->lost($session, $run);

            return;
        }
        $stream->send('skip', ['suggestion_id' => $run->id, 'turn_id' => $run->origin->turnId, 'latency' => $this->latency($run), 'usage' => $this->usageReport($run)]);
        $this->settle($session, $run, 'skip');
    }

    /** Another request ended this suggestion (it sent the end): this one stops without a word and settles. */
    private function lost(AssistSession $session, Run $run): void
    {
        $this->settle($session, $run, $this->card->endedAs($session, $run->id) ?? 'superseded');
    }

    /** The app closed the connection: nobody reads this suggestion any more. */
    private function gone(AssistSession $session, Run $run): void
    {
        $this->card->finish($session, $run->id, 'failed');
        $this->settle($session, $run, 'cancelled');
    }

    /** A model failed: the fallback answers when there is one, otherwise the suggestion ends with an error. */
    private function failover(AssistSession $session, Run $run, EventStream $stream, ModelFailure $e): ?Run
    {
        $out = $stream->expired() || $stream->aborted();
        if ($run->fallback !== null && ! $out) {
            if (! $run->gate->isOpen()) {
                // Nothing reached the card yet: the same suggestion on the fallback model. The attempt given up is
                // charged first (its estimate when the provider sent no counts), unless the provider turned the
                // request away unprocessed.
                $this->giveUp($run, $e);
                $run->switchTo($run->fallback);

                return $run;
            }

            return $this->replaceOnFallback($session, $run, $stream, $e);
        }
        $mine = $this->card->finish($session, $run->id, 'failed');
        if ($mine) {
            $stream->error($e->code(), $e->plain(), $e->retryable(), $run->id);
        }
        $this->settle($session, $run, 'error', $e->billed());

        return null;
    }

    /**
     * A suggestion broke after its first words (a refusal, or the connection dropped): it ends `superseded` and the
     * fallback model answers the same request as a new suggestion that `replaces` it, timed from the same question. A
     * Balanced draft's upgrade carries over to it.
     */
    private function replaceOnFallback(AssistSession $session, Run $run, EventStream $stream, ModelFailure $e): ?Run
    {
        $fallback = (string) $run->fallback;
        $latency = $this->latency($run);
        $usage = $this->usageReport($run);
        $this->settle($session, $run, 'superseded', $e->billed(), keepUpgrade: true);
        $id = self::id();
        $cents = self::cents(Pricing::bound($fallback, $run->prompt->upperTokens(), $run->prompt->upperCacheableTokens(), $run->max));
        $reservation = $this->budget->reserve($session, $run->origin->kind, $fallback, $cents, $id, $run->mode);
        if ($reservation === null) {
            $this->releaseUpgrade($run);
            if ($this->card->finish($session, $run->id, 'failed')) {
                $stream->error($e->code(), $e->plain(), $e->retryable(), $run->id);
            }

            return null;
        }
        $new = new Run($id, $run->origin, $run->mode, $fallback, null, $run->prompt, $run->max, $reservation, $run->t0, $run->id, $run->upgrade);
        $new->fallbackUsed = true;
        $claim = $this->card->claim($session, $this->record($new), $run->id, 'writing');
        if (! $claim['claimed']) {
            // Somebody ended the broken one meanwhile (and said so): nothing is left to replace.
            $this->budget->release($reservation);
            $this->releaseUpgrade($run);

            return null;
        }
        $stream->send('suggestion_end', ['suggestion_id' => $run->id, 'status' => 'superseded', 'model' => $run->model, 'latency' => $latency, 'usage' => $usage]);
        $this->emitStart($stream, $new);

        return $new;
    }

    /** What an attempt given up for the fallback cost (a request the provider turned away unprocessed costs nothing). */
    private function giveUp(Run $run, ModelFailure $e): void
    {
        if (! $e->billed()) {
            return;
        }
        [$tokens] = $run->tokens();
        $before = $run->abandoned ?? ['tokens' => self::NO_TOKENS, 'micros' => 0];
        $run->abandoned = ['tokens' => self::sum($before['tokens'], $tokens), 'micros' => $before['micros'] + Pricing::cost($run->model, $tokens)];
    }

    /** Balanced: the hard question also goes to the upgrade model once its draft is done; it replaces the draft. */
    private function upgrade(AssistSession $session, Run $run, EventStream $stream): void
    {
        $upgrade = $run->upgrade;
        if ($upgrade === null || $upgrade['reservation'] === null) {
            return;
        }
        $reservation = $upgrade['reservation'];
        // Not worth asking: Me already started answering, or the draft is no longer what the card shows.
        if ($this->spoke($session, $run) || ! $this->card->done($session, $run->id) || $stream->aborted() || $stream->expired()) {
            $this->budget->release($reservation);

            return;
        }
        $shell = new Run($upgrade['id'], $run->origin, $run->mode, $upgrade['model'], null, $upgrade['prompt'], $upgrade['max'], $reservation, $run->t0, $run->id);
        $shell->hold = true;
        try {
            foreach ($this->models->stream(ModelRequest::for($shell->model, $shell->prompt, $shell->max)) as $event) {
                if ($stream->aborted() || $stream->expired()) {
                    $this->settle($session, $shell, 'cancelled');

                    return;
                }
                if ($event->usage !== null) {
                    $shell->usage = $event->usage;
                } else {
                    $now = microtime(true);
                    $shell->firstTokenAt ??= $now;
                    $shell->lastTokenAt = $now;
                    $shell->text .= (string) $event->text;
                }
            }
        } catch (ModelFailure $e) {
            // A failed upgrade says nothing: the draft stays.
            $this->settle($session, $shell, 'error', $e->billed());

            return;
        }
        $text = trim($shell->text);
        if ($text === '' || SkipGate::isSkip($text) || $this->spoke($session, $run) || ! $this->card->claim($session, $this->record($shell, 'done'), $run->id)['claimed']) {
            $this->settle($session, $shell, 'discarded');

            return;
        }
        $stream->send('suggestion_start', [
            'suggestion_id' => $shell->id, 'turn_id' => $run->origin->turnId, 'kind' => 'upgrade', 'mode' => $run->mode, 'model' => $shell->model,
            'speculative' => false, 'draft' => false, 'replaces' => $run->id,
        ]);
        $stream->send('token', ['suggestion_id' => $shell->id, 'text' => $shell->text]);
        $shell->firstSentAt = microtime(true);
        $stream->send('suggestion_end', [
            'suggestion_id' => $shell->id, 'status' => 'done', 'model' => $shell->model, 'latency' => $this->latency($shell), 'usage' => $this->usageReport($shell),
        ]);
        $this->settle($session, $shell, 'done');
        $this->remember($session, $shell, $run->id, 'upgrade');
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Messages
    // ---------------------------------------------------------------------------------------------------------------

    private function emitStart(EventStream $stream, Run $run): void
    {
        $stream->send('suggestion_start', [
            'suggestion_id' => $run->id, 'turn_id' => $run->origin->turnId, 'kind' => $run->origin->kind, 'mode' => $run->mode, 'model' => $run->model,
            'speculative' => false, 'draft' => $run->upgrade !== null, 'replaces' => $run->replaces,
        ]);
    }

    private function sendText(AssistSession $session, Run $run, EventStream $stream, string $text): void
    {
        if ($text === '') {
            return;
        }
        $stream->send('token', ['suggestion_id' => $run->id, 'text' => $text]);
        $now = microtime(true);
        $run->firstSentAt ??= $now;
        // How much was written, for the estimate in an end another request sends.
        if ($now - $run->progressAt >= 0.3) {
            $run->progressAt = $now;
            $this->card->progress($session, $run->id, strlen($run->text));
        }
    }

    /**
     * A suggestion this request did not write ended on the card: sends its end, with what it cost so far as an
     * estimate (`estimated: true`), which its writer settles for real.
     *
     * @param  array<string, mixed>  $record
     */
    private function endByOther(AssistSession $session, array $record, EventStream $stream): void
    {
        $tokens = ['input' => (int) $record['input_tokens'], 'cached' => 0, 'written' => 0, 'output' => intdiv($this->card->chars($session, (string) $record['id']) + 3, 4)];
        $stream->send('suggestion_end', [
            'suggestion_id' => $record['id'], 'status' => $record['status'], 'model' => $record['model'],
            'latency' => ['end_of_turn_to_request_ms' => 0.0, 'request_to_first_token_ms' => null, 'end_of_turn_to_first_token_ms' => null, 'relay_overhead_ms' => null,
                'total_ms' => round((microtime(true) - (float) $record['started_at']) * 1000, 1)],
            'usage' => $this->report($tokens, Pricing::cost((string) $record['model'], $tokens), true),
        ]);
    }

    /** @return array{input_tokens: int, cached_input_tokens: int, cache_write_tokens: int, output_tokens: int, cost_usd: float, estimated: bool} */
    private function usageReport(Run $run): array
    {
        [$tokens, $estimated] = $run->tokens();
        $micros = Pricing::cost($run->model, $tokens);
        if ($run->abandoned !== null) {
            // Attempts given up were charged when they were given up; the report counts them too.
            $tokens = self::sum($tokens, $run->abandoned['tokens']);
            $micros += $run->abandoned['micros'];
            $estimated = true;
        }

        return $this->report($tokens, $micros, $estimated);
    }

    /**
     * @param  array{input: int, cached: int, written: int, output: int}  $tokens
     * @return array{input_tokens: int, cached_input_tokens: int, cache_write_tokens: int, output_tokens: int, cost_usd: float, estimated: bool}
     */
    private function report(array $tokens, int $micros, bool $estimated): array
    {
        return [
            'input_tokens' => $tokens['input'], 'cached_input_tokens' => $tokens['cached'], 'cache_write_tokens' => $tokens['written'], 'output_tokens' => $tokens['output'],
            'cost_usd' => $micros / 1_000_000, 'estimated' => $estimated,
        ];
    }

    /** @return array{end_of_turn_to_request_ms: float, request_to_first_token_ms: float|null, end_of_turn_to_first_token_ms: float|null, relay_overhead_ms: float|null, total_ms: float} */
    private function latency(Run $run): array
    {
        $now = microtime(true);
        $ms = fn (float $seconds): float => round(max(0.0, $seconds) * 1000, 1);
        // The model's share of the wait for the first word on the card: to its first word, or, for an answer held
        // until complete, to its last.
        $model = ($run->hold ? $run->lastTokenAt : $run->firstTokenAt);
        $shown = $run->firstSentAt;

        return [
            'end_of_turn_to_request_ms' => $ms($run->requestedAt - $run->t0),
            'request_to_first_token_ms' => $run->firstTokenAt !== null ? $ms($run->firstTokenAt - $run->requestedAt) : null,
            'end_of_turn_to_first_token_ms' => $shown !== null ? $ms($shown - $run->t0) : null,
            'relay_overhead_ms' => $shown !== null && $model !== null ? $ms(max(0.0, ($shown - $run->t0) - ($model - $run->requestedAt))) : null,
            'total_ms' => $ms($now - $run->t0),
        ];
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Accounting and the report
    // ---------------------------------------------------------------------------------------------------------------

    /**
     * Settles the run's reservation to what it cost (the answer's counts and any attempt given up; [$billed] false when
     * the last attempt was turned away unprocessed, so only the abandoned attempts count), or gives it back when
     * nothing was billed. A suggestion that does not stay on the card gives back its upgrade's reservation too.
     */
    private function settle(AssistSession $session, Run $run, string $outcome, bool $billed = true, bool $keepUpgrade = false): void
    {
        $reservation = $run->reservation;
        $run->reservation = null;
        if ($outcome !== 'done' && ! $keepUpgrade) {
            $this->releaseUpgrade($run);
        }
        if ($reservation === null) {
            return;
        }
        $parts = [];
        if ($billed) {
            [$tokens, $estimated] = $run->tokens();
            $parts[] = [$tokens, Pricing::cost($run->model, $tokens), $estimated];
        }
        if ($run->abandoned !== null) {
            $parts[] = [$run->abandoned['tokens'], $run->abandoned['micros'], true];
        }
        if ($parts === []) {
            $this->budget->release($reservation);

            return;
        }
        $total = self::NO_TOKENS;
        $micros = 0;
        $estimated = false;
        foreach ($parts as [$tokens, $cost, $guess]) {
            $total = self::sum($total, $tokens);
            $micros += $cost;
            $estimated = $estimated || $guess;
        }
        try {
            $this->budget->settleCall($reservation, $run->model, $total, $micros / 1_000_000, $estimated, $outcome, $run->fallbackUsed, $this->latency($run));
        } catch (\Throwable $e) {
            // Left pending at its worst case: the sweep settles it. Numbers and ids only in the log.
            Log::warning('Live Assist could not settle a suggestion', ['session' => $session->id, 'suggestion' => $run->id, 'error' => $e::class]);
        }
    }

    private function releaseUpgrade(Run $run): void
    {
        if ($run->upgrade !== null && $run->upgrade['reservation'] !== null) {
            $this->budget->release($run->upgrade['reservation']);
            $run->upgrade['reservation'] = null;
        }
    }

    /** The words of a suggestion that was shown, for the call's report (`AssistSuggestion`). */
    private function remember(AssistSession $session, Run $run, ?string $replaces, string $kind): void
    {
        try {
            AssistSuggestion::query()->updateOrCreate(
                ['assist_session_id' => $session->id, 'suggestion_id' => $run->id],
                ['turn_id' => $run->origin->turnId, 'kind' => $kind, 'mode' => $run->mode, 'model' => $run->model, 'replaces' => $replaces, 'text' => $run->text, 'shown_at' => now()],
            );
            MakeAssistReportJob::stale($session);
        } catch (\Throwable $e) {
            Log::warning('Live Assist could not keep a suggestion for the report', ['session' => $session->id, 'suggestion' => $run->id, 'error' => $e::class]);
        }
    }

    // ---------------------------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------------------------

    /** @return array<string, mixed> the card's record of a suggestion being written */
    private function record(Run $run, string $status = 'writing'): array
    {
        return [
            'id' => $run->id, 'status' => $status, 'kind' => $run->origin->kind, 'turn_id' => $run->origin->turnId, 'model' => $run->model, 'mode' => $run->mode,
            'input_tokens' => $run->prompt->tokens(), 'started_at' => $run->t0,
        ];
    }

    /**
     * Whether Me has started speaking since the question came: the moment for an upgrade has passed.
     *
     * @phpstan-impure
     */
    private function spoke(AssistSession $session, Run $run): bool
    {
        $me = $this->context->lastMe($session);

        return $me !== null && $me > (int) floor($run->t0 * 1000);
    }

    /** A hard question: many words, or one of the phrases that ask for more than a line. */
    private function hard(string $question): bool
    {
        return count(Text::words(Text::normalize($question))) >= (int) config('assist.upgrade.min_words', 14)
            || Text::hasPhrase($question, array_values(array_map('strval', (array) config('assist.upgrade.phrases', []))));
    }

    private function enabled(string $model): bool
    {
        $spec = config("assist.models.{$model}");

        return is_array($spec) && ($spec['enabled'] ?? true) !== false;
    }

    /** Why suggestions are off, in the words the app may show. */
    private function offWords(AssistSession $session): string
    {
        $left = $this->plans->left($session->workspace_id, $session->user_id, $session->practice, withPending: false);

        return match (true) {
            $left['seconds_left'] <= 0 => 'Suggestions are off: this plan\'s assist time is used up. Captions continue.',
            $this->plans->modes($session->workspace_id, $session->practice) === [] => 'Suggestions are off: this plan has no live suggestions (Practice has them). Captions continue.',
            default => 'Suggestions are off: this session\'s budget is used up. Captions continue.',
        };
    }

    /**
     * @param  array{input: int, cached: int, written: int, output: int}  $a
     * @param  array{input: int, cached: int, written: int, output: int}  $b
     * @return array{input: int, cached: int, written: int, output: int}
     */
    private static function sum(array $a, array $b): array
    {
        return ['input' => $a['input'] + $b['input'], 'cached' => $a['cached'] + $b['cached'], 'written' => $a['written'] + $b['written'], 'output' => $a['output'] + $b['output']];
    }

    /** Micro-dollars as whole US cents, rounded up (a reservation is in cents). */
    private static function cents(int $micros): int
    {
        return (int) ceil($micros / 10_000);
    }

    private static function id(): string
    {
        return Str::lower((string) Str::ulid());
    }
}
