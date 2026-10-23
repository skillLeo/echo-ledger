<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Stt\Data\NormalizedTranscript;
use Illuminate\Support\Facades\DB;

/** Persists normalized transcripts and keeps the parent session status in step. */
final class TranscriptStore
{
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
                'completed_at' => now(),
            ]);
            if ($transcript->kind === TranscriptKind::Final) {
                $transcript->captureSession->update(['status' => SessionStatus::Completed]);
            }
        });

        return $transcript->refresh();
    }

    public function fail(Transcript $transcript, string $error): void
    {
        DB::transaction(function () use ($transcript, $error): void {
            $transcript->update(['status' => TranscriptStatus::Failed, 'error' => mb_substr($error, 0, 2000)]);
            if ($transcript->kind === TranscriptKind::Final) {
                $transcript->captureSession->update(['status' => SessionStatus::Failed]);
            }
        });
    }
}
