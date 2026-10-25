<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\RecordingCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    Http::fake(['*' => Http::response(['id' => 'job_x', 'status' => 'queued', 'upload_url' => 'https://cdn.assemblyai.com/u'], 200)]);
});

/** @return array{0: string, 1: Recording, 2: string} device token, a pending 3-chunk recording (1024, 1024, 452 bytes), plain bytes */
function secUpload(?int $size = null): array
{
    $device = Fx::device();
    $plain = random_bytes($size ?? 2500);
    $recording = Fx::pendingRecording(Fx::session($device->user, $device), $plain, 1024);

    return [Fx::token($device), $recording, $plain];
}

/** Every path currently stored on the fake recordings disk. */
function secStoredPaths(): array
{
    return Storage::disk('recordings')->allFiles();
}

it('stores nothing and reports a client error for an invalid chunk index header', function (string|int|null $index) {
    [$token, $recording, $plain] = secUpload();
    $chunk = substr($plain, 0, 1024);

    $response = test()->putChunk($token, $recording->id, $index, $chunk);

    expect($response->getStatusCode())->toBe(422);
    expect(secStoredPaths())->toBe([]);
    expect($recording->fresh()->received_chunks)->toBe([]);
})->with([
    'missing' => [null],
    'empty' => [''],
    'negative' => ['-1'],
    'fraction' => ['1.5'],
    'exponent' => ['1e3'],
    'leading space' => [' 1'],
    'hex' => ['0x1'],
    'word' => ['abc'],
    'sql' => ["1' OR '1'='1"],
    'arabic-indic digits' => ["\u{0661}"],
    'overflowing digits' => ['99999999999999999999999999'],
    'plus sign' => ['+1'],
    'equal to the chunk count' => ['3'],
    'far out of range' => ['4000000000'],
]);

it('accepts well-formed index headers with leading zeros as the same index', function () {
    [$token, $recording, $plain] = secUpload();

    $this->putChunk($token, $recording->id, '000', substr($plain, 0, 1024))->assertOk()->assertJsonPath('received_chunks', [0]);
    $this->putChunk($token, $recording->id, '00', substr($plain, 0, 1024))->assertOk()->assertJsonPath('received_chunks', [0]);
});

it('rejects a missing or malformed checksum header and stores nothing', function (?string $sha) {
    [$token, $recording, $plain] = secUpload();

    $this->putChunk($token, $recording->id, 0, substr($plain, 0, 1024), $sha)->assertStatus(422);

    expect(secStoredPaths())->toBe([])->and($recording->fresh()->received_chunks)->toBe([]);
})->with([
    'missing' => [null],
    'empty' => [''],
    'too short' => ['abc'],
    'not hex' => [str_repeat('z', 64)],
    'prefixed' => ['sha256:'.str_repeat('a', 57)],
    'too long' => [str_repeat('a', 65)],
    'wrong digest' => [str_repeat('0', 64)],
]);

it('rejects a zero-byte chunk', function () {
    [$token, $recording] = secUpload();

    $this->putChunk($token, $recording->id, 0, '')->assertStatus(422);

    expect(secStoredPaths())->toBe([])->and($recording->fresh()->received_chunks)->toBe([]);
});

it('rejects an oversized chunk with 413 and leaves no partial file behind', function () {
    [$token, $recording, $plain] = secUpload();

    $this->putChunk($token, $recording->id, 0, str_repeat('A', 1025))->assertStatus(413);
    $this->putChunk($token, $recording->id, 2, $plain)->assertStatus(413);

    expect(secStoredPaths())->toBe([])->and($recording->fresh()->received_chunks)->toBe([]);
});

it('rejects a short non-final chunk and a wrong-length final chunk', function () {
    [$token, $recording, $plain] = secUpload();

    $this->putChunk($token, $recording->id, 0, substr($plain, 0, 1023))->assertStatus(422);
    $this->putChunk($token, $recording->id, 2, substr($plain, 2048, 451))->assertStatus(422);
    $this->putChunk($token, $recording->id, 2, str_repeat('x', 453))->assertStatus(413);

    expect(secStoredPaths())->toBe([]);
});

it('removes the temporary file when a chunk fails verification', function () {
    [$token, $recording, $plain] = secUpload();

    $this->putChunk($token, $recording->id, 0, substr($plain, 0, 1024), str_repeat('0', 64))->assertStatus(422);
    $this->putChunk($token, $recording->id, 0, str_repeat('x', 2000))->assertStatus(413);

    $leftovers = array_filter(secStoredPaths(), fn (string $path): bool => str_ends_with($path, '.tmp'));
    expect($leftovers)->toBe([]);
});

it('accepts out-of-order chunks, ignores a duplicate and assembles an identical encrypted file', function () {
    [$token, $recording, $plain] = secUpload();
    $slices = [substr($plain, 0, 1024), substr($plain, 1024, 1024), substr($plain, 2048)];

    foreach ([2, 0, 1, 1] as $index) {
        $this->putChunk($token, $recording->id, $index, $slices[$index])->assertOk();
    }
    $this->api($token)->getJson("/api/recordings/{$recording->id}")->assertOk()->assertJsonPath('upload.received_chunks', [0, 1, 2]);
    $this->api($token)->postJson("/api/recordings/{$recording->id}/complete")->assertStatus(202);

    $ready = $recording->fresh();
    expect($ready->isReady())->toBeTrue();
    $stored = Storage::disk('recordings')->get($ready->stored_path);
    expect($stored)->not->toContain(substr($plain, 0, 64));
    $roundTrip = fopen('php://memory', 'w+b');
    app(RecordingCipher::class)->decryptToStream(Storage::disk('recordings')->path($ready->stored_path), $roundTrip, $ready->id);
    rewind($roundTrip);
    expect(stream_get_contents($roundTrip))->toBe($plain);
    expect(array_filter(secStoredPaths(), fn (string $p): bool => str_starts_with($p, 'parts/')))->toBe([]);
});

it('refuses chunks once the upload is complete', function () {
    [$token, $recording, $plain] = secUpload(1024);

    $this->putChunk($token, $recording->id, 0, $plain)->assertOk();
    $this->api($token)->postJson("/api/recordings/{$recording->id}/complete")->assertStatus(202);

    $this->putChunk($token, $recording->id, 0, str_repeat('Z', 1024))->assertStatus(409);
    expect(app(RecordingCipher::class))->not->toBeNull();
});

it('reports exactly which chunks are missing when completing early', function () {
    [$token, $recording, $plain] = secUpload();
    $this->putChunk($token, $recording->id, 1, substr($plain, 1024, 1024))->assertOk();

    $this->api($token)->postJson("/api/recordings/{$recording->id}/complete")
        ->assertStatus(422)->assertJsonPath('missing_chunks', [0, 2]);

    expect($recording->fresh()->upload_status->value)->not->toBe('ready');
});

it('fails a recording whose assembled bytes do not match the declared checksum and keeps nothing', function () {
    $device = Fx::device();
    $plain = random_bytes(1024);
    $recording = app(ChunkedUploadService::class)->createRecording(Fx::session($device->user, $device), Fx::artifact([
        'size_bytes' => 1024, 'sha256' => hash('sha256', 'something else entirely'),
    ]), 1024);
    $token = Fx::token($device);

    $this->putChunk($token, $recording->id, 0, $plain)->assertOk();
    $this->api($token)->postJson("/api/recordings/{$recording->id}/complete");

    $failed = $recording->fresh();
    expect($failed->upload_status->value)->toBe('failed')->and($failed->stored_path)->toBeNull();
    expect(secStoredPaths())->toBe([]);
});

it('refuses to complete or upload to a recording that was already deleted', function () {
    [$token, $recording, $plain] = secUpload();
    $this->api($token)->deleteJson('/api/sessions/'.$recording->capture_session_id)->assertNoContent();

    $this->putChunk($token, $recording->id, 0, substr($plain, 0, 1024))->assertNotFound();
    $this->api($token)->postJson("/api/recordings/{$recording->id}/complete")->assertNotFound();
});

it('keeps hostile client-supplied names out of every stored path', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $hostile = ['../../../../etc/passwd', '..\\..\\windows\\win.ini', '%2e%2e%2f%2e%2e%2f', 'a/b/c', "x\0y", 'C:\\boot.ini', '....//....//', 'файл.ogg', '${jndi:ldap://x/a}'];
    $plain = 'RIFFxxxxWAVE-hostile-name-test';

    foreach ($hostile as $name) {
        $payload = Fx::sessionPayload([
            'client_ref' => $name, 'title' => $name, 'language' => 'en',
            'artifacts' => [Fx::artifact(['codec' => substr($name, 0, 32), 'size_bytes' => strlen($plain), 'sha256' => hash('sha256', $plain)])],
        ]);
        $created = $this->api($token)->postJson('/api/sessions', $payload);
        expect($created->getStatusCode())->toBeIn([201, 422]);
        if ($created->getStatusCode() !== 201) {
            continue;
        }
        $id = $created->json('recordings.0.id');
        $this->putChunk($token, $id, 0, $plain)->assertOk();
        $this->api($token)->postJson("/api/recordings/{$id}/complete")->assertStatus(202);
    }

    foreach (secStoredPaths() as $path) {
        expect($path)->toMatch('~^files/\d{4}/\d{2}/[0-9a-z]{26}\.elr$~i');
    }
    expect(Storage::disk('recordings')->allDirectories())->each->toMatch('~^(files|files/\d{4}|files/\d{4}/\d{2}|parts)$~');
});

it('only accepts the documented audio MIME types and never a traversal-style type', function () {
    $token = Fx::token(Fx::device());

    foreach (['text/html', 'application/javascript', 'image/svg+xml', 'audio/../../etc', 'audio/ogg; charset=utf-8', 'AUDIO/OGG ', 'audio/ogg\r\nX-Evil: 1', '*/*', 'audio/*', 'application/octet-stream'] as $mime) {
        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['mime' => $mime])]]))->assertStatus(422);
    }
});

it('rejects a request whose declared length exceeds the PHP post limit', function () {
    [$token, $recording] = secUpload();
    $limit = ini_parse_quantity((string) ini_get('post_max_size'));

    $response = $this->api($token)->call('POST', "/api/recordings/{$recording->id}/chunks", [], [], [], $this->transformHeadersToServerVars([
        'Content-Type' => 'application/octet-stream', 'Upload-Chunk-Index' => '0', 'Upload-Chunk-Sha256' => str_repeat('a', 64),
        'Authorization' => 'Bearer '.$token, 'Accept' => 'application/json',
    ]) + ['CONTENT_LENGTH' => $limit + 1], 'x');

    expect($response->getStatusCode())->toBe(413);
});

it('does not reflect request header values back in the response', function () {
    [$token, $recording, $plain] = secUpload();
    $needle = 'REFLECTED-'.bin2hex(random_bytes(6));

    $response = $this->putChunk($token, $recording->id, 0, substr($plain, 0, 1024), "{$needle}\r\nX-Injected: yes", ['Upload-Chunk-Index' => "0{$needle}"]);

    expect($response->headers->has('X-Injected'))->toBeFalse()
        ->and($response->getContent())->not->toContain($needle);
});

it('advertises a chunk size that always fits inside the PHP post limit', function () {
    $limit = ini_parse_quantity((string) ini_get('post_max_size'));
    $advertised = ChunkedUploadService::advertisedChunkSize();

    expect($advertised)->toBeGreaterThanOrEqual(256 * 1024)->toBeLessThanOrEqual(5 * 1024 * 1024);
    if ($limit > 0) {
        expect($advertised)->toBeLessThan(max($limit, 320 * 1024));
    }
});

it('purges abandoned uploads, their parts and stray temporary files with the retention job', function () {
    [$token, $recording, $plain] = secUpload();
    $this->putChunk($token, $recording->id, 0, substr($plain, 0, 1024))->assertOk();
    Storage::disk('recordings')->put($recording->partsDirectory().'/1.part.deadbeef.tmp', 'aborted upload');
    expect(secStoredPaths())->not->toBe([]);

    $this->travel(31)->days();
    Artisan::call('model:prune', ['--model' => [Recording::class, CaptureSession::class]]);

    expect(Recording::query()->count())->toBe(0)
        ->and(secStoredPaths())->toBe([]);
});
