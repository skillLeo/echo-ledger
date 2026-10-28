<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TranscriptStatus;
use App\Models\Transcript;
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
            'text' => $transcript->text,
            'segments' => $transcript->segments ?? [],
            ...self::failure($transcript),
            'created_at' => $transcript->created_at->toAtomString(),
            'completed_at' => $transcript->completed_at?->toAtomString(),
            // Since v1.1: set while the text differs from the provider's original (docs/API.md "Editing transcripts").
            'edited_at' => $transcript->edited_at?->toAtomString(),
            // Since v1.2: parts done of all parts while a long recording is transcribed in pieces (Whisper on Groq).
            'progress' => self::progress($transcript),
            // Since v1.2: the Show as views this transcript can have (roman, english) and whether they are made.
            'views' => ['available' => TranscriptViews::available($transcript), 'status' => app(TranscriptViews::class)->state($transcript)['status'],
                'default' => TranscriptViews::defaultView(TranscriptViews::available($transcript))],
        ];
    }

    /**
     * `{done, total}` while the transcript is being made in parts; null otherwise.
     *
     * @return array{done: int, total: int}|null
     */
    public static function progress(?Transcript $transcript): ?array
    {
        if ($transcript === null || ! in_array($transcript->status, [TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing], true)) {
            return null;
        }
        $p = $transcript->meta['progress'] ?? null;
        if (! is_array($p)) {
            return null;
        }
        $total = (int) ($p['chunks_total'] ?? 0);

        return $total > 1 ? ['done' => max(0, min($total, (int) ($p['chunks_done'] ?? 0))), 'total' => $total] : null;
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
