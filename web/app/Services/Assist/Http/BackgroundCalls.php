<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use App\Models\AssistUsage;
use App\Services\Assist\AssistBudget;
use App\Services\Assist\Http\Ai\ModelFailure;
use App\Services\Assist\Http\Ai\ModelGateway;
use App\Services\Assist\Http\Ai\ModelRequest;
use App\Services\Assist\Http\Prompt\Prompt;
use Illuminate\Support\Facades\Log;

/**
 * One of the server's own model calls beside the suggestions (a practice question, new facts, the running summary):
 * on the Fast mode's models, the fallback when the first fails, never shown as a suggestion. Its worst case on both
 * models is reserved first (AssistBudget) and the reservation settles to what the call cost, an attempt given up
 * included (services/assist-relay/src/background.rs, session.rs `spawn_task`). Nothing of the call's words is logged.
 */
class BackgroundCalls
{
    public function __construct(private readonly ModelGateway $models, private readonly AssistBudget $budget) {}

    /**
     * @param  string  $kind  the usage kind: practice_question, facts or summary
     * @param  string  $id  the usage row's id (unique: `pq-…`, `facts-…`, `summary-…`)
     * @param  int  $keepCents  what must stay free beside it (the suggestions' reserve)
     * @return array{text: string, model: string}|null null when the budget cannot take the call now
     *
     * @throws ModelFailure when every model failed (what was billed on the way is settled)
     */
    public function run(AssistSession $session, string $kind, string $id, Prompt $prompt, int $max, ?EventStream $stream = null, int $keepCents = 0): ?array
    {
        $fast = (array) config('assist.modes.fast', []);
        $models = array_values(array_filter([$fast['primary'] ?? null, $fast['fallback'] ?? null], fn (mixed $m): bool => is_string($m) && self::enabled($m)));
        if ($models === []) {
            throw new ModelFailure(ModelFailure::NOT_CONFIGURED);
        }
        $micros = 0;
        foreach ($models as $model) {
            $micros += Pricing::bound($model, $prompt->upperTokens(), $prompt->upperCacheableTokens(), $max);
        }
        $reservation = $this->budget->reserve($session, $kind, $models[0], (int) ceil($micros / 10_000), $id, 'fast', $keepCents);
        if ($reservation === null) {
            return null;
        }
        $given = ['tokens' => ['input' => 0, 'cached' => 0, 'written' => 0, 'output' => 0], 'micros' => 0];
        $last = new ModelFailure(ModelFailure::UNAVAILABLE);
        foreach ($models as $i => $model) {
            if ($stream !== null && ($stream->expired() || $stream->aborted())) {
                break;
            }
            $text = '';
            $usage = null;
            try {
                foreach ($this->models->stream(ModelRequest::for($model, $prompt, $max)) as $event) {
                    if ($stream !== null && $stream->expired()) {
                        throw new ModelFailure(ModelFailure::TIMEOUT);
                    }
                    if ($event->usage !== null) {
                        $usage = $event->usage;
                    } else {
                        $text .= (string) $event->text;
                    }
                }
            } catch (ModelFailure $failure) {
                $last = $failure;
                if ($failure->billed()) {
                    // The attempt given up: its estimate, the prompt as input and the words so far as output.
                    $tokens = ['input' => $prompt->tokens(), 'cached' => 0, 'written' => 0, 'output' => Pricing::estimateTokens($text)];
                    $given = ['tokens' => self::sum($given['tokens'], $tokens), 'micros' => $given['micros'] + Pricing::cost($model, $tokens)];
                }

                continue;
            } catch (\Throwable $e) {
                Log::warning('Live Assist background call failed', ['kind' => $kind, 'error' => $e::class]);
                $last = new ModelFailure(ModelFailure::UNAVAILABLE);
                $tokens = ['input' => $prompt->tokens(), 'cached' => 0, 'written' => 0, 'output' => Pricing::estimateTokens($text)];
                $given = ['tokens' => self::sum($given['tokens'], $tokens), 'micros' => $given['micros'] + Pricing::cost($model, $tokens)];

                continue;
            }
            $estimated = $usage === null;
            $tokens = $usage ?? ['input' => $prompt->tokens(), 'cached' => 0, 'written' => 0, 'output' => Pricing::estimateTokens($text)];
            $this->settle($reservation, $model, self::sum($given['tokens'], $tokens), Pricing::cost($model, $tokens) + $given['micros'], $estimated || $given['micros'] > 0, 'done', $i > 0);

            return ['text' => $text, 'model' => $model];
        }
        if ($given['micros'] > 0) {
            $this->settle($reservation, $models[0], $given['tokens'], $given['micros'], true, 'error', false);
        } else {
            $this->budget->release($reservation);
        }

        throw $last;
    }

    private static function enabled(string $model): bool
    {
        $spec = config("assist.models.{$model}");

        return is_array($spec) && ($spec['enabled'] ?? true) !== false;
    }

    /** @param  array{input: int, cached: int, written: int, output: int}  $tokens */
    private function settle(AssistUsage $reservation, string $model, array $tokens, int $micros, bool $estimated, string $outcome, bool $fallback): void
    {
        try {
            $this->budget->settleCall($reservation, $model, $tokens, $micros / 1_000_000, $estimated, $outcome, $fallback);
        } catch (\Throwable $e) {
            // Left pending at its worst case: the sweep settles it.
            Log::warning('Live Assist could not settle a background call', ['error' => $e::class]);
        }
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
}
