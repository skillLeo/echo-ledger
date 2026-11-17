<?php

declare(strict_types=1);

namespace App\Services\Assist\Http;

use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Services\Assist\Http\Ai\ModelFailure;
use App\Services\Assist\Http\Prompt\Transcript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Practice over HTTP mode (docs/API_ASSIST.md "Practice, facts and summary"): `practice_next` makes the AI play Them,
 * one question per message (services/assist-relay/src/session.rs `on_practice_next`). It answers `practice_question`
 * and then, in the same stream, the hint for it (unless suggestions are paused), or `practice_done` after the last
 * question. The question is Them's turn `q-N` and is stored with the call's turns, so the report pairs it with what
 * Me said after it and the hint shown.
 */
class PracticeEngine
{
    /** Tries at one question before practice stops. */
    public const ATTEMPTS = 3;

    public function __construct(
        private readonly LiveContext $context,
        private readonly ModeChoice $modes,
        private readonly BackgroundCalls $calls,
        private readonly SuggestionEngine $suggestions,
        private readonly CallTranscript $transcripts,
    ) {}

    public function next(AssistSession $session, EventStream $stream): void
    {
        if (! $session->practice) {
            $stream->error('not_practice', 'This is not a practice session.');

            return;
        }
        // One question at a time: a second `practice_next` while one is being written is told to wait. The lock is a
        // single-flight flag, not a queue: it is never waited for (get(), not block()), and it expires after 60 s if
        // the request that holds it dies.
        $lock = Cache::lock('assist.live.practice.lock.'.$session->id, 60);
        if (! $lock->get()) {
            $stream->error('practice_busy', 'The next question is on its way.', true);

            return;
        }
        try {
            $this->ask($session, $stream);
        } finally {
            $lock->release();
        }
    }

    private function ask(AssistSession $session, EventStream $stream): void
    {
        $total = (int) config('assist.practice.questions', 8);
        /** @var list<string> $asked */
        $asked = AssistTurn::query()->where('assist_session_id', $session->id)->where('channel', 'other')->where('is_question', true)->orderBy('id')->get()
            ->map(fn (AssistTurn $t): string => $t->text)->all();
        $state = $this->state($session);
        if (count($asked) >= $total || $state['stopped']) {
            $stream->send('practice_done', ['asked' => count($asked)]);

            return;
        }
        if ($this->modes->evaluate($session, $stream, 'request') === 'captions_only') {
            $stream->error('budget_exhausted', 'Practice is off: this session\'s allowance is used up.');

            return;
        }
        $index = count($asked) + 1;
        $lines = array_map(Transcript::line(...), $this->transcripts->of($session)->latest());
        $questionId = 'pq-'.Str::lower((string) Str::ulid());
        try {
            $result = $this->calls->run($session, 'practice_question', $questionId, Background::practice($this->context->get($session), $asked, $lines, $index, $total), (int) config('assist.practice.max_output_tokens', 120), $stream);
        } catch (ModelFailure $e) {
            $this->failed($session, $stream, $e->code(), $e->plain(), $e->retryable());

            return;
        }
        if ($result === null) {
            $stream->error('budget_exhausted', 'Practice is off: this session\'s allowance is used up.');

            return;
        }
        $question = Background::cleanQuestion($result['text']);
        if ($question === null) {
            $this->failed($session, $stream, 'provider_unavailable', 'The model is not available right now. Try again in a moment.', true);

            return;
        }
        $this->state($session, 0);
        $ms = max(0, (int) round($session->started_at->diffInMilliseconds(now(), true)));
        AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => $questionId, 'channel' => 'other', 'start_ms' => $ms, 'end_ms' => $ms, 'text' => $question, 'language' => 'en', 'is_question' => true]);
        $turnId = 'q-'.$index;
        $stream->send('practice_question', ['question_id' => $questionId, 'turn_id' => $turnId, 'index' => $index, 'total' => $total, 'text' => $question]);
        // A hint for the question, as for any question, unless Me paused suggestions.
        if (($this->context->get($session)['settings']['assist'] ?? 'on') !== 'paused') {
            $this->suggestions->hint($session, $turnId, $question, $stream);
        }
    }

    /**
     * A question could not be written. Up to [self::ATTEMPTS] tries per question the app may ask again; then practice
     * stops: this error says so (`retry: false`) and the next `practice_next` gets `practice_done`.
     */
    private function failed(AssistSession $session, EventStream $stream, string $code, string $message, bool $retry): void
    {
        $failures = $this->state($session)['failures'] + 1;
        $stopped = $failures >= self::ATTEMPTS;
        $this->state($session, $failures, $stopped);
        $stream->error($code, $message, $stopped ? false : $retry);
    }

    /**
     * Practice's failures at the current question, and whether it has stopped; with [$failures] given, remembers them.
     *
     * @return array{failures: int, stopped: bool}
     */
    private function state(AssistSession $session, ?int $failures = null, bool $stopped = false): array
    {
        $key = 'assist.live.practice.'.$session->id;
        if ($failures !== null) {
            Cache::put($key, ['failures' => $failures, 'stopped' => $stopped], LiveState::TTL_SECONDS);

            return ['failures' => $failures, 'stopped' => $stopped];
        }
        $stored = Cache::get($key);

        return is_array($stored) ? ['failures' => (int) ($stored['failures'] ?? 0), 'stopped' => (bool) ($stored['stopped'] ?? false)] : ['failures' => 0, 'stopped' => false];
    }
}
