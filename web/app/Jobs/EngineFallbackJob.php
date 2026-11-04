<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Services\ActivityFeed;
use App\Services\EngineWait;
use App\Stt\LanguageRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Since v1.3: a final transcript that failed for a provider reason is handed to the next engine of the routing table
 * that can do its language and has a key (LanguageRouter::candidates), never to one it already tried. Every attempt
 * is kept in `meta.route.tried` (engine, error code, reason, time) and `meta.route.fallback` turns true. When no engine
 * is left the transcript stays failed and its reason names the engines tried, so the apps can say why and offer Retry.
 *
 * Since v1.5 an Urdu or Auto recording gets here only when its engine refused it or stayed unavailable for the whole
 * wait (App\Services\EngineWait), and the next engine is marked a backup (`meta.route.backup`, `backup_reason`).
 */
final class EngineFallbackJob implements ShouldQueue
{
    use Queueable;

    /** Longest provider reason kept per attempt. */
    private const MAX_REASON_CHARS = 300;

    public function __construct(public readonly string $transcriptId) {}

    public function handle(LanguageRouter $router): void
    {
        $transcript = Transcript::query()->find($this->transcriptId);
        if ($transcript === null || $transcript->status !== TranscriptStatus::Failed || $transcript->kind !== TranscriptKind::Final) {
            return; // gone, retried by someone else, or not the final transcript
        }
        $code = TranscriptErrorCode::tryFrom((string) $transcript->error_code);
        $session = $transcript->captureSession()->withTrashed()->first();
        if ($code === null || ! $code->retryable() || $session === null || $session->trashed()) {
            return;
        }

        $meta = $transcript->meta ?? [];
        $route = is_array($meta['route'] ?? null) ? $meta['route'] : [];
        $tried = is_array($route['tried'] ?? null) ? $route['tried'] : [];
        // Set by TranscriptStore::fail when the engine stayed unavailable (an Urdu or Auto recording waited for it).
        $transient = is_string($route['transient'] ?? null) ? $route['transient'] : null;
        $tried[] = array_filter([
            'engine' => $transcript->provider,
            'code' => $code->value,
            'error' => mb_substr((string) $transcript->error, 0, self::MAX_REASON_CHARS),
            'transient' => $transient,
            'at' => now()->toAtomString(),
        ], fn ($value): bool => $value !== null);
        $route = EngineWait::cleared($route, keepSince: true);
        $done = array_values(array_unique(array_column($tried, 'engine')));

        $next = null;
        foreach ($router->candidates($router->languageFor($session, $transcript->language), $session) as $engine) {
            if (! in_array($engine->value, $done, true)) {
                $next = $engine;
                break;
            }
        }

        if ($next === null) {
            $names = implode(', ', array_map(fn (string $e): string => SttProviderKey::tryFrom($e)?->getLabel() ?? $e, $done));
            $error = (string) $transcript->error;
            if (! str_contains($error, '(Tried:')) {
                $error .= ' (Tried: '.$names.'. No other engine with a key can do this language: add one on API Credentials.)';
            }
            $transcript->update(['meta' => [...$meta, 'route' => [...$route, 'tried' => $tried]], 'error' => mb_substr($error, 0, 2000)]);
            // The activity feed held "Transcription failed" back while another engine could still try (ActivityFeed).
            app(ActivityFeed::class)->transcriptFailed($transcript, $session);

            return;
        }

        // Since v1.5 an Urdu or Auto recording on a lower engine says so (docs/API.md `backup_engine`): it waited for its
        // engine for the whole wait, or that engine refused it. Re-transcribe tries the preferred engine again.
        $backup = EngineWait::forUrdu($transcript)
            ? ['backup' => true, 'backup_reason' => $transient !== null ? EngineWait::WAITED : EngineWait::REJECTED]
            : [];
        DB::transaction(function () use ($transcript, $session, $meta, $route, $tried, $next, $backup): void {
            $transcript->update([
                'provider' => $next->value,
                'status' => TranscriptStatus::Pending,
                'provider_job_id' => null,
                'provider_transcript_id' => null,
                'submitted_at' => null,
                'error' => null,
                'error_code' => null,
                'meta' => [...$meta, 'route' => [...$route, 'used' => $next->value, 'fallback' => true, 'tried' => $tried, ...$backup]],
            ]);
            $session->update(['status' => SessionStatus::Processing, 'final_provider' => $next->value]);
        });
        TranscribeFinalJob::dispatch($transcript->id);
    }
}
