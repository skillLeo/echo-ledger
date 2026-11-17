<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Exceptions\ApiError;
use App\Http\Controllers\Api\Assist\InternalAssistController;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * "Ask over the session" (v1.9 L7, docs/PLAN_V1_9.md): a question about one Live Assist session or Practice, answered
 * from its own lines, its summary and its facts, with the moments the answer rests on (from the session's own lines,
 * never the model's words). One call, asked only when its worst case fits what is left of the session's allowance
 * (Practice's for Practice) and billed to it as usage kind "ask". Nothing is kept: the answer is shown, not stored.
 */
final class AssistAsk
{
    public function __construct(private readonly AssistAskAi $ai, private readonly AssistPlans $plans) {}

    /**
     * @return array{answer: string, citations: list<array{at_ms: int, channel: string, text: string}>}
     *
     * @throws ApiError 422 nothing to ask about, 402 budget_exhausted, 503 provider_not_configured, 502 provider_error
     */
    public function ask(AssistSession $session, string $question): array
    {
        $question = trim($question);
        /** @var list<AssistTurn> $turns */
        $turns = $session->turns()->orderBy('start_ms')->orderBy('end_ms')->orderBy('id')->get()->values()->all();
        if ($turns === []) {
            throw new ApiError(422, 'validation_failed', 'Nothing was said in this session yet.', ['errors' => ['question' => ['Nothing was said in this session yet.']]]);
        }
        $input = [
            'question' => $question,
            'practice' => $session->practice,
            'lines' => self::lines($turns),
            'summary' => $session->report['summary'] ?? $session->summary,
            'facts' => $session->facts ?? [],
        ];
        $model = (string) config('assist.ask.model', 'claude-haiku-5-5');
        $prompt = $this->ai->promptText($input);
        if (AssistReport::worstCaseCentsOf($model, (int) config('assist.ask.max_output_tokens', 800), $prompt)
            > $this->plans->left($session->workspace_id, $session->user_id, $session->practice)['cents_left']) {
            throw new ApiError(402, 'budget_exhausted', $session->practice
                ? 'This month\'s Practice allowance is used up. It starts again next month.'
                : 'This month\'s Live Assist allowance is used up.', ['fallback' => 'none']);
        }
        try {
            $answer = $this->ai->ask($input);
        } catch (AssistReportFailed $e) {
            if ($e->usage !== null) {
                $this->charge($session, $e->usage['model'], $e->usage['tokens'], false);
            } elseif ($e->unknownCost) {
                $this->charge($session, $model, ['input' => 0, 'cached' => 0, 'written' => AssistReport::worstCaseTokens($prompt),
                    'output' => (int) config('assist.ask.max_output_tokens', 800)], true);
            }
            if ($e->getMessage() === ClaudeAssistReportAi::NOT_SET_UP) {
                throw new ApiError(503, 'provider_not_configured', 'The AI is not set up on the server yet.');
            }

            throw new ApiError(502, 'provider_error', 'The AI could not answer right now. Try again in a minute.');
        }
        $this->charge($session, $answer['model'], $answer['tokens'], false);

        $text = is_string($answer['data']['answer'] ?? null) ? trim(mb_substr($answer['data']['answer'], 0, 2000)) : '';
        $citations = [];
        foreach (array_slice(is_array($answer['data']['lines'] ?? null) ? $answer['data']['lines'] : [], 0, 5) as $index) {
            $turn = is_int($index) ? ($turns[$index - 1] ?? null) : null;
            if ($turn instanceof AssistTurn && ! isset($citations[$index])) {
                $citations[$index] = ['at_ms' => $turn->start_ms, 'channel' => $turn->channel, 'text' => mb_substr($turn->text, 0, 300)];
            }
        }
        ksort($citations);

        return ['answer' => $text !== '' ? $text : 'The AI could not answer that from this session.', 'citations' => array_values($citations)];
    }

    /**
     * The lines the model reads, numbered from 1 (the citations' numbers): the start and the end of a long session.
     *
     * @param  list<AssistTurn>  $turns
     * @return list<array{n: int, at: string, who: string, text: string}>
     */
    private static function lines(array $turns): array
    {
        $all = [];
        foreach ($turns as $i => $turn) {
            $s = intdiv($turn->start_ms, 1000);
            $all[] = ['n' => $i + 1, 'at' => sprintf('%02d:%02d', intdiv($s, 60), $s % 60), 'who' => $turn->channel === 'me' ? 'Me' : 'Them', 'text' => $turn->text];
        }
        $budget = (int) config('assist.ask.transcript_chars', 40000);
        $size = fn (array $line): int => mb_strlen($line['text']) + 20;
        if (array_sum(array_map($size, $all)) <= $budget) {
            return $all;
        }
        [$head, $tail, $used] = [[], [], 0];
        foreach ($all as $line) {
            if (($used += $size($line)) > $budget / 2) {
                break;
            }
            $head[] = $line;
        }
        $used = 0;
        foreach (array_reverse($all) as $line) {
            if (($used += $size($line)) > $budget / 2) {
                break;
            }
            array_unshift($tail, $line);
        }

        return [...$head, ...$tail];
    }

    /** @param  array{input: int, cached: int, written: int, output: int}  $tokens */
    private function charge(AssistSession $session, string $model, array $tokens, bool $estimated): void
    {
        $catalog = (string) config('assist.ask.model', 'claude-haiku-5-5');
        try {
            InternalAssistController::record($session, [
                'suggestion_id' => 'ask-'.$session->id.'-'.Str::ulid(), 'provider' => (string) config("assist.models.{$catalog}.provider", 'anthropic'),
                'model' => $model, 'mode' => $session->mode, 'kind' => 'ask', 'trigger' => 'ask',
                'tokens' => $tokens, 'cost_usd' => AssistReport::costUsd($catalog, $tokens), 'estimated' => $estimated,
            ], now());
        } catch (UniqueConstraintViolationException) {
            // Cannot happen with a new id; kept as the report's charge is.
        }
    }
}
