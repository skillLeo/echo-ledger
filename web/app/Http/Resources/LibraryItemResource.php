<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Transcript;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Library item (docs/API.md "Library & sync"). Tombstones carry only id, client_ref, deleted and updated_at.
 *
 * @mixin CaptureSession
 */
final class LibraryItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CaptureSession $session */
        $session = $this->resource;

        if ($session->trashed()) {
            return [
                'id' => $session->id,
                'client_ref' => $session->client_ref,
                'deleted' => true,
                'updated_at' => $session->updated_at->toAtomString(),
            ];
        }

        $transcripts = $session->relationLoaded('transcripts') ? $session->transcripts : $session->transcripts()->get();
        $markers = $session->relationLoaded('markers') ? $session->markers : $session->markers()->get(['id', 'capture_session_id', 'tag', 'at_ms']);
        /** @var Transcript|null $final */
        $final = $transcripts->first(fn (Transcript $t) => $t->kind === TranscriptKind::Final);
        /** @var Transcript|null $live */
        $live = $transcripts->first(fn (Transcript $t) => $t->kind === TranscriptKind::Live);

        return [
            'id' => $session->id,
            'client_ref' => $session->client_ref,
            'deleted' => false,
            'source_device' => $session->source_device->value,
            'source_device_label' => $session->source_device->getLabel(),
            'source_device_icon' => $session->source_device->iconKey(),
            'source_app' => $session->source_app->value,
            'source_app_label' => $session->source_app->getLabel(),
            'device' => $session->device ? ['id' => $session->device->id, 'name' => $session->device->name] : null,
            'client' => $session->client ? ['id' => $session->client->id, 'name' => $session->client->name, 'color' => $session->client->color, 'avatar' => $session->client->avatar] : null,
            'title' => $session->displayTitle(),
            'started_at' => $session->started_at->toAtomString(),
            'ended_at' => $session->ended_at?->toAtomString(),
            'duration_ms' => $session->duration_ms,
            'status' => $session->status->value,
            'transcript' => [
                'final_status' => $final?->status->value,
                'final_transcript_id' => $final?->id,
                'live_available' => $live !== null,
                'live_transcript_id' => $live?->id,
                'preview' => ($final?->text ? $final->preview() : $live?->preview()) ?: null,
            ],
            'marker_count' => $markers->count(),
            'tags' => $markers->pluck('tag')->filter()->unique()->values()->all(),
            'pinned' => (bool) $session->pinned,
            'keep' => (bool) $session->keep_forever,
            'labels' => $session->labelList(),
            'updated_at' => $session->updated_at->toAtomString(),
        ];
    }
}
