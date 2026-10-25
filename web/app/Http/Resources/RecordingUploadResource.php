<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Enums\UploadStatus;
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
                'received_chunks' => $recording->received_chunks,
                'status' => $recording->upload_status->value,
                // A readable reason while the upload is failed (the client then sends the whole file again).
                'error' => $recording->upload_status === UploadStatus::Failed ? $recording->error : null,
            ],
        ];
    }
}
