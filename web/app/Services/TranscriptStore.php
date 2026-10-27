<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use App\Services\Usage\UsageLedger;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\ScriptCheck;
use Illuminate\Support\Facades\DB;

/** Persists normalized transcripts and keeps the parent session status in step. */
final class TranscriptStore
{
    public function __construct(private readonly UsageLedger $ledger, private readonly TranscriptEditor $editor, private readonly SettingsRepository $settings) {}

    public function complete(Transcript $transcript, NormalizedTranscript $result): Transcript
    {
        DB::transaction(function () use ($transcript, $result): void {
            $transcript->update([
                'status' => TranscriptStatus::Completed,
                'text' => $result->text,
                'segments' => $result->segmentsArray(),
                // The language the user chose stays; what the engine detected is kept beside it.
                'language' => $transcript->language ?? $result->language,
                'meta' => self::withScriptCheck(array_merge($transcript->meta ?? [], $result->meta, array_filter(['detected_language' => $result->language])), $transcript, $result),
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

        return $transcript->refresh();
    }

    /**
     * Marks a transcript whose text came back in the wrong script for its language (Urdu as Devanagari, for example):
     * script_warning names the script found; the console offers "Transcribe with <next engine>".
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private static function withScriptCheck(array $meta, Transcript $transcript, NormalizedTranscript $result): array
    {
        unset($meta['script_warning']);
        if ($transcript->kind === TranscriptKind::Live) {
            return $meta;
        }
        $found = ScriptCheck::wrongScript($transcript->language ?? $result->language, $result->text);
        if ($found !== null) {
            $meta['script_warning'] = $found;
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
