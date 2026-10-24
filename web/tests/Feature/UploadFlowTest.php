<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Recording;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

/**
 * End to end over HTTP: create a session, upload the single-chunk recording, complete it, and confirm the server
 * assembled + encrypted the file and the session shows up in the library. The provider is stubbed so the final
 * transcription job cannot reach the network.
 */
it('creates a session, uploads a recording and surfaces it in the library', function () {
    Storage::fake('recordings');
    Http::fake(['*' => Http::response(['id' => 'job_x', 'status' => 'queued', 'upload_url' => 'https://cdn.assemblyai.com/u'], 200)]);

    $device = Device::factory()->create();
    Sanctum::actingAs($device, Device::ABILITIES);

    $bytes = random_bytes(4096);
    $clientRef = (string) Str::uuid();

    $create = $this->postJson('/api/sessions', [
        'client_ref' => $clientRef,
        'source_app' => 'whatsapp',
        'started_at' => now()->subMinute()->toAtomString(),
        'ended_at' => now()->toAtomString(),
        'duration_ms' => 60000,
        'artifacts' => [[
            'role' => 'primary',
            'mime' => 'audio/ogg',
            'codec' => 'opus',
            'channels' => 2,
            'sample_rate' => 16000,
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'duration_ms' => 60000,
        ]],
    ]);

    $create->assertCreated();
    $sessionId = $create->json('session.id');
    $recordingId = $create->json('recordings.0.id');
    expect($recordingId)->not->toBeNull();

    $this->call('POST', "/api/recordings/{$recordingId}/chunks", [], [], [], [
        'HTTP_UPLOAD_CHUNK_INDEX' => '0',
        'HTTP_UPLOAD_CHUNK_SHA256' => hash('sha256', $bytes),
        'CONTENT_TYPE' => 'application/octet-stream',
    ], $bytes)->assertOk();

    $this->postJson("/api/recordings/{$recordingId}/complete")->assertStatus(202);

    // Assembly + encryption run inline on the sync queue.
    $recording = Recording::find($recordingId);
    expect($recording->isReady())->toBeTrue();
    expect(Storage::disk('recordings')->exists($recording->stored_path))->toBeTrue();

    $library = $this->getJson('/api/library')->json('data');
    expect(collect($library)->pluck('id'))->toContain($sessionId);
    expect(collect($library)->firstWhere('id', $sessionId)['source_device'])->toBe('phone');
});
