<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\RecordingRole;
use App\Enums\UploadStatus;
use App\Models\CaptureSession;
use App\Models\Recording;

/**
 * Ingests a local plaintext file (admin "Upload voice note", demo seeder) straight into encrypted storage.
 */
final class RecordingIngestor
{
    public function __construct(private readonly ChunkedUploadService $uploads) {}

    /** @param array{codec?: string|null, channels?: int, sample_rate?: int|null, duration_ms?: int|null} $meta */
    public function ingestLocalFile(CaptureSession $session, string $absolutePath, RecordingRole $role, string $mime, array $meta = []): Recording
    {
        $size = (int) filesize($absolutePath);

        /** @var Recording $recording */
        $recording = $session->recordings()->create([
            'role' => $role,
            'mime' => strtolower($mime),
            'codec' => $meta['codec'] ?? null,
            'channels' => $meta['channels'] ?? 1,
            'sample_rate' => $meta['sample_rate'] ?? null,
            'duration_ms' => $meta['duration_ms'] ?? null,
            'declared_size_bytes' => $size,
            'sha256' => (string) hash_file('sha256', $absolutePath),
            'chunk_size_bytes' => max(1, $size),
            'total_chunks' => 1,
            'received_chunks' => [0],
            'upload_status' => UploadStatus::Assembling,
        ]);

        $this->uploads->storeEncrypted($recording, $absolutePath);

        return $recording->refresh();
    }
}
