<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TranscriptStatus;
use App\Models\Transcript;
use App\Services\EngineWait;
use App\Services\TranscriptionSpeed;
use App\Services\Views\TranscriptViews;
use App\Stt\LanguageChoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Transcript */
final class TranscriptResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Transcript $transcript */
        $transcript = $this->resource;

        return [
            'id' => $transcript->id,
            'session_id' => $transcript->capture_session_id,
            'kind' => $transcript->kind->value,
            'provider' => $transcript->provider,
            'status' => $transcript->status->value,
            'language' => $transcript->language,
            // Since v1.1: the model the engine reports it used (for example `universal-2`), when it says.
            'model' => self::model($transcript),
            // Since v1.1.2: readable language and engine, a wrong-script warning, and whether the engine was a fallback
            // because the preferred one has no key.
            'language_label' => LanguageChoice::label($transcript->language),
            'provider_label' => $transcript->providerKey()?->getLabel(),
            'script_warning' => $transcript->meta['script_warning'] ?? null,
            'fallback' => (bool) ($transcript->meta['route']['fallback'] ?? false),
            // Since v1.5: an Urdu or Auto recording written by a lower engine of the routing table (backup_engine,
            // backup_reason) and, while it waits for its preferred engine, when that one is tried again (retry_at,
            // retry_reason).
            ...EngineWait::fields($transcript),
            'text' => $transcript->text,
            'segments' => $transcript->segments ?? [],
            ...self::failure($transcript),
            'created_at' => $transcript->created_at->toAtomString(),
            'completed_at' => $transcript->completed_at?->toAtomString(),
            // Since v1.1: set while the text differs from the provider's original (docs/API.md "Editing transcripts").
            'edited_at' => $transcript->edited_at?->toAtomString(),
            // Since v1.2: parts done of all parts while a long recording is transcribed in pieces (Whisper on Groq); since
            // v1.5 (Phase 1C) for every run while it is made, with the time left.
            'progress' => self::progress($transcript),
            // Since v1.2: the Show as views this transcript can have (roman, english) and whether they are made.
            // Since v1.5 (Phase 1C): `progress`, lines done of lines per view while they are being made ({} otherwise).
            'views' => (function () use ($transcript): array {
                $state = app(TranscriptViews::class)->state($transcript);

                return ['available' => $state['available'], 'status' => $state['status'], 'default' => $state['default'], 'progress' => $state['progress']];
            })(),
            // Since v1.4: likely mishearings Claude flagged with the client's vocabulary; nothing changes until accepted.
            'suggestions' => array_values(array_filter(
                array_map(fn (array $s, int $n): array => ['id' => $n, 'index' => (int) $s['line'], 'from' => (string) $s['from'], 'to' => (string) $s['to'], 'reason' => (string) ($s['reason'] ?? '')] + ['status' => (string) ($s['status'] ?? 'open')],
                    $transcript->meta['suggestions'] ?? [], array_keys($transcript->meta['suggestions'] ?? [])),
                fn (array $s): bool => $s['status'] === 'open',
            )),
        ];
    }

    /**
     * `{done, total, eta_seconds}` for every run while it is being made (since v1.5, Phase 1C: `total` 1 for a run in
     * one piece; `eta_seconds` from the audio still to do and the engine's measured speed, null until there is a
     * measure); null once it is finished or failed.
     *
     * @return array{done: int, total: int, eta_seconds: int|null}|null
     */
    public static function progress(?Transcript $transcript): ?array
    {
        if ($transcript === null || ! in_array($transcript->status, [TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing], true)) {
            return null;
        }
        $p = $transcript->meta['progress'] ?? null;
        $total = is_array($p) ? max(1, (int) ($p['chunks_total'] ?? 1)) : 1;
        $done = is_array($p) ? max(0, min($total, (int) ($p['chunks_done'] ?? 0))) : 0;

        return ['done' => $done, 'total' => $total, 'eta_seconds' => app(TranscriptionSpeed::class)->etaSeconds($transcript, $done, $total)];
    }

    public static function model(Transcript $transcript): ?string
    {
        $meta = $transcript->meta ?? [];
        foreach (['speech_model_used', 'model', 'engine'] as $key) {
            if (isset($meta[$key]) && is_string($meta[$key]) && $meta[$key] !== '') {
                return $meta[$key];
            }
        }

        return null;
    }

    /**
     * `error` and `error_code` (docs/API.md): set only while the transcript is failed, so a stale reason of an earlier
     * attempt never reaches a device.
     *
     * @return array{error: string|null, error_code: string|null}
     */
    public static function failure(Transcript $transcript): array
    {
        if ($transcript->status !== TranscriptStatus::Failed) {
            return ['error' => null, 'error_code' => null];
        }
        $code = $transcript->getAttribute('error_code');

        return ['error' => $transcript->error, 'error_code' => is_string($code) && $code !== '' ? $code : null];
    }
}
