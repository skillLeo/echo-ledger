<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SessionStatus;
use App\Enums\SttProviderKey;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Stt\LanguageRouter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Since v1.3: a final transcript that failed for a provider reason is handed to the next engine of the routing table
 * that can do its language and has a key (LanguageRouter::candidates), never to one it already tried. Every attempt
 * is kept in `meta.route.tried` (engine, error code, reason, time) and `meta.route.fallback` turns true. When no engine
 * is left the transcript stays failed and its reason names the engines tried, so the apps can say why and offer Retry.
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
        $tried[] = [
            'engine' => $transcript->provider,
            'code' => $code->value,
            'error' => mb_substr((string) $transcript->error, 0, self::MAX_REASON_CHARS),
            'at' => now()->toAtomString(),
        ];
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

            return;
        }

        DB::transaction(function () use ($transcript, $session, $meta, $route, $tried, $next): void {
            $transcript->update([
                'provider' => $next->value,
                'status' => TranscriptStatus::Pending,
                'provider_job_id' => null,
                'provider_transcript_id' => null,
                'submitted_at' => null,
                'error' => null,
                'error_code' => null,
                'meta' => [...$meta, 'route' => [...$route, 'used' => $next->value, 'fallback' => true, 'tried' => $tried]],
            ]);
            $session->update(['status' => SessionStatus::Processing, 'final_provider' => $next->value]);
        });
        TranscribeFinalJob::dispatch($transcript->id);
    }
}
