<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Http\Controllers\Api\Assist\InternalAssistController;
use App\Models\AssistPrep;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistTurn;
use App\Models\Client;
use App\Models\ClientMemoryItem;
use App\Services\SettingsRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The post-call report of a Live Assist session (v1.9 L7, docs/PLAN_V1_9.md L7), made by MakeAssistReportJob:
 *
 * - the Q&A table: each question (an `other` turn the app marked as one, or one a hint answered) with what the person
 *   said after it (the `me` turns until the next question) and the hint shown for it (the suggestion whose `turn_id`
 *   is the question's: the relay's turn id, which the apps send as the turn's client_ref (1.9.0-j); in Practice the
 *   question's `q-N`, N its place among the questions), then a verdict and a better answer;
 * - coaching from the turns alone: words per minute, filler words, talk ratio and long pauses before an answer; and
 *   three grammar fixes;
 * - from the model (config `assist.report.model`, one call billed to the session's allowance, Practice's for
 *   Practice): the summary (and in the prep's language when it asked for language help), mistakes against the facts
 *   ledger, promises, action items (added to the prep's project memory after a live call) and a follow-up email.
 *
 * Without the model (not set up, the allowance spent, or it failed) the report still has the table, the coaching
 * numbers, the relay's running summary and the facts ledger. A report made again (a late post from the relay) asks the
 * model at most `assist.report.max_runs` times in all, then keeps the last answer.
 */
final class AssistReport
{
    public const VERSION = 1;

    public function __construct(private readonly AssistReportAi $ai, private readonly AssistPlans $plans, private readonly SettingsRepository $settings) {}

    /**
     * @param  array<string, mixed>|null  $previous  the report made before, if any
     * @return array<string, mixed>
     */
    public function make(AssistSession $session, ?array $previous): array
    {
        /** @var Collection<int, AssistTurn> $turns */
        $turns = $session->turns()->orderBy('start_ms')->orderBy('end_ms')->orderBy('id')->get()->values();
        /** @var Collection<int, AssistSuggestion> $suggestions */
        $suggestions = $session->suggestions()->orderBy('id')->get();
        $prep = $session->prep;
        $qa = $this->pairs($session, $turns, $suggestions);
        $coaching = self::coaching($turns);

        [$ai, $words] = $this->words($session, $prep instanceof AssistPrep ? $prep : null, $turns, $qa, $previous);
        foreach ($qa as $i => $item) {
            $verdict = $words['qa'][$item['index']] ?? null;
            // An unanswered question is missed whatever the model says.
            $qa[$i]['verdict'] = $item['answer'] === null ? 'missed' : ($verdict['verdict'] ?? null);
            $qa[$i]['better_answer'] = $item['answer'] === null || ($verdict['verdict'] ?? null) !== 'good'
                ? (($verdict['better_answer'] ?? '') !== '' ? $verdict['better_answer'] : null) : null;
        }
        $coaching['grammar'] = $words['grammar'];
        $memory = $ai['status'] === 'ready' ? $this->remember($session, $prep instanceof AssistPrep ? $prep : null, $words['action_items']) : (int) ($previous['memory_added'] ?? 0);

        return [
            'version' => self::VERSION,
            'mode' => $session->mode,
            'practice' => $session->practice,
            'meeting_type' => $prep instanceof AssistPrep ? $prep->meeting_type : 'general',
            'title' => $prep instanceof AssistPrep ? $prep->title : null,
            'started_at' => $session->started_at->toAtomString(),
            'ended_at' => $session->ended_at?->toAtomString(),
            'seconds' => $session->seconds(),
            'turns' => $turns->count(),
            'ai' => $ai,
            'summary' => $words['summary'] !== '' ? $words['summary'] : $session->summary,
            'summary_translation' => $words['summary_translation'],
            'qa' => $qa,
            'mistakes' => $words['mistakes'],
            'promises' => $words['promises'],
            'coaching' => $coaching,
            'action_items' => $words['action_items'],
            'memory_added' => $memory,
            'follow_up_email' => $words['follow_up_email'],
            'facts' => $session->facts ?? [],
            'running_summary' => $session->summary,
            // Since L1: every suggestion shown and what was done with it.
            'suggestions' => $suggestions->filter(fn (AssistSuggestion $s): bool => $s->text !== null)
                ->map(fn (AssistSuggestion $s): array => [
                    'id' => $s->suggestion_id, 'turn_id' => $s->turn_id, 'kind' => $s->kind, 'mode' => $s->mode,
                    'text' => $s->text, 'action' => $s->action,
                ])->values()->all(),
            'used' => $suggestions->where('action', 'used')->count(),
        ];
    }

    /**
     * The Q&A table without the model's verdicts.
     *
     * @param  Collection<int, AssistTurn>  $turns
     * @param  Collection<int, AssistSuggestion>  $suggestions
     * @return list<array<string, mixed>>
     */
    private function pairs(AssistSession $session, Collection $turns, Collection $suggestions): array
    {
        $hinted = $suggestions->pluck('turn_id')->filter()->flip();
        $questions = $turns->filter(fn (AssistTurn $t): bool => $t->channel === 'other' && ($t->is_question || $hinted->has($t->client_ref)))->values();
        $rows = [];
        foreach ($questions as $i => $question) {
            $next = $questions->get($i + 1);
            $answers = $turns->filter(fn (AssistTurn $t): bool => $t->channel === 'me' && $t->start_ms >= $question->start_ms
                && ($next === null || $t->start_ms < $next->start_ms));
            // The relay's turn id: in Practice the question's q-N (the app's client_ref is the question_id).
            $keys = [$question->client_ref];
            if ($session->practice) {
                $keys[] = 'q-'.($i + 1);
            }
            $answer = trim($answers->pluck('text')->implode(' '));
            $rows[] = [
                'index' => $i + 1,
                'turn_id' => $session->practice ? 'q-'.($i + 1) : $question->client_ref,
                'question_id' => $question->client_ref,
                'at_ms' => $question->start_ms,
                'question' => $question->text,
                'answer' => $answer === '' ? null : $answer,
                'answer_ms' => $answers->sum(fn (AssistTurn $t): int => max(0, $t->end_ms - $t->start_ms)),
                'hint' => self::hint($suggestions->filter(fn (AssistSuggestion $s): bool => in_array($s->turn_id, $keys, true))),
            ];
        }

        return $rows;
    }

    /**
     * The hint shown for a question: the last of its suggestions that no other one replaced, with what was done.
     *
     * @param  Collection<int, AssistSuggestion>  $mine
     * @return array{suggestion_id: string, text: string, action: string|null}|null
     */
    private static function hint(Collection $mine): ?array
    {
        $replaced = $mine->pluck('replaces')->filter()->flip();
        $shown = $mine->filter(fn (AssistSuggestion $s): bool => $s->text !== null && ! $replaced->has($s->suggestion_id))
            ->sortBy(fn (AssistSuggestion $s): string => ($s->shown_at?->format('U.u') ?? '0').'|'.str_pad((string) $s->id, 12, '0', STR_PAD_LEFT));
        $last = $shown->last();
        if (! $last instanceof AssistSuggestion) {
            return null;
        }

        return [
            'suggestion_id' => $last->suggestion_id,
            'text' => (string) $last->text,
            'action' => $mine->contains(fn (AssistSuggestion $s): bool => $s->action === 'used') ? 'used' : $last->action,
        ];
    }

    /**
     * The coaching numbers, from the turns alone (L7: words per minute, filler words, talk ratio, long pauses).
     *
     * @param  Collection<int, AssistTurn>  $turns
     * @return array<string, mixed>
     */
    public static function coaching(Collection $turns): array
    {
        $mine = $turns->where('channel', 'me');
        $talk = fn (Collection $of): int => (int) $of->sum(fn (AssistTurn $t): int => max(0, $t->end_ms - $t->start_ms));
        $meMs = $talk($mine);
        $otherMs = $talk($turns->where('channel', 'other'));
        $text = mb_strtolower($mine->pluck('text')->implode("\n"));
        $words = $text === '' ? 0 : count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: []);

        $fillers = [];
        foreach ((array) config('assist.report.filler_words', []) as $filler) {
            $count = preg_match_all('/(?<![\p{L}\p{N}])'.preg_quote(mb_strtolower((string) $filler), '/').'(?![\p{L}\p{N}])/u', $text);
            if ($count > 0) {
                $fillers[] = ['word' => (string) $filler, 'count' => $count];
            }
        }
        usort($fillers, fn (array $a, array $b): int => [$b['count'], $a['word']] <=> [$a['count'], $b['word']]);
        $fillerCount = array_sum(array_column($fillers, 'count'));

        // A long pause: the other side finished and the person took this long to start answering.
        $pauses = [];
        $threshold = (int) config('assist.report.long_pause_ms', 3000);
        $previous = null;
        foreach ($turns as $turn) {
            if ($turn->channel === 'me' && $previous instanceof AssistTurn && $previous->channel === 'other' && $turn->start_ms - $previous->end_ms >= $threshold) {
                $pauses[] = ['at_ms' => $previous->end_ms, 'ms' => $turn->start_ms - $previous->end_ms];
            }
            if (! $previous instanceof AssistTurn || $turn->end_ms >= $previous->end_ms) {
                $previous = $turn;
            }
        }

        return [
            'words' => $words,
            'speaking_ms' => $meMs,
            // Only from 10 seconds of speech: fewer is no pace.
            'words_per_minute' => $meMs >= 10000 ? (int) round($words / ($meMs / 60000)) : null,
            'filler_words' => ['count' => $fillerCount, 'per_100_words' => $words > 0 ? round($fillerCount * 100 / $words, 1) : 0.0, 'top' => array_slice($fillers, 0, 5)],
            'talk_ratio' => $meMs + $otherMs > 0 ? ['me' => (int) round($meMs * 100 / ($meMs + $otherMs)), 'them' => 100 - (int) round($meMs * 100 / ($meMs + $otherMs))] : null,
            'long_pauses' => ['count' => count($pauses), 'longest_ms' => $pauses === [] ? 0 : max(array_column($pauses, 'ms')), 'threshold_ms' => $threshold, 'at' => array_slice($pauses, 0, 10)],
            'grammar' => [],
        ];
    }

    /**
     * The model's part: asked, taken from the report before (after max_runs), or left out with the reason.
     *
     * @param  Collection<int, AssistTurn>  $turns
     * @param  list<array<string, mixed>>  $qa
     * @param  array<string, mixed>|null  $previous
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function words(AssistSession $session, ?AssistPrep $prep, Collection $turns, array $qa, ?array $previous): array
    {
        $runs = (int) ($previous['ai']['runs'] ?? 0);
        $ai = fn (string $status, ?string $reason, ?string $model = null) => ['status' => $status, 'reason' => $reason, 'model' => $model, 'runs' => $runs];
        if (! $turns->contains('channel', 'me')) {
            return [$ai('skipped', 'nothing_said'), self::empty()];
        }
        if ($runs >= (int) config('assist.report.max_runs', 3) && ($previous['ai']['status'] ?? null) === 'ready') {
            return [$ai('ready', null, $previous['ai']['model'] ?? null), self::fromReport($previous)];
        }
        if ($this->plans->left($session->workspace_id, $session->user_id, $session->practice)['cents_left'] <= 0) {
            return [$ai('skipped', 'budget'), self::empty()];
        }
        try {
            $answer = $this->ai->write($this->input($session, $prep, $turns, $qa));
        } catch (RuntimeException $e) {
            return [$ai('failed', $e->getMessage() === ClaudeAssistReportAi::NOT_SET_UP ? 'not_set_up' : 'unavailable'), self::empty()];
        }
        $runs++;
        $this->charge($session, $answer, $runs);

        return [['status' => 'ready', 'reason' => null, 'model' => $answer['model'], 'runs' => $runs], self::clean($answer['data'], $qa, $prep)];
    }

    /**
     * What the model reads.
     *
     * @param  Collection<int, AssistTurn>  $turns
     * @param  list<array<string, mixed>>  $qa
     * @return array<string, mixed>
     */
    private function input(AssistSession $session, ?AssistPrep $prep, Collection $turns, array $qa): array
    {
        $lines = $turns->map(fn (AssistTurn $t): string => sprintf('[%s] %s: %s', self::clock($t->start_ms), $t->channel === 'me' ? 'Me' : 'Them', $t->text))->all();
        $budget = (int) config('assist.report.transcript_chars', 60000);
        if (mb_strlen(implode("\n", $lines)) > $budget) {
            // The start and the end of a long call: what it was about, and how it ended.
            $head = [];
            $tail = [];
            $used = 0;
            foreach ($lines as $line) {
                if ($used + mb_strlen($line) > $budget / 2) {
                    break;
                }
                $head[] = $line;
                $used += mb_strlen($line) + 1;
            }
            $used = 0;
            foreach (array_reverse($lines) as $line) {
                if ($used + mb_strlen($line) > $budget / 2) {
                    break;
                }
                array_unshift($tail, $line);
                $used += mb_strlen($line) + 1;
            }
            $lines = [...$head, '[… the middle of the call is left out …]', ...$tail];
        }

        return [
            'meeting' => [
                'type' => $prep instanceof AssistPrep ? $prep->meeting_type : 'general',
                'title' => $prep?->title, 'other_person' => $prep?->counterpart, 'company' => $prep?->company,
                'goals' => $prep?->goals, 'to_discuss' => $prep?->to_discuss,
            ],
            'practice' => $session->practice,
            'questions' => array_map(fn (array $row): array => [
                'index' => $row['index'], 'question' => $row['question'], 'answer' => $row['answer'], 'hint' => $row['hint']['text'] ?? null,
            ], $qa),
            'facts' => $session->facts ?? [],
            'running_summary' => $session->summary,
            'transcript' => $lines,
            'translate_to' => $prep instanceof AssistPrep && $prep->language_help !== 'off' ? $prep->language_help : null,
        ];
    }

    /**
     * The report's call, in the session's usage (its allowance: Practice's for Practice) and the month's ledger.
     *
     * @param  array{model: string, tokens: array{input: int, cached: int, written: int, output: int}}  $answer
     */
    private function charge(AssistSession $session, array $answer, int $run): void
    {
        $model = (string) config('assist.report.model', 'claude-sonnet-5-5');
        $price = (array) config("assist.models.{$model}.price", []);
        $tokens = $answer['tokens'];
        $cost = ($tokens['input'] * (float) ($price['input'] ?? 0) + $tokens['cached'] * (float) ($price['cache_read'] ?? 0)
            + $tokens['written'] * (float) ($price['cache_write'] ?? 0) + $tokens['output'] * (float) ($price['output'] ?? 0)) / 1_000_000;
        try {
            InternalAssistController::record($session, [
                'suggestion_id' => "report-{$session->id}-{$run}", 'provider' => (string) config("assist.models.{$model}.provider", 'anthropic'),
                'model' => $answer['model'], 'mode' => $session->mode, 'kind' => 'report', 'trigger' => 'report',
                'tokens' => $tokens, 'cost_usd' => $cost,
            ], now());
        } catch (UniqueConstraintViolationException) {
            // Counted already (the same run made twice at once).
        }
    }

    /**
     * The model's answer, trimmed to the sizes the report shows.
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $qa
     * @return array<string, mixed>
     */
    private static function clean(array $data, array $qa, ?AssistPrep $prep): array
    {
        $text = fn (mixed $v, int $max): string => is_string($v) ? mb_substr(trim($v), 0, $max) : '';
        $list = fn (mixed $v, int $count, int $max): array => array_values(array_filter(array_map(fn (mixed $s): string => $text($s, $max), array_slice(is_array($v) ? $v : [], 0, $count)), fn (string $s): bool => $s !== ''));
        $indexes = array_column($qa, 'index');
        $verdicts = [];
        foreach (is_array($data['qa'] ?? null) ? $data['qa'] : [] as $item) {
            $index = is_array($item) ? ($item['index'] ?? null) : null;
            if (is_int($index) && in_array($index, $indexes, true) && in_array($item['verdict'] ?? null, ['good', 'weak', 'missed'], true)) {
                $verdicts[$index] = ['verdict' => $item['verdict'], 'better_answer' => $text($item['better_answer'] ?? '', 1200)];
            }
        }
        $pairs = function (mixed $v, array $keys, int $count): array {
            $out = [];
            foreach (array_slice(is_array($v) ? $v : [], 0, $count) as $item) {
                $row = [];
                foreach ($keys as $key) {
                    $row[$key] = is_array($item) && is_string($item[$key] ?? null) ? mb_substr(trim($item[$key]), 0, 600) : '';
                }
                if ($row[$keys[0]] !== '') {
                    $out[] = $row;
                }
            }

            return $out;
        };
        $translation = $text($data['summary_translation'] ?? '', 4000);
        $email = is_array($data['follow_up_email'] ?? null) ? $data['follow_up_email'] : [];

        return [
            'summary' => $text($data['summary'] ?? '', 4000),
            'summary_translation' => $translation !== '' && $prep instanceof AssistPrep && $prep->language_help !== 'off'
                ? ['language' => $prep->language_help, 'text' => $translation] : null,
            'qa' => $verdicts,
            'mistakes' => $pairs($data['mistakes'] ?? [], ['said', 'fact'], 10),
            'promises' => $list($data['promises'] ?? [], 10, 300),
            'grammar' => $pairs($data['grammar'] ?? [], ['said', 'better', 'why'], 3),
            'action_items' => $list($data['action_items'] ?? [], 8, 300),
            'follow_up_email' => $text($email['body'] ?? '', 4000) !== '' ? ['subject' => $text($email['subject'] ?? '', 200), 'body' => $text($email['body'], 4000)] : null,
        ];
    }

    /** @return array<string, mixed> the model's part when there is none */
    private static function empty(): array
    {
        return ['summary' => '', 'summary_translation' => null, 'qa' => [], 'mistakes' => [], 'promises' => [], 'grammar' => [], 'action_items' => [], 'follow_up_email' => null];
    }

    /**
     * The model's part as the report before has it.
     *
     * @param  array<string, mixed>  $report
     * @return array<string, mixed>
     */
    private static function fromReport(array $report): array
    {
        $qa = [];
        foreach ((array) ($report['qa'] ?? []) as $row) {
            if (is_array($row) && isset($row['index'], $row['verdict'])) {
                $qa[(int) $row['index']] = ['verdict' => $row['verdict'], 'better_answer' => (string) ($row['better_answer'] ?? '')];
            }
        }

        return [
            'summary' => (string) ($report['summary'] ?? ''), 'summary_translation' => $report['summary_translation'] ?? null, 'qa' => $qa,
            'mistakes' => (array) ($report['mistakes'] ?? []), 'promises' => (array) ($report['promises'] ?? []),
            'grammar' => (array) ($report['coaching']['grammar'] ?? []), 'action_items' => (array) ($report['action_items'] ?? []),
            'follow_up_email' => $report['follow_up_email'] ?? null,
        ];
    }

    /**
     * The action items of a live call into its prep's project memory (L7), replacing the ones this session added
     * before; pinned lines stay. Not for Practice, nor for a project whose AI notes are off. Returns how many.
     *
     * @param  list<string>  $items
     */
    private function remember(AssistSession $session, ?AssistPrep $prep, array $items): int
    {
        if ($session->practice || ! $prep instanceof AssistPrep || $prep->client_id === null) {
            return 0;
        }
        // Only the person's own project in the session's workspace (projects are private, v1.5).
        $project = Client::query()->withoutGlobalScopes()->where('workspace_id', $session->workspace_id)->where('user_id', $session->user_id)->find($prep->client_id);
        if (! $project instanceof Client || ! $project->ai_notes) {
            return 0;
        }
        $day = $session->started_at->copy()->setTimezone($this->settings->displayTimezone())->toDateString();

        return DB::transaction(function () use ($session, $project, $items, $day): int {
            ClientMemoryItem::query()->withoutGlobalScopes()->where('assist_session_id', $session->id)->where('pinned', false)->delete();
            foreach ($items as $item) {
                ClientMemoryItem::query()->withoutGlobalScopes()->create([
                    'workspace_id' => $session->workspace_id,
                    'client_id' => $project->id,
                    'kind' => 'decision',
                    'text' => mb_substr('My action: '.$item, 0, 500),
                    'happened_on' => $day,
                    // The recording made with it, when there is one, is the call the line cites.
                    'source_type' => $session->capture_session_id !== null ? 'call' : 'user',
                    'source_id' => $session->capture_session_id,
                    'assist_session_id' => $session->id,
                ]);
            }

            return count($items);
        });
    }

    private static function clock(int $ms): string
    {
        $s = intdiv($ms, 1000);

        return sprintf('%02d:%02d', intdiv($s, 60), $s % 60);
    }
}
