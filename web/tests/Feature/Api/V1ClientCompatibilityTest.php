<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * The v1.0 desktop and phone apps keep working against a v1.1 server until they are updated. The requests below are
 * copied from the v1.0 contract (docs/API.md at `main`): the one-shot session create without markers, session_id or
 * usage reports, chunk upload, `complete`, library sync with `since`, session and transcript reads, deletion, token
 * rotation. The one documented loss is live captions: v1.1 refuses `stt/token` for captions without a session id.
 */

it('lets a v1.0 app pair, upload in one shot, sync and delete', function () {
    $owner = User::factory()->create();
    $this->configureAssemblyAi();
    $this->fakeAssemblyAi('job_v1');
    $code = app(PairingService::class)->createCode($owner);

    // v1.0 pairing body: no new fields.
    $registered = $this->postJson('/api/devices/register', [
        'pairing_code' => $code, 'name' => 'Old laptop', 'platform' => 'windows', 'app_version' => '0.1.1', 'os_version' => '10',
    ])->assertCreated();
    $token = $registered->json('token');
    expect($registered->json('device.source_device'))->toBe('desktop');
    $this->asToken($token);

    // Config has no `api_version` requirement: a v1 app reads the same keys it always did.
    $config = $this->getJson('/api/config')->assertOk();
    expect($config->json('providers.final_transcript.mode'))->toBeString()
        ->and($config->json('providers.final_transcript.required_artifacts'))->toBeArray()
        ->and($config->json('pins'))->toBeArray();

    // The one-shot create of v1.0: no markers, no pauses, no session_id.
    $bytes = Fixtures::audio(300_000, 'V1-AUDIO');
    $created = $this->postJson('/api/sessions', [
        'client_ref' => '3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22', 'source_app' => 'zoom', 'client_id' => null, 'title' => null,
        'started_at' => '2026-10-24T09:00:00Z', 'ended_at' => '2026-10-24T09:00:20Z', 'duration_ms' => 20000, 'language' => null,
        'artifacts' => [[
            'role' => 'primary', 'mime' => 'audio/ogg', 'codec' => 'opus', 'channels' => 2, 'sample_rate' => 48000,
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_ms' => 20000,
        ]],
    ])->assertCreated();
    $recordingId = $created->json('recordings.0.id');
    $sessionId = $created->json('session.id');
    expect($created->json('required_artifacts'))->toBe(['primary'])
        ->and($created->json('recordings.0.upload.chunk_size_bytes'))->toBeInt();

    // Repeating the create (a retry after a crash) is the documented 200.
    $this->postJson('/api/sessions', [
        'client_ref' => '3f2b6c1e-8d4a-4d3e-9a51-2c7f0e9b1a22', 'source_app' => 'zoom', 'started_at' => '2026-10-24T09:00:00Z',
        'artifacts' => [[
            'role' => 'primary', 'mime' => 'audio/ogg', 'codec' => 'opus', 'channels' => 2, 'sample_rate' => 48000,
            'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_ms' => 20000,
        ]],
    ])->assertOk()->assertJsonPath('session.id', $sessionId);

    $this->uploadAll($recordingId, $bytes, $created->json('recordings.0.upload.chunk_size_bytes'));
    $this->postJson("/api/recordings/{$recordingId}/complete")->assertStatus(202)->assertJsonStructure(['status']);

    // v1.0 posts its live captions after the call.
    $this->postJson("/api/sessions/{$sessionId}/transcripts", [
        'kind' => 'live', 'provider' => 'assemblyai', 'language' => 'en',
        'segments' => [Fixtures::segment(['channel' => 2, 'speaker' => 'Client', 'start_ms' => 500, 'end_ms' => 2500, 'text' => 'Hello there.', 'words' => []])],
    ])->assertCreated();

    // Library sync as v1.0 does it: `since` and the cursor, reading the fields it always read.
    $first = $this->getJson('/api/library')->assertOk();
    $item = collect($first->json('data'))->firstWhere('id', $sessionId);
    foreach (['id', 'status', 'source_device', 'source_app', 'started_at', 'updated_at', 'transcript'] as $key) {
        expect($item)->toHaveKey($key);
    }
    expect($first->json('server_time'))->toBeString();
    $this->getJson('/api/library?since='.urlencode((string) $first->json('server_time')))->assertOk()->assertJsonStructure(['data', 'server_time']);

    $this->getJson("/api/sessions/{$sessionId}")->assertOk()->assertJsonStructure(['id', 'status', 'recordings' => [['id', 'role', 'mime', 'size_bytes', 'duration_ms', 'status']], 'transcripts' => [['id', 'kind', 'provider', 'status', 'language']], 'playback_url']);
    $transcriptId = Transcript::query()->where('capture_session_id', $sessionId)->where('kind', 'live')->value('id');
    $this->getJson("/api/transcripts/{$transcriptId}")->assertOk()->assertJsonStructure(['id', 'session_id', 'kind', 'provider', 'status', 'language', 'text', 'segments', 'created_at', 'completed_at']);

    // Token rotation and deletion work as before.
    $rotated = $this->postJson('/api/devices/rotate-token')->assertOk()->json('token');
    $this->asToken($rotated)->deleteJson("/api/sessions/{$sessionId}")->assertNoContent();
    expect(unscoped(fn () => CaptureSession::query()->find($sessionId)))->toBeNull();
});

it('still gives a v1.0 app dictation tokens, and refuses only its captions with a clear code', function () {
    [$device, $token] = $this->enroll(platform: 'windows');
    $this->asToken($token);
    $this->configureAssemblyAi();
    Http::fake(['streaming.assemblyai.com/v3/token*' => Http::response(['token' => 'tmp_stream_token'], 200)]);

    // v1.0 dictation: a microphone-only token without a session.
    $this->postJson('/api/stt/token', ['use_case' => 'dictation', 'channels' => ['me'], 'sample_rate' => 16000])->assertOk()->assertJsonCount(1, 'grants');

    // v1.0 live captions: no session id. The documented loss: the app shows no captions but keeps recording.
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me', 'client'], 'sample_rate' => 16000])
        ->assertStatus(422)->assertJsonPath('code', 'session_required');
});

it('keeps v1.0 error handling working: statuses and a plain message', function () {
    [, $token] = $this->enroll(platform: 'android');
    $this->asToken($token);

    $this->getJson('/api/sessions/'.str_repeat('0', 26))->assertNotFound()->assertJsonStructure(['message']);
    $this->postJson('/api/devices/register', ['pairing_code' => 'WRONG-CODE'])->assertStatus(422)->assertJsonStructure(['message']);
    $this->app['auth']->forgetGuards();
    $this->withHeaders(['Authorization' => ''])->getJson('/api/library')->assertUnauthorized()->assertJsonStructure(['message']);
});
