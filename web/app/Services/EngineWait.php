<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\Transcript;
use App\Stt\LanguageRouter;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Since v1.5: Urdu or mixed speech never falls back to a weaker engine. The final transcript of an Urdu or Auto
 * recording (routing key `ur` or `auto`) whose engine failed for a reason that passes by itself (rate limited, a 5xx,
 * unreachable, timed out, interrupted) waits for that engine: it stays pending and the same engine is tried again
 * after 2, 4, 8, 16 and then every 30 minutes (sooner or later when the provider sends Retry-After), until
 * `routing.urdu_wait_hours` (6) have passed since the first failure. Then the next engine of the routing table takes
 * it as a backup (`meta.route.backup`, reason `waited`); an engine that refuses the recording hands it on at once
 * (reason `rejected`, EngineFallbackJob). English, Arabic and the other languages keep the immediate fallback.
 *
 * Pacing (Groq's limits, any language and kind): an engine with no room right now is tried again when it has room.
 * That is never a failure; for Urdu and Auto it counts towards the same wait.
 *
 * While a transcript waits, `meta.route.retry_at` / `retry_reason` say when and why; the scheduled run carries that
 * time, so a run queued by anyone else before it (stt:reconcile, a repeated upload) does nothing.
 */
final class EngineWait
{
    /** Minutes before each retry after a failure; the last one repeats. */
    public const BACKOFF_MINUTES = [2, 4, 8, 16, 30];

    public const DEFAULT_HOURS = 6;

    public const MAX_HOURS = 72;

    /** The shortest wait, whatever Retry-After says. */
    public const MIN_SECONDS = 5;

    /** A scheduled run this late was lost from the queue: stt:reconcile queues it again. */
    public const LOST_AFTER_MINUTES = 10;

    /** Why a transcript waits (docs/API.md `retry_reason`). */
    public const RATE_LIMITED = 'rate_limited';

    public const UNAVAILABLE = 'unavailable';

    /** Why a backup engine wrote it (docs/API.md `backup_reason`). */
    public const WAITED = 'waited';

    public const REJECTED = 'rejected';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** Urdu or mixed speech: the transcript's language routes as Urdu or Auto. */
    public static function forUrdu(Transcript $transcript): bool
    {
        return in_array(LanguageRouter::routeKey($transcript->language), ['ur', LanguageRouter::AUTO], true);
    }

    /** How long an Urdu or Auto recording waits for its engine (Settings → Engine per language). */
    public function hours(): float
    {
        return self::clampHours($this->settings->get('routing.urdu_wait_hours', self::DEFAULT_HOURS));
    }

    /** A wait setting as hours: 0 to 72 in half hours, the default when it is not a number. */
    public static function clampHours(mixed $value): float
    {
        return is_numeric($value) ? max(0.0, min((float) self::MAX_HOURS, round((float) $value * 2) / 2)) : (float) self::DEFAULT_HOURS;
    }

    /**
     * Whether a failure may pass by itself, and how: `rate_limited` (a 429, or the provider's words say rate limit,
     * quota or too many requests), `unavailable` (a 5xx, unreachable, timed out, interrupted) or null (the engine
     * refused this recording, or is not set up).
     */
    public static function transient(?TranscriptErrorCode $code, string $error): ?string
    {
        return match ($code) {
            TranscriptErrorCode::ProviderUnreachable, TranscriptErrorCode::ProviderTimeout, TranscriptErrorCode::Interrupted => self::UNAVAILABLE,
            TranscriptErrorCode::ProviderRejected => self::fromWords($error),
            default => null,
        };
    }

    /** The same for a refused HTTP request: its status first, then the provider's words. */
    public static function fromResponse(Response $response, string $reason): ?string
    {
        return match (true) {
            $response->status() === 429 => self::RATE_LIMITED,
            $response->status() === 408, $response->serverError() => self::UNAVAILABLE,
            default => self::fromWords($reason),
        };
    }

    private static function fromWords(string $error): ?string
    {
        if (preg_match('/rate[ _-]?limit|too many requests|quota|resource[ _]exhausted|\bHTTP 429\b/i', $error) === 1) {
            return self::RATE_LIMITED;
        }

        return preg_match('/\bHTTP (5\d\d|408)\b|overloaded|temporarily unavailable|service unavailable|bad gateway|gateway time-?out|internal server error/i', $error) === 1
            ? self::UNAVAILABLE : null;
    }

    /** Seconds the provider asks to wait (Retry-After: seconds or an HTTP date), or null when it does not say. */
    public static function retryAfter(?Response $response): ?int
    {
        $value = trim((string) $response?->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return max(0, (int) ceil((float) $value));
        }
        try {
            return max(0, (int) ceil(now()->diffInSeconds(Carbon::parse($value), false)));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Holds a final transcript of an Urdu or Auto recording whose engine failed for [$reason]: back to pending, the
     * same engine again later. False when it does not wait (another language, a recording in the bin, or the wait is
     * over): the caller fails it and the next engine takes it.
     */
    public function hold(Transcript $transcript, string $reason, ?int $retryAfter = null): bool
    {
        if ($transcript->kind !== TranscriptKind::Final || ! self::forUrdu($transcript) || self::binned($transcript)) {
            return false;
        }
        $route = self::route($transcript);
        $since = self::since($route) ?? now();
        $deadline = $this->deadline($since);
        if (! now()->lt($deadline)) {
            return false;
        }
        $waits = (int) ($route['waits'] ?? 0);
        $seconds = $retryAfter !== null ? max(self::MIN_SECONDS, $retryAfter) : self::BACKOFF_MINUTES[min($waits, count(self::BACKOFF_MINUTES) - 1)] * 60;
        $this->schedule($transcript, self::earlier(now()->addSeconds($seconds), $deadline), $reason,
            [...$route, 'wait_started_at' => $since->toAtomString(), 'waits' => $waits + 1]);

        return true;
    }

    /**
     * Pacing (Groq's limits, App\Stt\GroqPacer): the engine has no room for this request for [$seconds]. Any transcript
     * waits for it, without a failure and on the same engine; an Urdu or Auto final one only within its wait. False
     * when that wait is over, or the recording is in the bin: the caller fails it.
     */
    public function pace(Transcript $transcript, int $seconds): bool
    {
        if (self::binned($transcript)) {
            return false;
        }
        $route = self::route($transcript);
        $at = now()->addSeconds(max(self::MIN_SECONDS, $seconds));
        if ($transcript->kind === TranscriptKind::Final && self::forUrdu($transcript)) {
            $since = self::since($route) ?? now();
            $deadline = $this->deadline($since);
            if (! now()->lt($deadline)) {
                return false;
            }
            $at = self::earlier($at, $deadline);
            $route['wait_started_at'] = $since->toAtomString();
        }
        $this->schedule($transcript, $at, self::RATE_LIMITED, $route);

        return true;
    }

    /**
     * Whether a run of the transcript may start now: nothing is scheduled, or this is the scheduled run (or a later
     * one, [$token] null) and its time has come.
     */
    public static function mayRun(Transcript $transcript, ?string $token): bool
    {
        $at = self::route($transcript)['retry_at'] ?? null;
        if (! is_string($at)) {
            return true;
        }
        $time = self::parse($at);
        if ($time !== null && now()->lt($time)) {
            return false;
        }

        return $token === null || $token === $at;
    }

    /** stt:reconcile: a pending transcript may be queued again unless its scheduled run is still due or only just late. */
    public static function due(Transcript $transcript): bool
    {
        $at = self::parse(self::route($transcript)['retry_at'] ?? null);

        return $at === null || $at->copy()->addMinutes(self::LOST_AFTER_MINUTES)->isPast();
    }

    /**
     * The fields every transcript carries (docs/API.md, since v1.5): whether a backup engine wrote it and why, and while
     * an Urdu or Auto transcript waits for its engine, when it is tried again and why.
     *
     * @return array{backup_engine: bool, backup_reason: string|null, retry_at: string|null, retry_reason: string|null}
     */
    public static function fields(Transcript $transcript): array
    {
        $route = self::route($transcript);
        $backup = ($route['backup'] ?? false) === true;
        $reason = $route['backup_reason'] ?? null;
        $at = $transcript->status === TranscriptStatus::Pending && self::forUrdu($transcript) ? self::parse($route['retry_at'] ?? null) : null;
        $why = $route['retry_reason'] ?? null;

        return [
            'backup_engine' => $backup,
            'backup_reason' => $backup && in_array($reason, [self::WAITED, self::REJECTED], true) ? $reason : null,
            'retry_at' => $at?->copy()->utc()->toAtomString(),
            'retry_reason' => $at !== null && in_array($why, [self::RATE_LIMITED, self::UNAVAILABLE], true) ? $why : null,
        ];
    }

    /**
     * The route without a wait that is over (the transcript is finished, failed, or moves to another engine).
     * [$keepSince] keeps when the first failure was, so the next engine does not start a new wait of its own.
     *
     * @param  array<string, mixed>  $route
     * @return array<string, mixed>
     */
    public static function cleared(array $route, bool $keepSince = false): array
    {
        unset($route['retry_at'], $route['retry_reason'], $route['waits'], $route['transient']);
        if (! $keepSince) {
            unset($route['wait_started_at']);
        }

        return $route;
    }

    /** Drops the scheduled time once a run has started (the transcript is no longer waiting). */
    public static function started(Transcript $transcript): void
    {
        $route = self::route($transcript);
        if (! isset($route['retry_at']) && ! isset($route['retry_reason'])) {
            return;
        }
        unset($route['retry_at'], $route['retry_reason']);
        $transcript->forceFill(['meta' => [...($transcript->meta ?? []), 'route' => $route]])->save();
    }

    /**
     * Puts the transcript back to pending until [$at] and queues the run for that time. The copy an async engine kept
     * of the failed attempt is queued for deletion (ProviderCopyEraser), whatever provider_deleted_at still says about
     * an earlier attempt (deleting a copy twice is harmless); the next run starts a new job.
     *
     * @param  array<string, mixed>  $route
     */
    private function schedule(Transcript $transcript, CarbonInterface $at, string $reason, array $route): void
    {
        $provider = $transcript->providerKey();
        if ($provider !== null && $transcript->provider_job_id !== null && $transcript->provider_job_id !== '') {
            app(ProviderCopyEraser::class)->schedule($provider, $transcript->provider_job_id, $transcript->provider_transcript_id);
        }
        $token = $at->copy()->utc()->toAtomString();
        DB::transaction(function () use ($transcript, $route, $token, $reason): void {
            $transcript->update([
                'status' => TranscriptStatus::Pending,
                'provider_job_id' => null,
                'provider_transcript_id' => null,
                'provider_deleted_at' => null,
                'submitted_at' => null,
                'error' => null,
                'error_code' => null,
                'meta' => [...($transcript->meta ?? []), 'route' => [...$route, 'retry_at' => $token, 'retry_reason' => $reason]],
            ]);
            if ($transcript->kind === TranscriptKind::Final) {
                // Touched as well: library sync and the console's live status see the new retry time, however long it waits.
                $transcript->captureSession()->first()?->fill(['status' => SessionStatus::Processing])->touch();
            }
        });
        TranscribeFinalJob::dispatch($transcript->id, $token)->delay($at)->afterCommit();
    }

    private function deadline(CarbonInterface $since): CarbonInterface
    {
        return $since->copy()->addMinutes((int) round($this->hours() * 60));
    }

    /**
     * Deleted, or in the undo bin (B17): no later run sends it to an engine again. The relation also finds a recording
     * in the bin, so it is asked directly.
     */
    private static function binned(Transcript $transcript): bool
    {
        $session = $transcript->captureSession()->first();

        return $session === null || $session->trashed();
    }

    /** @return array<string, mixed> */
    private static function route(Transcript $transcript): array
    {
        $route = ($transcript->meta ?? [])['route'] ?? null;

        return is_array($route) ? $route : [];
    }

    /** @param  array<string, mixed>  $route */
    private static function since(array $route): ?CarbonInterface
    {
        return self::parse($route['wait_started_at'] ?? null);
    }

    private static function parse(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function earlier(CarbonInterface $a, CarbonInterface $b): CarbonInterface
    {
        return $a->lt($b) ? $a : $b;
    }
}
