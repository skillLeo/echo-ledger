<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use App\Services\Usage\UsageLedger;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\LanguageChoice;
use App\Stt\ScriptCheck;
use Illuminate\Support\Facades\DB;

/** Persists normalized transcripts and keeps the parent session status in step. */
final class TranscriptStore
{
    public function __construct(private readonly UsageLedger $ledger, private readonly TranscriptEditor $editor, private readonly SettingsRepository $settings) {}

    public function complete(Transcript $transcript, NormalizedTranscript $result): Transcript
    {
        // The right language every time: a result in a language the recording may not use, or in Hindi letters, is
        // run once more with the language forced (the billed first run stays in the usage ledger).
        if (($forced = $this->forcedLanguage($transcript, $result)) !== null) {
            return $this->rerunForced($transcript, $result, $forced[0], $forced[1]);
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

    /** Bills the finished run, erases the provider's copy and queues the same engine again in [$language]. */
    private function rerunForced(Transcript $transcript, NormalizedTranscript $result, string $language, string $reason): Transcript
    {
        $this->ledger->recordFinal($transcript, $result);
        $provider = $transcript->providerKey();
        if ($provider !== null && $transcript->provider_job_id !== null && $transcript->provider_job_id !== '') {
            app(ProviderCopyEraser::class)->schedule($provider, $transcript->provider_job_id, $transcript->provider_transcript_id);
        }
        $transcript->update([
            'status' => TranscriptStatus::Pending,
            'provider_job_id' => null,
            'provider_transcript_id' => null,
            'provider_deleted_at' => null,
            'attempts' => 0,
            'language' => $language,
            'meta' => array_merge($transcript->meta ?? [], [
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

    /** Marks the transcript (and, for a final one, its session) failed with a readable reason and its error code. */
    public function fail(Transcript $transcript, string $error, ?TranscriptErrorCode $code = null): void
    {
        DB::transaction(function () use ($transcript, $error, $code): void {
            $transcript->update([
                'status' => TranscriptStatus::Failed,
                'error' => mb_substr($error, 0, 2000),
                'error_code' => $code?->value,
            ]);
            if ($transcript->kind === TranscriptKind::Final) {
                $transcript->captureSession->update(['status' => SessionStatus::Failed]);
            }
        });
    }

    /** Fails with the documented message of $code. */
    public function failWith(Transcript $transcript, TranscriptErrorCode $code): void
    {
        $this->fail($transcript, $code->message(), $code);
    }
}
