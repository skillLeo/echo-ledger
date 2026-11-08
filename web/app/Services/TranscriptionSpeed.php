<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Time left of a transcription (since v1.5, Phase 1C): each engine's speed measured from the runs this server already
 * finished (seconds of audio per second of waiting, from when the run was sent to when it was done), and the audio
 * still to do of a run being made. Null until an engine has a measure.
 */
final class TranscriptionSpeed
{
    /** The newest finished runs of an engine that make its measure. */
    private const SAMPLE = 50;

    /** Runs older than this no longer say how fast the engine is today. */
    private const WITHIN_DAYS = 30;

    /** Recordings shorter than this are mostly waiting in a queue, not transcribing, and would skew the measure. */
    private const MIN_AUDIO_MS = 5_000;

    /** @var array<string, float|null> */
    private array $memo = [];

    /** Seconds of audio an engine does per second on this server, or null without finished runs to measure. */
    public function speedOf(string $provider): ?float
    {
        if (array_key_exists($provider, $this->memo)) {
            return $this->memo[$provider];
        }

        return $this->memo[$provider] = Cache::remember('transcription-speed:'.$provider, now()->addMinutes(10), fn (): ?float => $this->measure($provider));
    }

    /**
     * Seconds until [$transcript] is done: the audio still to do at the engine's measured speed, less the time the
     * current part has already taken; null without a measure or a recording length.
     */
    public function etaSeconds(Transcript $transcript, int $done, int $total): ?int
    {
        $speed = $this->speedOf((string) $transcript->provider);
        $audioMs = (int) (CaptureSession::withTrashed()->whereKey($transcript->capture_session_id)->value('duration_ms') ?? 0);
        if ($speed === null || $speed <= 0 || $audioMs <= 0 || $total < 1) {
            return null;
        }
        $left = $audioMs / 1000 * max(0, $total - $done) / $total / $speed;
        if ($total === 1) {
            $started = $transcript->submitted_at ?? $transcript->created_at;
            $left -= max(0, $started->diffInSeconds(now()));
        }

        return max(0, (int) ceil($left));
    }

    private function measure(string $provider): ?float
    {
        $rows = DB::table('transcripts')
            ->join('capture_sessions', 'capture_sessions.id', '=', 'transcripts.capture_session_id')
            ->where('transcripts.provider', $provider)
            ->whereIn('transcripts.kind', [TranscriptKind::Final->value, TranscriptKind::Trial->value])
            ->where('transcripts.status', TranscriptStatus::Completed->value)
            ->whereNotNull('transcripts.submitted_at')
            ->whereNotNull('transcripts.completed_at')
            ->where('transcripts.completed_at', '>=', now()->subDays(self::WITHIN_DAYS))
            ->where('capture_sessions.duration_ms', '>=', self::MIN_AUDIO_MS)
            ->orderByDesc('transcripts.completed_at')
            ->limit(self::SAMPLE)
            ->get(['capture_sessions.duration_ms', 'transcripts.submitted_at', 'transcripts.completed_at']);
        $audio = 0.0;
        $waited = 0.0;
        foreach ($rows as $row) {
            $seconds = strtotime((string) $row->completed_at) - strtotime((string) $row->submitted_at);
            if ($seconds <= 0) {
                continue;
            }
            $audio += (int) $row->duration_ms / 1000;
            $waited += $seconds;
        }

        return $waited > 0 && $audio > 0 ? $audio / $waited : null;
    }
}
