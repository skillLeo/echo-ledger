<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\User;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Stt\Drivers\AssemblyAiDriver;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Api\Support\Shape;

uses(InteractsWithDeviceApi::class);

/*
 * These tests pin the wire format to what the two device apps decode:
 *   mobile/app/src/main/java/com/echoledger/mobile/data/Models.kt   (kotlinx.serialization, ignoreUnknownKeys)
 *   desktop/src-tauri/src/net/models.rs                              (serde)
 * A field is "required" below when either app declares it without a default, with the stricter type of the two.
 */

/** @return array<string, mixed> */
function deviceShape(): array
{
    return Shape::object(['id' => 'string', 'name' => 'string', 'platform' => 'string', 'source_device' => 'string']);
}

/** @return array<string, mixed> */
function serverConfigShape(): array
{
    return Shape::object([
        'api_version' => 'string',
        'device' => deviceShape(),
        'server_time' => 'string',
        'retention_days' => 'int',
        'upload' => Shape::object(['chunk_size_bytes' => 'int', 'max_recording_bytes' => 'int', 'accepted_mimes' => Shape::list('string')]),
        'providers' => Shape::map(Shape::object(['provider' => 'string', 'mode' => 'string', 'required_artifacts' => Shape::optional(Shape::list('string')), 'preferred_primary' => 'string?'])),
        'captions' => Shape::object(['channels' => 'string']),
        'pins' => Shape::list('string'),
        'usage' => usageShape(),
    ]);
}

/** @return array<string, mixed> the `usage` block of GET /api/config and POST /api/usage (since v1.1) */
function usageShape(): array
{
    return Shape::object([
        'month' => 'string', 'time_zone' => 'string', 'period_start' => 'string', 'period_end' => 'string',
        'minutes' => 'number', 'cost_usd' => 'number', 'limit_minutes' => 'int?', 'limit_usd' => 'number?',
        'warn_at_percent' => 'int', 'warn' => 'bool', 'limit_reached' => 'bool',
    ]);
}

/** @return array<string, mixed> every error answer (since v1.1) */
function errorShape(): array
{
    return Shape::object(['message' => 'string', 'code' => 'string', 'errors' => Shape::optional(Shape::map(Shape::list('string')))]);
}

/** @param  array<string, mixed>  $body */
function expectKeys(array $body, array $keys): void
{
    foreach ($keys as $key) {
        expect(array_key_exists($key, $body))->toBeTrue("{$key} must be present, even when null");
    }
}

/** @return array<string, mixed> */
function libraryItemShape(): array
{
    return Shape::object([
        'id' => 'string', 'client_ref' => 'string?', 'deleted' => 'bool',
        'source_device' => 'string', 'source_device_label' => 'string', 'source_device_icon' => 'string',
        'source_app' => 'string', 'source_app_label' => 'string',
        'device' => Shape::object(['id' => 'string', 'name' => 'string']),
        'client' => 'any?',
        'title' => 'string', 'started_at' => 'string', 'ended_at' => 'string?', 'duration_ms' => 'int?', 'status' => 'string',
        'transcript' => Shape::object([
            'final_status' => 'string?', 'final_transcript_id' => 'string?', 'live_available' => 'bool',
            'live_transcript_id' => 'string?', 'preview' => 'string?',
        ]),
        'updated_at' => 'string',
    ]);
}

/** @return array<string, mixed> */
function uploadStateShape(): array
{
    return Shape::object(['chunk_size_bytes' => 'int', 'total_chunks' => 'int', 'received_chunks' => Shape::list('int'), 'status' => 'string', 'error' => 'string?']);
}

/** @return array<string, mixed> */
function transcriptShape(): array
{
    return Shape::object([
        'id' => 'string', 'session_id' => 'string', 'kind' => 'string', 'provider' => 'string', 'status' => 'string',
        'language' => 'string?', 'text' => 'string?',
        'segments' => Shape::list(Shape::object([
            'channel' => 'int', 'speaker' => 'string', 'start_ms' => 'int', 'end_ms' => 'int', 'text' => 'string', 'words' => Shape::list('any'),
        ])),
        'error' => 'string?', 'error_code' => 'string?', 'created_at' => 'string', 'completed_at' => 'string?',
    ]);
}

beforeEach(function () {
    $this->owner = User::factory()->create();
    [$this->device, $this->token] = $this->enroll($this->owner);
    $this->asToken($this->token);
});

describe('registration and configuration', function () {
    it('registers with a body both apps can decode', function () {
        $code = app(PairingService::class)->createCode($this->owner);

        $response = $this->flushHeaders()->postJson('/api/devices/register', [
            'pairing_code' => $code, 'name' => 'Pixel', 'platform' => 'android', 'app_version' => '1.0', 'os_version' => '16',
        ])->assertCreated();

        expect(Shape::violations($response->json(), Shape::object([
            'device' => deviceShape(), 'token' => 'string', 'token_expires_at' => 'string?', 'config' => serverConfigShape(),
        ])))->toBe([]);
    });

    it('serves a configuration both apps can decode', function () {
        $config = $this->getJson('/api/config')->assertOk()->json();

        expect(Shape::violations($config, serverConfigShape()))->toBe([]);
    });

    it('tells the apps which contract the server speaks and how much of the monthly limit is used', function () {
        $config = $this->getJson('/api/config')->assertOk()->json();

        expect(array_key_first($config))->toBe('api_version')
            ->and($config['api_version'])->toBe('1.1')
            ->and(Shape::violations($config['usage'], usageShape()))->toBe([])
            ->and($config['usage']['month'])->toMatch('/^\d{4}-\d{2}$/')
            ->and($config['usage']['period_start'])->toMatch(Fixtures::ISO_8601)
            ->and($config['usage']['period_end'])->toMatch(Fixtures::ISO_8601)
            ->and($config['usage']['warn_at_percent'])->toBe(80);
        expectKeys($config['usage'], ['limit_minutes', 'limit_usd']);
    });

    it('cuts the usage month in the console display time zone', function () {
        app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');
        $this->travelTo(Carbon\Carbon::parse('2026-10-31T19:30:00Z')); // already 1 November in Karachi

        $usage = $this->getJson('/api/config')->json('usage');

        expect($usage)->toMatchArray([
            'month' => '2026-11', 'time_zone' => 'Asia/Karachi',
            'period_start' => '2026-10-31T19:00:00+00:00', 'period_end' => '2026-11-30T19:00:00+00:00',
        ]);
    });

    it('advertises all four use cases with their completion modes', function () {
        $providers = $this->getJson('/api/config')->json('providers');

        expect(array_keys($providers))->toEqualCanonicalizing(['live_captions', 'dictation', 'final_transcript', 'voice_note'])
            ->and($providers['live_captions']['mode'])->toBeIn(['cloud', 'on_device'])
            ->and($providers['dictation']['mode'])->toBeIn(['cloud', 'on_device'])
            ->and($providers['final_transcript']['mode'])->toBeIn(['async_webhook', 'sync', 'device'])
            ->and($providers['voice_note']['mode'])->toBeIn(['async_webhook', 'sync', 'device']);
    });

    it('lists clients with their keyterm counts', function () {
        $client = Client::factory()->create(['user_id' => $this->owner->id, 'name' => 'Acme Holdings', 'color' => '#8B5CF6']);
        foreach (['Farah Khan', 'Q4 forecast', 'EBITDA'] as $term) {
            ClientKeyterm::query()->create(['client_id' => $client->id, 'term' => $term]);
        }
        Client::factory()->create(['name' => 'Someone else\'s client']);

        $response = $this->getJson('/api/clients')->assertOk();

        expect(Shape::violations($response->json(), Shape::object([
            'data' => Shape::list(Shape::object(['id' => 'string', 'name' => 'string', 'color' => 'string?', 'keyterm_count' => 'int', 'updated_at' => 'string'])),
        ])))->toBe([])
            ->and($response->json('data'))->toHaveCount(1)
            ->and($response->json('data.0.name'))->toBe('Acme Holdings')
            ->and($response->json('data.0.keyterm_count'))->toBe(3);
    });

    it('lists clients alphabetically', function () {
        foreach (['Zeta', 'Alpha', 'Mid'] as $name) {
            Client::factory()->create(['user_id' => $this->owner->id, 'name' => $name]);
        }

        $names = array_column($this->getJson('/api/clients')->json('data'), 'name');

        expect($names)->toBe(['Alpha', 'Mid', 'Zeta']);
    });
});

describe('streaming tokens', function () {
    it('returns grants both apps can decode', function () {
        $this->configureAssemblyAi();
        Http::fake(['streaming.assemblyai.com/*' => Http::response(['token' => 'tmp_abc'], 200)]);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $this->openSession(), 'channels' => ['me', 'client'], 'sample_rate' => 16000])->assertOk();

        expect(Shape::violations($response->json(), Shape::object([
            'provider' => 'string?', 'mode' => 'string', 'expires_at' => 'string?', 'keyterms' => Shape::list('string'),
            'grants' => Shape::list(Shape::object([
                'channel' => 'string', 'transport' => 'string', 'protocol' => 'string?', 'ws_url' => 'string?', 'max_session_seconds' => 'int?',
                'audio' => Shape::object(['encoding' => 'string', 'sample_rate' => 'int', 'channels' => 'int', 'frame_ms' => 'int']),
                'init_messages' => Shape::list('any'),
            ])),
        ])))->toBe([]);
    });

    it('returns the "off" answer in the same shape', function () {
        app(SettingsRepository::class)->set('captions.channels', 'off');

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $this->openSession()])->assertOk();

        expect(Shape::violations($response->json(), Shape::object([
            'provider' => 'string?', 'mode' => 'string', 'expires_at' => 'string?', 'keyterms' => Shape::list('string'), 'grants' => Shape::list('any'),
        ])))->toBe([]);
    });
});

describe('sessions and uploads', function () {
    it('creates a session with a body both apps can decode', function () {
        $response = $this->createSession(Fixtures::audio(3000))->assertCreated();

        expect(Shape::violations($response->json(), Shape::object([
            'session' => libraryItemShape(),
            'recordings' => Shape::list(Shape::object(['id' => 'string', 'role' => 'string', 'upload' => uploadStateShape()])),
            'required_artifacts' => Shape::list('string'),
        ])))->toBe([]);
    });

    it('opens a session with a body both apps can decode', function () {
        $response = $this->postJson('/api/sessions', Fixtures::openSessionBody())->assertCreated();

        expect(Shape::violations($response->json(), Shape::object([
            'session' => libraryItemShape(),
            'recordings' => Shape::list('any'),
            'required_artifacts' => Shape::list('string'),
        ])))->toBe([])
            ->and($response->json('session.status'))->toBe('recording')
            ->and($response->json('recordings'))->toBe([]);
    });

    it('finishes a session with the same body as a create', function () {
        $bytes = Fixtures::audio(3000);
        $id = $this->openSession();

        $response = $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($bytes))->assertOk();

        expect(Shape::violations($response->json(), Shape::object([
            'session' => libraryItemShape(),
            'recordings' => Shape::list(Shape::object(['id' => 'string', 'role' => 'string', 'upload' => uploadStateShape()])),
            'required_artifacts' => Shape::list('string'),
        ])))->toBe([])
            ->and($response->json('session.status'))->toBe('uploading');
        expectKeys($response->json('recordings.0.upload'), ['error']);
    });

    it('accepts the exact finish bodies the mobile and desktop apps send', function () {
        $bytes = Fixtures::audio(3000);
        $kotlin = ['ended_at' => '2026-10-24T09:01:00Z', 'duration_ms' => 60000,
            'artifacts' => [['role' => 'primary', 'mime' => 'audio/ogg', 'codec' => 'opus', 'channels' => 2, 'sample_rate' => 48000,
                'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_ms' => 60000]]];
        $rust = ['ended_at' => '2026-10-24T09:01:00.123456789+00:00', 'duration_ms' => 60000, 'title' => null, 'client_id' => null,
            'artifacts' => [['role' => 'primary', 'mime' => 'audio/flac', 'codec' => null, 'channels' => 2, 'sample_rate' => null,
                'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_ms' => null]]];

        $this->postJson('/api/sessions/'.$this->openSession().'/finish', $kotlin)->assertOk();
        $this->postJson('/api/sessions/'.$this->openSession().'/finish', $rust)->assertOk();
    });

    it('reports a failed upload with its reason, and no reason otherwise', function () {
        $created = $this->createSession(Fixtures::audio(3000))->assertCreated();
        $id = (string) $created->json('recordings.0.id');
        expect($this->getJson("/api/recordings/{$id}")->json('upload.error'))->toBeNull();

        Recording::query()->whereKey($id)->update(['upload_status' => 'failed', 'received_chunks' => '[]', 'error' => 'The uploaded file did not match its checksum. Send it again.']);
        $state = $this->getJson("/api/recordings/{$id}")->assertOk();

        expect(Shape::violations($state->json(), Shape::object(['id' => 'string', 'role' => 'string', 'upload' => uploadStateShape()])))->toBe([])
            ->and($state->json('upload'))->toMatchArray(['status' => 'failed', 'received_chunks' => [], 'error' => 'The uploaded file did not match its checksum. Send it again.']);
    });

    it('reports resume state both apps can decode', function () {
        $created = $this->createSession(Fixtures::audio(3000))->assertCreated();

        $state = $this->getJson('/api/recordings/'.$created->json('recordings.0.id'))->assertOk();

        expect(Shape::violations($state->json(), Shape::object(['id' => 'string', 'role' => 'string', 'upload' => uploadStateShape()])))->toBe([]);
    });

    it('answers a chunk with the progress document the desktop reads', function () {
        $bytes = Fixtures::audio(3000);
        $created = $this->createSession($bytes)->assertCreated();

        $response = $this->postChunk($created->json('recordings.0.id'), 0, $bytes)->assertOk();

        expect(Shape::violations($response->json(), Shape::object(['received_chunks' => Shape::list('int'), 'total_chunks' => 'int', 'status' => 'string'])))->toBe([]);
    });

    it('accepts the exact request bodies the mobile and desktop apps send', function () {
        $bytes = Fixtures::audio(3000);
        // Kotlin: explicitNulls = false, so nullable fields are simply absent.
        $kotlin = [
            'client_ref' => 'kt-'.bin2hex(random_bytes(4)), 'source_app' => 'whatsapp', 'started_at' => '2026-10-24T09:00:00Z', 'ended_at' => '2026-10-24T09:01:00Z',
            'duration_ms' => 60000,
            'artifacts' => [['role' => 'primary', 'mime' => 'audio/ogg', 'codec' => 'opus', 'channels' => 2, 'sample_rate' => 48000,
                'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_ms' => 60000]],
        ];
        // Rust: serde serialises every Option as an explicit null.
        $rust = [
            'client_ref' => 'rs-'.bin2hex(random_bytes(4)), 'source_app' => 'zoom', 'client_id' => null, 'title' => null,
            'started_at' => '2026-10-24T09:00:00.123456789+00:00', 'ended_at' => '2026-10-24T09:01:00+00:00', 'duration_ms' => 60000, 'language' => null,
            'artifacts' => [['role' => 'primary', 'mime' => 'audio/flac', 'codec' => null, 'channels' => 2, 'sample_rate' => null,
                'size_bytes' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'duration_ms' => null]],
        ];

        $this->postJson('/api/sessions', $kotlin)->assertCreated();
        $this->postJson('/api/sessions', $rust)->assertCreated();
    });

    it('accepts the live transcript bodies both apps post', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id, 'duration_ms' => 60000]);
        // Kotlin SegmentDto has no words; Rust SegmentDto sends an empty words list.
        $kotlin = ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]];
        $rust = ['kind' => 'live', 'provider' => 'assemblyai', 'language' => null, 'segments' => [['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1000, 'end_ms' => 1900, 'text' => 'Hi', 'words' => []]]];

        $this->postJson("/api/sessions/{$session->id}/transcripts", $kotlin)->assertCreated();
        $this->postJson("/api/sessions/{$session->id}/transcripts", $rust)->assertCreated();
    });

    it('lets a session\'s own detail document be decoded', function () {
        $flow = $this->uploadRecording(Fixtures::audio(3000));

        $detail = $this->getJson("/api/sessions/{$flow['session']}")->assertOk();

        expect(Shape::violations($detail->json(), Shape::object([
            'id' => 'string', 'updated_at' => 'string', 'deleted' => 'bool', 'playback_url' => 'string?',
            'recordings' => Shape::list(Shape::object(['id' => 'string', 'role' => 'string', 'mime' => 'string', 'size_bytes' => 'int', 'duration_ms' => 'int?', 'status' => 'string'])),
            'transcripts' => Shape::list(Shape::object(['id' => 'string', 'kind' => 'string', 'provider' => 'string', 'status' => 'string', 'language' => 'string?', 'error' => 'string?', 'error_code' => 'string?'])),
        ])))->toBe([]);
    });

    it('names the reason of a failed transcript in the session detail, and only for a failure', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id]);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error' => 'No audio was captured in this recording.']);
        $session->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'ok', 'error' => 'stale']);

        $transcripts = collect($this->getJson("/api/sessions/{$session->id}")->assertOk()->json('transcripts'))->keyBy('kind');

        expect($transcripts['final']['error'])->toBe('No audio was captured in this recording.')
            ->and($transcripts['live']['error'])->toBeNull()->and($transcripts['live']['error_code'])->toBeNull();
        expectKeys($transcripts['final'], ['error', 'error_code']);
    });
});

describe('usage reports', function () {
    it('answers a streaming usage report in the documented shape', function () {
        $body = ['report_id' => '6c0d0b2e-2a4e-4c8e-9a9f-0c3f1f5b7d11', 'use_case' => 'live_captions', 'provider' => 'assemblyai',
            'session_id' => $this->openSession(), 'streamed_ms' => 61000, 'channels' => 2, 'ended_at' => '2026-10-24T09:43:00Z'];

        $response = $this->postJson('/api/usage', $body);

        expect($response->getStatusCode())->toBeIn([200, 201]);
        expect(Shape::violations($response->json(), Shape::object([
            'report' => Shape::object([
                'id' => 'string?', 'report_id' => 'string', 'use_case' => 'string', 'provider' => 'string', 'session_id' => 'string?',
                'streamed_ms' => 'int', 'channels' => 'int', 'billed_ms' => 'int', 'cost_usd' => 'number', 'occurred_at' => 'string', 'duplicate' => 'bool',
            ]),
            'usage' => usageShape(),
        ])))->toBe([])
            ->and($response->json('report.billed_ms'))->toBe(122000)
            ->and($response->json('report.occurred_at'))->toBe('2026-10-24T09:43:00+00:00');
    });

    it('refuses a usage report with a malformed body', function () {
        $this->postJson('/api/usage', ['report_id' => 'x', 'use_case' => 'final_transcript', 'provider' => 'nope', 'streamed_ms' => -1, 'channels' => 3])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors(['report_id', 'use_case', 'provider', 'streamed_ms', 'channels']);
    });
});

describe('errors', function () {
    it('answers every error with a message and a machine-readable code', function () {
        $responses = [
            $this->getJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound(),
            $this->postJson('/api/sessions', [])->assertStatus(422),
            $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertStatus(422),
            $this->getJson('/api/library?cursor=garbage')->assertStatus(422),
            $this->deleteJson('/api/config')->assertStatus(405),
        ];
        $this->app['auth']->forgetGuards();
        $responses[] = $this->flushHeaders()->getJson('/api/config')->assertUnauthorized();

        foreach ($responses as $response) {
            expect(Shape::violations($response->json(), errorShape()))->toBe([]);
        }
        expect(array_map(fn ($r) => $r->json('code'), $responses))
            ->toBe(['session_not_found', 'validation_failed', 'session_required', 'invalid_cursor', 'method_not_allowed', 'unauthenticated']);
    });

    it('answers a missing session with the documented body', function () {
        $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/finish', Fixtures::finishBody(Fixtures::audio(1000)))
            ->assertNotFound()->assertExactJson(['message' => 'Session not found.', 'code' => 'session_not_found']);
    });

    it('answers a whole-file verification failure with the documented body and resume state', function () {
        $bytes = Fixtures::audio(3000);
        $flow = $this->uploadRecording($bytes, [], ['sha256' => str_repeat('0', 64)]);

        $flow['complete']->assertStatus(422)->assertExactJson([
            'message' => 'The uploaded file did not match its checksum. Send it again.', 'code' => 'verification_failed',
        ]);
        expect($this->getJson("/api/recordings/{$flow['recording']}")->json('upload'))
            ->toMatchArray(['status' => 'failed', 'received_chunks' => [], 'error' => 'The uploaded file did not match its checksum. Send it again.']);
    });

    it('answers a token request without a session with the documented body', function () {
        $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertStatus(422)->assertExactJson([
            'message' => 'The session id field is required for live captions.', 'code' => 'session_required',
            'errors' => ['session_id' => ['The session id field is required for live captions.']],
        ]);
    });

    it('sends Retry-After with the documented 429 body', function () {
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/stt/token', ['use_case' => 'bogus']);
        }

        $blocked = $this->postJson('/api/stt/token', ['use_case' => 'bogus'])->assertStatus(429);

        expect($blocked->json())->toBe(['message' => 'Too Many Attempts.', 'code' => 'rate_limited'])
            ->and((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
    });
});

describe('library and transcripts', function () {
    it('lists items and tombstones in a body both apps can decode', function () {
        $live = CaptureSession::factory()->create(['user_id' => $this->owner->id, 'device_id' => $this->device->id]);
        CaptureSession::factory()->create(['user_id' => $this->owner->id])->delete();

        $page = $this->getJson('/api/library?since='.urlencode(now()->subYear()->toAtomString()))->assertOk();

        $tombstone = collect($page->json('data'))->firstWhere('deleted', true);
        expect($tombstone)->not->toBeNull();
        expect(Shape::violations($page->json(), Shape::object([
            'data' => Shape::list(Shape::object(['id' => 'string', 'deleted' => 'bool', 'updated_at' => 'string', 'client_ref' => 'string?'])),
            'next_cursor' => 'string?', 'server_time' => 'string',
        ])))->toBe([]);
        expect(Shape::violations(collect($page->json('data'))->firstWhere('id', $live->id), libraryItemShape()))->toBe([]);
    });

    it('serves a completed final transcript in the shape both apps read', function () {
        $this->configureAssemblyAi();
        $this->fakeAssemblyAi('job_1');
        $flow = $this->uploadRecording(Fixtures::audio(5000));
        $transcript = Transcript::query()->where('capture_session_id', $flow['session'])->where('kind', 'final')->sole();
        expect($transcript->status)->toBe(TranscriptStatus::Submitted);
        $this->flushHeaders()->postJson(
            '/api/webhooks/stt/assemblyai?rid='.$transcript->id,
            ['transcript_id' => 'job_1', 'status' => 'completed'],
            [AssemblyAiDriver::WEBHOOK_HEADER => AssemblyAiDriver::webhookToken('whsec_test_secret', $transcript->id)],
        )->assertOk();

        $response = $this->asToken($this->token)->getJson('/api/transcripts/'.$transcript->id)->assertOk();

        expect(Shape::violations($response->json(), transcriptShape()))->toBe([])
            ->and($response->json('kind'))->toBe('final')
            ->and($response->json('status'))->toBe('completed')
            ->and($response->json('segments'))->toHaveCount(2)
            ->and($response->json('segments.0.channel'))->toBeIn([1, 2])
            ->and($response->json('segments.0.speaker'))->toBeIn(['Me', 'Client'])
            ->and($response->json('text'))->toBe("Me: Hello there.\nClient: Hi, thanks for calling.")
            ->and($response->json('error'))->toBeNull();
    });

    it('serves a live transcript uploaded by a device in the same shape', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id, 'duration_ms' => 60000]);
        $id = $this->postJson("/api/sessions/{$session->id}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai', 'language' => 'en', 'segments' => [Fixtures::segment()]])->json('id');

        $response = $this->getJson("/api/transcripts/{$id}")->assertOk();

        expect(Shape::violations($response->json(), transcriptShape()))->toBe([])->and($response->json('kind'))->toBe('live');
    });

    it('serves transcripts that are not finished yet without breaking the decoders', function (TranscriptStatus $status) {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id]);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => $status]);

        $response = $this->getJson("/api/transcripts/{$transcript->id}")->assertOk();

        expect(Shape::violations($response->json(), transcriptShape()))->toBe([])
            ->and($response->json('status'))->toBe($status->value)
            ->and($response->json('segments'))->toBe([])
            ->and($response->json('completed_at'))->toBeNull();
    })->with([TranscriptStatus::Pending, TranscriptStatus::Submitted, TranscriptStatus::Processing, TranscriptStatus::AwaitingDevice]);

    it('carries the reason for a failure and only for a failure', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id]);
        $failed = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error' => 'No audio was captured in this recording.']);
        $session2 = CaptureSession::factory()->create(['user_id' => $this->owner->id]);
        $ok = $session2->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'fine', 'error' => 'stale error text']);

        $a = $this->getJson("/api/transcripts/{$failed->id}")->assertOk();
        $b = $this->getJson("/api/transcripts/{$ok->id}")->assertOk();

        expect(Shape::violations($a->json(), transcriptShape()))->toBe([])
            ->and($a->json('error'))->toBe('No audio was captured in this recording.')
            ->and($b->json('error'))->toBeNull()
            ->and($b->json('error_code'))->toBeNull();
        expectKeys($a->json(), ['error', 'error_code']);
        expectKeys($b->json(), ['error', 'error_code']);
    });

    it('keeps every integer a JSON integer, never a string or float', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id, 'duration_ms' => 61000]);
        $id = $this->postJson("/api/sessions/{$session->id}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai',
            'segments' => [['channel' => '2', 'start_ms' => '100', 'end_ms' => '900', 'text' => 'numbers as strings']]])->assertCreated()->json('id');

        $segment = $this->getJson("/api/transcripts/{$id}")->json('segments.0');

        expect($segment['channel'])->toBe(2)->and($segment['start_ms'])->toBe(100)->and($segment['end_ms'])->toBe(900);
    });

    it('answers 404 for a transcript that does not exist or belongs to someone else', function () {
        $foreign = CaptureSession::factory()->create();
        $theirs = $foreign->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'private']);

        $this->getJson('/api/transcripts/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
        $response = $this->getJson("/api/transcripts/{$theirs->id}")->assertNotFound();
        expect($response->getContent())->not->toContain('private');
    });

    it('does not serve the transcript of a deleted session', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id]);
        $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'gone']);
        $session->delete();

        $this->getJson("/api/transcripts/{$transcript->id}")->assertNotFound();
    });
});

describe('documentation drift', function () {
    it('serves timestamps in the form the documentation promises', function () {
        $this->createSession(Fixtures::audio(3000))->assertCreated();
        $item = $this->getJson('/api/library')->json('data.0');

        // docs/API.md: "Timestamps are ISO-8601 UTC (2026-10-24T09:00:00Z)". Z and +00:00 both denote UTC.
        foreach (['started_at', 'ended_at', 'updated_at'] as $field) {
            expect($item[$field])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|\+00:00)$/');
        }
    });

    it('shows the SourceDevice labels and icons listed in docs/API.md', function () {
        expect(array_map(fn ($c) => [$c->value, $c->getLabel(), $c->iconKey()], SourceDevice::cases()))->toBe([
            ['phone', 'Phone', 'device-phone-mobile'],
            ['desktop', 'Desktop', 'computer-desktop'],
            ['web', 'Web', 'globe-alt'],
        ]);
    });

    it('shows the SourceApp labels listed in docs/API.md', function () {
        $labels = collect(SourceApp::cases())->mapWithKeys(fn ($c) => [$c->value => $c->getLabel()])->all();

        expect($labels)->toBe([
            'whatsapp' => 'WhatsApp', 'whatsapp_business' => 'WhatsApp Business', 'whatsapp_web' => 'WhatsApp Web',
            'google_meet' => 'Google Meet', 'zoom' => 'Zoom', 'microsoft_teams' => 'Microsoft Teams',
            'telegram' => 'Telegram', 'signal' => 'Signal', 'messenger' => 'Messenger', 'instagram' => 'Instagram',
            'skype' => 'Skype', 'slack' => 'Slack', 'discord' => 'Discord',
            'phone_call' => 'Phone call', 'voice_note' => 'Voice note', 'other' => 'Other',
        ]);
    });
});
