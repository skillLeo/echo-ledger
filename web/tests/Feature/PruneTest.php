<?php

declare(strict_types=1);

use App\Enums\RecordingRole;
use App\Enums\UploadStatus;
use App\Models\CaptureSession;
use App\Models\Recording;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

function recordingAged(int $days): Recording
{
    $session = CaptureSession::factory()->create();
    $recording = $session->recordings()->create([
        'role' => RecordingRole::Primary,
        'mime' => 'audio/ogg',
        'channels' => 2,
        'declared_size_bytes' => 10,
        'sha256' => str_repeat('a', 64),
        'chunk_size_bytes' => 10,
        'total_chunks' => 1,
        'received_chunks' => [0],
        'upload_status' => UploadStatus::Ready,
    ]);
    $recording->forceFill(['created_at' => now()->subDays($days)])->save();

    return $recording;
}

it('prunes recordings past the retention window and keeps recent ones', function () {
    Storage::fake('recordings');
    $old = recordingAged(40); // default retention is 30 days
    $fresh = recordingAged(5);

    Artisan::call('model:prune', ['--model' => [Recording::class]]);

    expect(Recording::find($old->id))->toBeNull();
    expect(Recording::find($fresh->id))->not->toBeNull();
});
