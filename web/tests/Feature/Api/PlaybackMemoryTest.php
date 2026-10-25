<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Services\ChunkedUploadService;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "GET /api/recordings/{id}/audio": the server decrypts to a private temporary file per request, never the
 * whole recording in memory. Shared hosting runs PHP with memory_limit 256M while recordings reach hours of audio.
 */

const PLAYBACK_SIZE = 40 * 1024 * 1024;

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->asToken($token);

    // Build the plaintext in pieces so the test itself never holds the recording in memory.
    $uploads = app(ChunkedUploadService::class);
    $this->plain = $uploads->tempPath('big-source');
    $out = fopen($this->plain, 'wb');
    for ($written = 0; $written < PLAYBACK_SIZE; $written += 1024 * 1024) {
        fwrite($out, str_repeat(chr(65 + intdiv($written, 1024 * 1024) % 26), 1024 * 1024));
    }
    fclose($out);
    $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'status' => SessionStatus::Completed]);
    $recording = $uploads->createRecording($session, [
        'role' => 'primary', 'mime' => 'audio/flac', 'channels' => 2, 'size_bytes' => PLAYBACK_SIZE,
        'sha256' => hash_file('sha256', $this->plain),
    ], 5 * 1024 * 1024);
    $uploads->storeEncrypted($recording, $this->plain);
    $this->url = $this->getJson("/api/sessions/{$session->id}")->assertOk()->json('playback_url');
    $this->flushHeaders();
});

afterEach(fn () => @unlink($this->plain));

/** Sends the body through output buffering in 64 KiB pieces, returning its length and SHA-256 without keeping it. */
function drainBody(object $response): array
{
    $hash = hash_init('sha256');
    $length = 0;
    ob_start(function (string $buffer) use ($hash, &$length): string {
        hash_update($hash, $buffer);
        $length += strlen($buffer);

        return '';
    }, 65536);
    $response->baseResponse->sendContent();
    ob_end_clean();

    return [$length, hash_final($hash)];
}

it('streams a large recording without holding it in memory', function () {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $response = $this->get($this->url)->assertOk();
    [$length, $sha] = drainBody($response);

    $growth = memory_get_peak_usage() - $before;
    expect($length)->toBe(PLAYBACK_SIZE)
        ->and($sha)->toBe(hash_file('sha256', $this->plain))
        ->and($growth)->toBeLessThan(16 * 1024 * 1024, 'peak memory grew by '.round($growth / 1048576, 1).' MiB');
});

it('serves a range from deep inside a large recording within the same bound', function () {
    gc_collect_cycles();
    memory_reset_peak_usage();
    $before = memory_get_usage();

    $response = $this->withHeaders(['Range' => 'bytes=31457280-31458303'])->get($this->url);

    $response->assertStatus(206);
    expect($response->headers->get('Content-Range'))->toBe('bytes 31457280-31458303/'.PLAYBACK_SIZE)
        ->and($response->streamedContent())->toBe(file_get_contents($this->plain, false, null, 31457280, 1024))
        ->and(memory_get_peak_usage() - $before)->toBeLessThan(16 * 1024 * 1024);
});

it('answers 416 for a range past the end of a large recording and removes the decrypted copy', function () {
    $response = $this->withHeaders(['Range' => 'bytes='.PLAYBACK_SIZE.'-'])->get($this->url);

    $response->assertStatus(416);
    expect($response->headers->get('Content-Range'))->toBe('bytes */'.PLAYBACK_SIZE)
        ->and((string) $response->getContent())->toBe('')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(array_filter($this->workFiles(), fn (string $f) => str_contains(basename($f), 'play-')))->toBe([]);
});

it('keeps answering ranges that start inside the file, and the whole file for an If-Range', function () {
    $this->withHeaders(['Range' => 'bytes='.(PLAYBACK_SIZE - 1).'-'])->get($this->url)->assertStatus(206);
    $this->withHeaders(['Range' => 'bytes=99999999999999999999-'])->get($this->url)->assertStatus(416);

    $ifRange = $this->withHeaders(['Range' => 'bytes='.PLAYBACK_SIZE.'-', 'If-Range' => '"stale"'])->get($this->url);
    $ifRange->assertOk();
    expect((int) $ifRange->headers->get('Content-Length'))->toBe(PLAYBACK_SIZE);
    drainBody($ifRange);
});
