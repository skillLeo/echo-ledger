<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Recording;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Recording */
final class RecordingUploadResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Recording $recording */
        $recording = $this->resource;

        return [
            'id' => $recording->id,
            'role' => $recording->role->value,
            'upload' => [
                'chunk_size_bytes' => $recording->chunk_size_bytes,
                'total_chunks' => $recording->total_chunks,
                'received_chunks' => array_values($recording->received_chunks),
                'status' => $recording->upload_status->value,
            ],
        ];
    }
}
