<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Transcript;
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
            'text' => $transcript->text,
            'segments' => $transcript->segments ?? [],
            'error' => $transcript->status->value === 'failed' ? $transcript->error : null,
            'created_at' => $transcript->created_at->toAtomString(),
            'completed_at' => $transcript->completed_at?->toAtomString(),
        ];
    }
}
