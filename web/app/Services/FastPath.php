<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CaptureSession;
use App\Models\Transcript;

/**
 * The fast path for short notes (since v1.5, Phase 1C): a recording of at most a minute is transcribed, checked and
 * given its views right after the upload answers, in the same process, instead of waiting for the once-a-minute queue
 * run. On a web request the work runs after the response has gone (the phone is not kept waiting); in a queue worker
 * or command it runs at once. Long recordings keep the queue.
 */
final class FastPath
{
    public const MAX_MS = 60_000;

    /** Without a length, a file up to this size is short (a minute of two-sided Opus at 32 kbps is about 480 KB). */
    public const MAX_BYTES = 1_000_000;

    public static function isShort(?CaptureSession $session): bool
    {
        if ($session === null) {
            return false;
        }
        if ((int) ($session->duration_ms ?? 0) > 0) {
            return (int) $session->duration_ms <= self::MAX_MS;
        }
        $recordings = $session->recordings()->get();
        if ($recordings->isEmpty()) {
            return false;
        }
        $longest = (int) $recordings->max('duration_ms');
        if ($longest > 0) {
            return $longest <= self::MAX_MS;
        }

        return (int) $recordings->sum(fn ($r): int => (int) ($r->stored_size_bytes ?? $r->declared_size_bytes)) <= self::MAX_BYTES;
    }

    public static function isShortTranscript(Transcript $transcript): bool
    {
        return self::isShort($transcript->captureSession);
    }

    /** Runs [$job] now-ish when [$short], else queues it. */
    public static function dispatch(object $job, bool $short): void
    {
        if (! $short) {
            dispatch($job);

            return;
        }
        // Tests: the sync queue runs it at once and Queue::fake() still sees it.
        if (app()->runningUnitTests()) {
            dispatch($job);

            return;
        }
        if (app()->runningInConsole()) {
            dispatch_sync($job);

            return;
        }
        dispatch($job)->afterResponse();
    }
}
