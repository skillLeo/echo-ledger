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
                'language' => $result->language ?? $transcript->language,
                'meta' => array_merge($transcript->meta ?? [], $result->meta),
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
