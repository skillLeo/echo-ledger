<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\ChunkedUploadService;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

/** A ready (assembled + encrypted) primary recording owned by $device's user. */
function readyRecordingFor(Device $device, string $bytes): CaptureSession
{
    $uploads = app(ChunkedUploadService::class);
    $session = CaptureSession::factory()->create(['user_id' => $device->user_id]);
    $recording = $uploads->createRecording($session, [
        'role' => 'primary', 'mime' => 'audio/ogg', 'channels' => 2,
        'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes),
    ], 1024 * 1024);

    $plain = $uploads->tempPath('audio-test');
    file_put_contents($plain, $bytes);
    $uploads->storeEncrypted($recording, $plain);
    @unlink($plain);

    return $session;
}

beforeEach(function () {
    Storage::fake('recordings');
});

it('refuses audio requests without a valid signature', function () {
    $device = Device::factory()->create();
    $session = readyRecordingFor($device, random_bytes(2048));
    $id = $session->recordings()->first()->id;

    $this->get("/api/recordings/{$id}/audio")->assertForbidden();
    $this->get("/api/recordings/{$id}/audio?expires=9999999999&signature=forged")->assertForbidden();
});

it('streams decrypted audio from a signed URL with private caching and Range support', function () {
    $device = Device::factory()->create();
    $bytes = random_bytes(4096);
    $session = readyRecordingFor($device, $bytes);

    Sanctum::actingAs($device, Device::ABILITIES);
    $url = $this->getJson("/api/sessions/{$session->id}")->assertOk()->json('playback_url');
    expect($url)->toContain('signature=');

    $full = $this->get($url);
    $full->assertOk();
    expect($full->headers->get('Content-Type'))->toContain('audio/ogg');
    expect($full->headers->get('Cache-Control'))->toContain('no-store');
    expect($full->headers->get('X-Content-Type-Options'))->toBe('nosniff');

    $partial = $this->withHeaders(['Range' => 'bytes=0-99'])->get($url);
    $partial->assertStatus(206);
    expect($partial->headers->get('Content-Range'))->toBe('bytes 0-99/4096');
});
