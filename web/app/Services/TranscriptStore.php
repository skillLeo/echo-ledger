<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\DoubleCheckJob;
use App\Jobs\EngineFallbackJob;
use App\Jobs\ScoreAccuracyJob;
use App\Jobs\ShortNoteCheckJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\Usage\UsageLedger;
use App\Services\Views\TranscriptViews;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use App\Stt\ScriptCheck;
use App\Stt\Support\PartCache;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;

/** Persists normalized transcripts and keeps the parent session status in step. */
final class TranscriptStore
{
    public function __construct(private readonly UsageLedger $ledger, private readonly TranscriptEditor $editor, private readonly SettingsRepository $settings) {}

    public function complete(Transcript $transcript, NormalizedTranscript $result): Transcript
    {
        // The engine answered for the whole recording: the parts kept for a run that had to wait are not needed.
        app(PartCache::class)->forget($transcript->id);
        // The right language every time: a result in a language the recording may not use, or in Hindi letters, is
        // run once more with the language forced (the billed first run stays in the usage ledger).
        if (($forced = $this->forcedLanguage($transcript, $result)) !== null) {
            return $this->rerunForced($transcript, $result, $forced[0], $forced[1]);
        }
        // Since v1.5 (Phase 1C): Auto heard a language whose own engine is another one (Arabic → ElevenLabs Scribe):
        // run it there; this first run stays as the second opinion of the short-note check.
        if (($engine = $this->rerouteEngine($transcript, $result)) !== null) {
            return $this->rerouteDetected($transcript, $result, $engine);
        }
        $detected = LanguageChoice::fromDetected($result->language);
        $segments = $result->segmentsArray();
        $text = $result->text;
        $flagged = 0;
        if ($transcript->kind !== TranscriptKind::Live && ScriptCheck::wrongScript($transcript->language ?? $detected, $text) === 'devanagari') {
            // Still Hindi letters after the forced run: flag those lines instead of showing Hindi.
            [$segments, $flagged] = self::flagDevanagari($segments);
            $text = TranscriptEditor::plainText($segments);
        }

        DB::transaction(function () use ($transcript, $result, $detected, $segments, $text, $flagged): void {
            $meta = array_merge($transcript->meta ?? [], $result->meta, array_filter(['detected_language' => $result->language]));
            if ($flagged > 0) {
                $meta['wrong_script_segments'] = $flagged;
            }
            // Since v1.5: no longer waiting for an engine (EngineWait); whether a backup engine wrote it stays.
            if (is_array($meta['route'] ?? null)) {
                $meta['route'] = EngineWait::cleared($meta['route']);
            }
            $transcript->update([
                'status' => TranscriptStatus::Completed,
                'text' => $text,
                'segments' => $segments,
                // The language the user chose (or the one forced) stays; what the engine detected is kept beside it, read
                // as one of ours (Hindi is Urdu).
                'language' => $transcript->language ?? $detected,
                'meta' => self::withScriptCheck($meta, $transcript, $text, $transcript->language ?? $detected),
                'error' => null,
                'error_code' => null,
                'completed_at' => now(),
            ]);
            if ($transcript->kind === TranscriptKind::Final) {
                $transcript->captureSession->update(['status' => SessionStatus::Completed]);
            }
            if ($transcript->kind !== TranscriptKind::Live) {
                // The provider bills a finished job: one ledger row per job (failed jobs are free and write nothing).
                $this->ledger->recordFinal($transcript, $result);
            }
        });

        $this->applyVocabulary($transcript->refresh());
        // "Re-transcribe as …": the new version becomes the transcript as soon as it is ready (the old one is kept).
        if ($transcript->kind === TranscriptKind::Alternate && ($transcript->meta['promote_when_done'] ?? false) === true) {
            app(Retranscriber::class)->choose($transcript);
        }
        // Accuracy Lab: a comparison run of a recording with a reference is scored against it, on the queue.
        if ($transcript->kind === TranscriptKind::Trial && $transcript->captureSession->hasReference()) {
            ScoreAccuracyJob::dispatch($transcript->capture_session_id);
        }
        // Since v1.5 (Phase 1C): a short note is heard by a second engine first, then gets its views (the job makes
        // them), so a disputed word reaches them marked unsure; a minute or less runs on the fast path.
        $shortCheck = app(ShortNoteCheck::class)->wanted($transcript->refresh());
        if ($shortCheck) {
            FastPath::dispatch(new ShortNoteCheckJob($transcript->id), FastPath::isShortTranscript($transcript));
        } elseif (app(DoubleCheck::class)->wanted($transcript)) {
            // Double-check (when it is on): a second engine hears the unsure lines again, on the queue.
            DoubleCheckJob::dispatch($transcript->id);
        }
        // Roman Urdu (or the view its language opens in) is made at once, so the transcript opens in it (plan A3).
        if (! $shortCheck && in_array($transcript->kind, [TranscriptKind::Final, TranscriptKind::Alternate], true)) {
            app(TranscriptViews::class)->autoMake($transcript);
        }
        // A client's recording gets its call notes and memory lines (v1.4), on the queue.
        app(ClientWorkspace::class)->afterTranscript($transcript);

        return $transcript->refresh();
    }

    /**
     * The language to run again in, and why, or null when the result may stay. Only once per transcript (a forced
     * run is kept whatever it returns; its Hindi lines are flagged). Live captions are never re-run.
     *
     * - Auto, and the engine heard a language Auto may not pick (French for an Urdu call): the console's fallback.
     * - Any recording whose text is Hindi (Devanagari) letters: Urdu.
     *
     * @return array{0: string, 1: string}|null
     */
    private function forcedLanguage(Transcript $transcript, NormalizedTranscript $result): ?array
    {
        if ($transcript->kind === TranscriptKind::Live || isset(($transcript->meta ?? [])['forced_language'])) {
            return null;
        }
        $choice = LanguageChoice::normalize($transcript->language);
        $detected = LanguageChoice::fromDetected($result->language);
        if (ScriptCheck::wrongScript($choice ?? $detected, $result->text) === 'devanagari') {
            return ['ur', 'devanagari'];
        }
        // Only a language the engine named counts (an engine that reports none is not second-guessed).
        if ($choice === null && $result->language !== null && trim($result->text) !== '' && ! LanguageChoice::allows(null, $detected, $this->settings)) {
            return [LanguageChoice::fallback($this->settings), 'not_allowed:'.($result->language ?? 'unknown')];
        }

        return null;
    }

    /**
     * The engine a recording on Auto should go to once its language is known, or null to keep this result: only a
     * final transcript, once, when the detected language's own list starts with another engine that has its key.
     */
    private function rerouteEngine(Transcript $transcript, NormalizedTranscript $result): ?SttProviderKey
    {
        $meta = $transcript->meta ?? [];
        if ($transcript->kind !== TranscriptKind::Final || $transcript->language !== null || isset($meta['rerouted_from']) || isset($meta['forced_language'])) {
            return null;
        }
        $detected = LanguageChoice::fromDetected($result->language);
        if ($detected === null || trim($result->text) === '') {
            return null;
        }
        $session = $transcript->captureSession;
        $route = app(LanguageRouter::class)->final($detected, FinalTranscriptCoordinator::useCaseFor($session), $session);

        return $route->provider !== $transcript->providerKey() && ! $route->fallback ? $route->provider : null;
    }

    /** Bills this run, keeps its text per side as the first opinion, and queues [$engine] in the detected language. */
    private function rerouteDetected(Transcript $transcript, NormalizedTranscript $result, SttProviderKey $engine): Transcript
    {
        $this->ledger->recordFinal($transcript, $result);
        $provider = $transcript->providerKey();
        if ($provider !== null && $transcript->provider_job_id !== null && $transcript->provider_job_id !== '') {
            app(ProviderCopyEraser::class)->schedule($provider, $transcript->provider_job_id, $transcript->provider_transcript_id);
        }
        $channels = [];
        foreach ($result->segmentsArray() as $segment) {
            $channel = (int) ($segment['channel'] ?? 1) === 2 ? 2 : 1;
            $channels[(string) $channel] = trim(($channels[(string) $channel] ?? '').' '.trim((string) ($segment['text'] ?? '')));
        }
        $language = LanguageChoice::fromDetected($result->language);
        $meta = $transcript->meta ?? [];
        if (is_array($meta['route'] ?? null)) {
            $meta['route'] = EngineWait::cleared($meta['route']);
        }
        $transcript->update([
            'provider' => $engine->value,
            'status' => TranscriptStatus::Pending,
            'provider_job_id' => null,
            'provider_transcript_id' => null,
            'provider_deleted_at' => null,
            'attempts' => 0,
            'language' => $language,
            'meta' => array_merge($meta, [
                'rerouted_from' => $provider?->value,
                'first_detected_language' => $result->language,
                'first_run' => ['provider' => $provider?->value, 'channels' => $channels],
            ]),
        ]);
        $transcript->captureSession->update(['final_provider' => $engine->value]);
        FastPath::dispatch(new TranscribeFinalJob($transcript->id), FastPath::isShortTranscript($transcript));

        return $transcript->refresh();
    }

    /** Bills the finished run, erases the provider's copy and queues the same engine again in [$language]. */
    private function rerunForced(Transcript $transcript, NormalizedTranscript $result, string $language, string $reason): Transcript
    {
        $this->ledger->recordFinal($transcript, $result);
        $provider = $transcript->providerKey();
        if ($provider !== null && $transcript->provider_job_id !== null && $transcript->provider_job_id !== '') {
            app(ProviderCopyEraser::class)->schedule($provider, $transcript->provider_job_id, $transcript->provider_transcript_id);
        }
        $meta = $transcript->meta ?? [];
        if (is_array($meta['route'] ?? null)) {
            $meta['route'] = EngineWait::cleared($meta['route']); // the engine answered: a wait before this run is over
        }
        $transcript->update([
            'status' => TranscriptStatus::Pending,
            'provider_job_id' => null,
            'provider_transcript_id' => null,
            'provider_deleted_at' => null,
            'attempts' => 0,
            'language' => $language,
            'meta' => array_merge($meta, [
                'forced_language' => $language,
                'forced_reason' => $reason,
                'first_detected_language' => $result->language,
            ]),
        ]);
        TranscribeFinalJob::dispatch($transcript->id);

        return $transcript->refresh();
    }

    /**
     * Lines that are still mostly Hindi letters after the forced Urdu run: their text is replaced by a flag (kept in
     * `raw_text` for the record), never shown as Hindi.
     *
     * @param  list<array<string, mixed>>  $segments
     * @return array{0: list<array<string, mixed>>, 1: int}
     */
    public static function flagDevanagari(array $segments): array
    {
        $flagged = 0;
        foreach ($segments as $i => $segment) {
            $c = ScriptCheck::counts((string) ($segment['text'] ?? ''));
            $letters = $c['arabic'] + $c['devanagari'] + $c['latin'];
            if ($letters > 0 && $c['devanagari'] / $letters >= 0.5) {
                $segments[$i]['raw_text'] = $segment['text'];
                $segments[$i]['text'] = self::WRONG_SCRIPT_TEXT;
                $segments[$i]['flag'] = 'wrong_script';
                $segments[$i]['words'] = [];
                $flagged++;
            }
        }

        return [$segments, $flagged];
    }

    /** What a flagged line shows instead of Hindi letters. */
    public const WRONG_SCRIPT_TEXT = '[unclear: wrong script, re-transcribe]';

    /**
     * Marks a transcript whose text came back in the wrong script for its language (Urdu as Devanagari, for example):
     * script_warning names the script found; the console offers "Transcribe with <next engine>".
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function withScriptCheck(array $meta, Transcript $transcript, string $text, ?string $language): array
    {
        unset($meta['script_warning']);
        if ($transcript->kind === TranscriptKind::Live) {
            return $meta;
        }
        $found = ScriptCheck::wrongScript($language, $text);
        if ($found !== null) {
            $meta['script_warning'] = $found;
        } elseif (($meta['wrong_script_segments'] ?? 0) > 0) {
            $meta['script_warning'] = 'devanagari';
        }

        return $meta;
    }

    /**
     * Text replacements of the vocabulary on a new final transcript: the client's first, then the global ones. The
     * provider's text stays restorable (the editor keeps it as the original revision).
     */
    private function applyVocabulary(Transcript $transcript): void
    {
        if ($transcript->kind === TranscriptKind::Live) {
            return;
        }
        $rules = [...TextReplacements::rules($transcript->captureSession->client?->replacements), ...$this->settings->globalReplacements()];
        if ($rules === []) {
            return;
        }
        [$segments, $count] = TextReplacements::apply($transcript->segments ?? [], $rules);
        if ($count > 0) {
            $this->editor->saveSegments($transcript, $segments, TranscriptRevision::REPLACE, 'Vocabulary');
        }
    }

    /**
     * Marks the transcript (and, for a final one, its session) failed with a readable reason and its error code.
     * Since v1.5 the final transcript of an Urdu or Auto recording whose engine failed for a reason that passes by
     * itself waits for that engine instead (EngineWait); [$response] is the provider's refusal, when there was one.
     */
    public function fail(Transcript $transcript, string $error, ?TranscriptErrorCode $code = null, ?Response $response = null): void
    {
        // A provider that found no speech at all: the recording is silent (every engine would say the same).
        if ($code === TranscriptErrorCode::ProviderRejected && TranscriptErrorCode::saysNoSpeech($error)) {
            $code = TranscriptErrorCode::NoAudio;
            $error = $code->message();
        }
        $final = $transcript->kind === TranscriptKind::Final;
        $transient = $final && $code?->retryable() === true
            ? ($response !== null ? EngineWait::fromResponse($response, $error) : EngineWait::transient($code, $error))
            : null;
        if ($transient !== null && app(EngineWait::class)->hold($transcript, $transient, EngineWait::retryAfter($response))) {
            return;
        }
        DB::transaction(function () use ($transcript, $error, $code, $final, $transient): void {
            $attributes = [
                'status' => TranscriptStatus::Failed,
                'error' => mb_substr($error, 0, 2000),
                'error_code' => $code?->value,
            ];
            $stored = is_array($transcript->meta['route'] ?? null) ? $transcript->meta['route'] : null;
            if ($final && ($stored !== null || $transient !== null)) {
                // Not waiting any more. EngineFallbackJob reads whether the engine stayed unavailable or refused it.
                $route = EngineWait::cleared($stored ?? [], keepSince: true);
                $attributes['meta'] = [...($transcript->meta ?? []), 'route' => $transient !== null ? [...$route, 'transient' => $transient] : $route];
            }
            $transcript->update($attributes);
            if ($final) {
                $transcript->captureSession->update(['status' => SessionStatus::Failed]);
            }
        });
        // Since v1.3 a provider failure hands the final transcript to the next engine of the routing table. Queued, so
        // whatever the caller still does with the failed engine's job (erasing its copy) happens first.
        if ($code?->retryable() === true && $transcript->kind === TranscriptKind::Final) {
            EngineFallbackJob::dispatch($transcript->id)->afterCommit();
        }
    }

    /** Fails with the documented message of $code. */
    public function failWith(Transcript $transcript, TranscriptErrorCode $code): void
    {
        $this->fail($transcript, $code->message(), $code);
    }
}
