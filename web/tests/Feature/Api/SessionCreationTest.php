<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Recording;
use App\Services\SettingsRepository;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->asToken($token);
    $this->bytes = Fixtures::audio(3000);
});

describe('a valid request', function () {
    it('creates the session with upload slots in the documented shape', function () {
        $response = $this->createSession($this->bytes, ['title' => 'Call with Farah', 'language' => 'en']);

        $response->assertCreated()
            ->assertJsonPath('session.deleted', false)
            ->assertJsonPath('session.client_ref', fn ($ref) => is_string($ref) && strlen($ref) === 36)
            ->assertJsonPath('session.source_device', 'phone')
            ->assertJsonPath('session.source_device_label', 'Phone')
            ->assertJsonPath('session.source_device_icon', 'device-phone-mobile')
            ->assertJsonPath('session.source_app', 'whatsapp')
            ->assertJsonPath('session.source_app_label', 'WhatsApp')
            ->assertJsonPath('session.title', 'Call with Farah')
            ->assertJsonPath('session.status', 'uploading')
            ->assertJsonPath('session.duration_ms', 60000)
            ->assertJsonPath('session.device.id', $this->device->id)
            ->assertJsonPath('session.client', null)
            ->assertJsonPath('session.transcript.final_status', null)
            ->assertJsonPath('session.transcript.live_available', false)
            ->assertJsonPath('required_artifacts', ['primary'])
            ->assertJsonCount(1, 'recordings')
            ->assertJsonPath('recordings.0.role', 'primary')
            ->assertJsonPath('recordings.0.upload.status', 'pending')
            ->assertJsonPath('recordings.0.upload.received_chunks', [])
            ->assertJsonPath('recordings.0.upload.total_chunks', 1);
        expect($response->json('session.id'))->toMatch(Fixtures::ULID)
            ->and($response->json('recordings.0.id'))->toMatch(Fixtures::ULID)
            ->and($response->json('recordings.0.upload.chunk_size_bytes'))->toBeInt()->toBeGreaterThanOrEqual(256 * 1024);
    });

    it('persists the artifact exactly as declared, with the hash normalised to lower case', function () {
        $upper = strtoupper(hash('sha256', $this->bytes));
        $this->createSession($this->bytes, [], ['sha256' => $upper, 'mime' => 'audio/flac', 'codec' => 'flac', 'channels' => 2, 'sample_rate' => 44100, 'duration_ms' => 59000])->assertCreated();

        $recording = Recording::query()->sole();
        expect($recording->sha256)->toBe(strtolower($upper))
            ->and($recording->mime)->toBe('audio/flac')
            ->and($recording->codec)->toBe('flac')
            ->and($recording->channels)->toBe(2)
            ->and($recording->sample_rate)->toBe(44100)
            ->and($recording->duration_ms)->toBe(59000)
            ->and($recording->declared_size_bytes)->toBe(strlen($this->bytes))
            ->and($recording->upload_status->value)->toBe('pending')
            ->and($recording->received_chunks)->toBe([]);
    });

    it('derives the source device from the calling device and ignores a claimed one', function () {
        [, $desktopToken] = $this->enroll(platform: 'windows');

        $this->asToken($desktopToken)
            ->createSession($this->bytes, ['source_device' => 'web', 'status' => 'completed', 'user_id' => 999, 'final_provider' => 'elevenlabs'])
            ->assertCreated()
            ->assertJsonPath('session.source_device', 'desktop')
            ->assertJsonPath('session.status', 'uploading');

        $session = CaptureSession::query()->sole();
        expect($session->final_provider)->toBe('assemblyai')->and($session->user_id)->not->toBe(999);
    });

    it('stores times in UTC whatever offset the client sends', function () {
        $this->createSession($this->bytes, ['started_at' => '2026-10-24T14:00:00+05:00', 'ended_at' => '2026-10-24T14:30:00.250+05:00'])
            ->assertCreated()
            ->assertJsonPath('session.started_at', '2026-10-24T09:00:00+00:00');

        expect(CaptureSession::query()->sole()->ended_at->toIso8601String())->toBe('2026-10-24T09:30:00+00:00');
    });

    it('accepts an end equal to the start and a zero duration', function () {
        $this->createSession($this->bytes, ['ended_at' => '2026-10-24T09:00:00Z', 'duration_ms' => 0])->assertCreated();
    });

    it('accepts a session with no optional fields at all', function () {
        $this->postJson('/api/sessions', [
            'client_ref' => 'bare',
            'source_app' => 'other',
            'started_at' => '2026-10-24T09:00:00Z',
            'artifacts' => [Fixtures::artifact($this->bytes)],
        ])->assertCreated()->assertJsonPath('session.title', 'Recording')->assertJsonPath('session.ended_at', null);
    });

    it('falls back to a readable title per source app', function (string $app, string $title) {
        $this->createSession($this->bytes, ['source_app' => $app])->assertCreated()->assertJsonPath('session.title', $title);
    })->with([
        ['whatsapp', 'WhatsApp call'],
        ['whatsapp_business', 'WhatsApp Business call'],
        ['whatsapp_web', 'WhatsApp Web call'],
        ['google_meet', 'Google Meet call'],
        ['zoom', 'Zoom call'],
        ['microsoft_teams', 'Microsoft Teams call'],
        ['voice_note', 'Voice note'],
        ['other', 'Recording'],
    ]);

    it('keeps unicode and markup in a title as plain text', function () {
        $title = '<b>Réunion</b> 会議 🎙️';
        $this->createSession($this->bytes, ['title' => $title])->assertCreated()->assertJsonPath('session.title', $title);
    });

    it('accepts a title of exactly 200 characters, counted as characters not bytes', function () {
        $this->createSession($this->bytes, ['title' => str_repeat('é', 200)])->assertCreated();
    });

    it('accepts every MIME type advertised by GET /api/config', function () {
        $advertised = $this->getJson('/api/config')->assertOk()->json('upload.accepted_mimes');

        expect($advertised)->not->toBeEmpty();
        foreach ($advertised as $i => $mime) {
            $this->createSession($this->bytes, ['client_ref' => "mime-{$i}"], ['mime' => $mime])->assertCreated();
        }
        expect(Recording::query()->count())->toBe(count($advertised));
    });

    it('rejects a MIME type that is not advertised', function () {
        $advertised = $this->getJson('/api/config')->json('upload.accepted_mimes');

        foreach (['application/octet-stream', 'audio/midi', 'image/png', 'application/pdf'] as $mime) {
            expect($advertised)->not->toContain($mime);
            $this->createSession($this->bytes, [], ['mime' => $mime])->assertStatus(422);
        }
    });

    it('accepts a primary plus both channel artifacts', function () {
        $left = random_bytes(1500);
        $right = random_bytes(1600);

        $response = $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['artifacts' => [
            Fixtures::artifact($this->bytes),
            Fixtures::artifact($left, ['role' => 'channel_1', 'mime' => 'audio/wav', 'channels' => 1, 'sample_rate' => 16000]),
            Fixtures::artifact($right, ['role' => 'channel_2', 'mime' => 'audio/wav', 'channels' => 1, 'sample_rate' => 16000]),
        ]]))->assertCreated();

        expect(collect($response->json('recordings'))->pluck('role')->sort()->values()->all())->toBe(['channel_1', 'channel_2', 'primary']);
    });

    it('treats a sample rate of zero as unknown', function () {
        $this->createSession($this->bytes, [], ['sample_rate' => 0])->assertCreated();

        expect(Recording::query()->sole()->sample_rate)->toBeNull();
    });

    it('attaches one of the owner\'s clients', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id, 'name' => 'Acme Holdings']);

        $this->createSession($this->bytes, ['client_id' => $client->id])
            ->assertCreated()
            ->assertJsonPath('session.client.id', $client->id)
            ->assertJsonPath('session.client.name', 'Acme Holdings');
    });

    it('computes the chunk count from the advertised chunk size', function (int $factor, int $extra, int $expected) {
        $probe = $this->createSession($this->bytes, ['client_ref' => 'probe'])->assertCreated();
        $chunk = $probe->json('recordings.0.upload.chunk_size_bytes');
        $size = $chunk * $factor + $extra;

        $response = $this->postJson('/api/sessions', Fixtures::sessionBody('', ['client_ref' => 'sized-'.$size], ['size_bytes' => $size, 'sha256' => str_repeat('a', 64)]))->assertCreated();

        expect($response->json('recordings.0.upload.total_chunks'))->toBe($expected);
    })->with([
        'a single byte' => [0, 1, 1],
        'exactly one chunk' => [1, 0, 1],
        'one chunk and a byte' => [1, 1, 2],
        'exactly three chunks' => [3, 0, 3],
        'three chunks and a bit' => [3, 17, 4],
    ]);

    it('pins the final provider at creation so a later settings change cannot move an upload in flight', function () {
        app(SettingsRepository::class)->set('provider.final_transcript', 'elevenlabs');
        $this->createSession($this->bytes, ['client_ref' => 'before'])->assertCreated();

        app(SettingsRepository::class)->set('provider.final_transcript', 'whispercpp_local');
        $response = $this->createSession($this->bytes, ['client_ref' => 'after'])->assertCreated();

        expect(CaptureSession::query()->where('client_ref', 'before')->value('final_provider'))->toBe('elevenlabs')
            ->and(CaptureSession::query()->where('client_ref', 'after')->value('final_provider'))->toBe('whispercpp_local');
        expect($response->json('required_artifacts'))->toBe(['primary', 'channel_1', 'channel_2']);
    });

    it('selects the voice note provider for voice notes', function () {
        app(SettingsRepository::class)->setMany(['provider.final_transcript' => 'elevenlabs', 'provider.voice_note' => 'parakeet_local']);

        $this->createSession($this->bytes, ['source_app' => 'voice_note'])->assertCreated();

        expect(CaptureSession::query()->sole()->final_provider)->toBe('parakeet_local');
    });

    it('accepts an artifact at exactly the maximum recording size', function () {
        $max = (int) config('echoledger.max_recording_bytes');

        $response = $this->postJson('/api/sessions', Fixtures::sessionBody('', [], ['size_bytes' => $max, 'sha256' => str_repeat('b', 64)]))->assertCreated();

        expect($response->json('recordings.0.upload.total_chunks'))->toBe((int) ceil($max / $response->json('recordings.0.upload.chunk_size_bytes')));
    });
});

describe('required fields', function () {
    it('rejects a request missing a top-level field', function (string $field) {
        $body = Fixtures::sessionBody($this->bytes);
        unset($body[$field]);

        $this->postJson('/api/sessions', $body)->assertStatus(422)->assertJsonValidationErrors([$field]);
        expect(CaptureSession::query()->count())->toBe(0);
    })->with(['client_ref', 'source_app', 'started_at']);

    it('opens a session instead of rejecting the request when the artifacts key is absent (since v1.1)', function () {
        $body = Fixtures::sessionBody($this->bytes);
        unset($body['artifacts']);

        $this->postJson('/api/sessions', $body)->assertCreated()
            ->assertJsonPath('session.status', 'recording')
            ->assertJsonPath('recordings', []);
        expect(CaptureSession::query()->sole()->status)->toBe(SessionStatus::Recording)
            ->and(Recording::query()->count())->toBe(0);
    });

    it('still rejects artifacts that are present but null', function () {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['artifacts' => null]))
            ->assertStatus(422)->assertJsonValidationErrors(['artifacts']);
        expect(CaptureSession::query()->count())->toBe(0);
    });

    it('rejects an artifact missing a required field', function (string $field) {
        $body = Fixtures::sessionBody($this->bytes);
        unset($body['artifacts'][0][$field]);

        $this->postJson('/api/sessions', $body)->assertStatus(422)->assertJsonValidationErrors(["artifacts.0.{$field}"]);
        expect(CaptureSession::query()->count())->toBe(0)->and(Recording::query()->count())->toBe(0);
    })->with(['role', 'mime', 'channels', 'size_bytes', 'sha256']);

    it('rejects a completely empty body', function () {
        // Without the artifacts key the request is an open session, so only the session fields are reported.
        $this->postJson('/api/sessions', [])->assertStatus(422)
            ->assertJsonValidationErrors(['client_ref', 'source_app', 'started_at'])
            ->assertJsonMissingValidationErrors(['artifacts']);
    });

    it('treats empty strings and whitespace as missing', function (string $field) {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, [$field => '   ']))
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
    })->with(['client_ref', 'source_app', 'started_at']);
});

describe('invalid values', function () {
    it('rejects bad top-level values', function (array $override, string $field) {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, $override))
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
        expect(CaptureSession::query()->count())->toBe(0);
    })->with([
        'client_ref too long' => [['client_ref' => str_repeat('r', 65)], 'client_ref'],
        'client_ref number' => [['client_ref' => 12345], 'client_ref'],
        'client_ref array' => [['client_ref' => ['x']], 'client_ref'],
        'unknown source app' => [['source_app' => 'telegram'], 'source_app'],
        'source app case' => [['source_app' => 'WhatsApp'], 'source_app'],
        'source app number' => [['source_app' => 3], 'source_app'],
        'client id too short' => [['client_id' => 'abc'], 'client_id'],
        'client id too long' => [['client_id' => str_repeat('A', 27)], 'client_id'],
        'client id number' => [['client_id' => 12], 'client_id'],
        'title too long' => [['title' => str_repeat('t', 201)], 'title'],
        'title array' => [['title' => ['a']], 'title'],
        'start not a date' => [['started_at' => 'not-a-date'], 'started_at'],
        'start impossible date' => [['started_at' => '2026-13-45T00:00:00Z'], 'started_at'],
        'start number' => [['started_at' => ['2026']], 'started_at'],
        'end before start' => [['ended_at' => '2026-10-24T08:59:59Z'], 'ended_at'],
        'end not a date' => [['ended_at' => 'later'], 'ended_at'],
        'negative duration' => [['duration_ms' => -1], 'duration_ms'],
        'duration over a day' => [['duration_ms' => 86400001], 'duration_ms'],
        'duration text' => [['duration_ms' => 'long'], 'duration_ms'],
        'duration fraction' => [['duration_ms' => 1.5], 'duration_ms'],
        'language too long' => [['language' => str_repeat('l', 17)], 'language'],
    ]);

    it('accepts the boundary values next to the rejected ones', function (array $override) {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, $override + ['client_ref' => 'edge']))->assertCreated();
    })->with([
        'client_ref of 64' => [['client_ref' => str_repeat('r', 64)]],
        'title of 200' => [['title' => str_repeat('t', 200)]],
        'duration of exactly a day' => [['duration_ms' => 86400000]],
        'language of 16' => [['language' => str_repeat('l', 16)]],
    ]);

    it('rejects a client that belongs to someone else without attaching it', function () {
        $stranger = Client::factory()->create();

        $status = $this->createSession($this->bytes, ['client_id' => $stranger->id])->getStatusCode();

        expect($status)->toBeIn([404, 422])->and(CaptureSession::query()->count())->toBe(0);
    });

    it('rejects a client id that does not exist', function () {
        $status = $this->createSession($this->bytes, ['client_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->getStatusCode();

        expect($status)->toBeIn([404, 422])->and(CaptureSession::query()->count())->toBe(0);
    });
});

describe('artifacts', function () {
    it('rejects bad artifact values', function (array $override, string $field) {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, [], $override))
            ->assertStatus(422)->assertJsonValidationErrors(["artifacts.0.{$field}"]);
        expect(Recording::query()->count())->toBe(0);
    })->with([
        'unknown role' => [['role' => 'secondary'], 'role'],
        'role case' => [['role' => 'Primary'], 'role'],
        'video mime' => [['mime' => 'video/mp4'], 'mime'],
        'text mime' => [['mime' => 'text/plain'], 'mime'],
        'mime with parameters' => [['mime' => 'audio/ogg; codecs=opus'], 'mime'],
        'mime array' => [['mime' => ['audio/ogg']], 'mime'],
        'html mime' => [['mime' => 'text/html'], 'mime'],
        'zero channels' => [['channels' => 0], 'channels'],
        'three channels' => [['channels' => 3], 'channels'],
        'fractional channels' => [['channels' => 1.5], 'channels'],
        'text channels' => [['channels' => 'stereo'], 'channels'],
        'sample rate too low' => [['sample_rate' => 7999], 'sample_rate'],
        'sample rate too high' => [['sample_rate' => 192001], 'sample_rate'],
        'codec too long' => [['codec' => str_repeat('c', 33)], 'codec'],
        'size zero' => [['size_bytes' => 0], 'size_bytes'],
        'size negative' => [['size_bytes' => -5], 'size_bytes'],
        'size text' => [['size_bytes' => 'big'], 'size_bytes'],
        'size fraction' => [['size_bytes' => 10.5], 'size_bytes'],
        'hash too short' => [['sha256' => str_repeat('a', 63)], 'sha256'],
        'hash too long' => [['sha256' => str_repeat('a', 65)], 'sha256'],
        'hash not hex' => [['sha256' => str_repeat('g', 64)], 'sha256'],
        'hash with prefix' => [['sha256' => 'sha256:'.str_repeat('a', 57)], 'sha256'],
        'negative duration' => [['duration_ms' => -1], 'duration_ms'],
    ]);

    it('rejects a sample rate that is not a number', function (array $override, string $field) {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, [], $override))
            ->assertStatus(422)->assertJsonValidationErrors(["artifacts.0.{$field}"]);
        expect(Recording::query()->count())->toBe(0);
    })->with([
        'sample rate text' => [['sample_rate' => 'high'], 'sample_rate'],
        'sample rate fraction' => [['sample_rate' => 0.5], 'sample_rate'],
        'sample rate text zero with a unit' => [['sample_rate' => '0 Hz'], 'sample_rate'],
    ]);

    it('treats a sample rate sent as the text "0" as unknown, like the number 0', function () {
        $this->createSession($this->bytes, [], ['sample_rate' => '0'])->assertCreated();

        expect(Recording::query()->sole()->sample_rate)->toBeNull();
    });

    it('rejects an oversized artifact', function () {
        $max = (int) config('echoledger.max_recording_bytes');

        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, [], ['size_bytes' => $max + 1]))
            ->assertStatus(422)->assertJsonValidationErrors(['artifacts.0.size_bytes']);
        expect(Recording::query()->count())->toBe(0);
    });

    it('honours a lowered size cap from configuration', function () {
        config(['echoledger.max_recording_bytes' => 5000]);

        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, [], ['size_bytes' => 5001]))->assertStatus(422);
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['client_ref' => 'ok'], ['size_bytes' => 5000]))->assertCreated();
    });

    it('validates every artifact and names the offending index', function () {
        $body = Fixtures::sessionBody($this->bytes, ['artifacts' => [
            Fixtures::artifact($this->bytes),
            Fixtures::artifact($this->bytes, ['role' => 'channel_1', 'mime' => 'video/mp4']),
        ]]);

        $this->postJson('/api/sessions', $body)->assertStatus(422)->assertJsonValidationErrors(['artifacts.1.mime'])
            ->assertJsonMissingValidationErrors(['artifacts.0.mime']);
    });

    it('rejects an empty list and more than three artifacts', function () {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['artifacts' => []]))->assertStatus(422)->assertJsonValidationErrors(['artifacts']);

        $four = array_map(fn (string $role) => Fixtures::artifact($this->bytes, ['role' => $role]), ['primary', 'channel_1', 'channel_2', 'primary']);
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['artifacts' => $four]))->assertStatus(422)->assertJsonValidationErrors(['artifacts']);
    });

    it('rejects artifacts that are not a list of objects', function (mixed $artifacts) {
        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['artifacts' => $artifacts]))->assertStatus(422);
        expect(CaptureSession::query()->count())->toBe(0);
    })->with([
        'a string' => ['audio'],
        'a number' => [5],
        'null' => [null],
        'a single object' => [['role' => 'primary', 'mime' => 'audio/ogg', 'channels' => 1, 'size_bytes' => 10, 'sha256' => 'abc']],
        'list of strings' => [['a', 'b']],
        'list with null' => [[null]],
        'nested list' => [[[1, 2]]],
    ]);

    it('requires exactly one primary artifact with unique roles', function (array $roles) {
        $artifacts = array_map(fn (string $role) => Fixtures::artifact($this->bytes, ['role' => $role]), $roles);

        $this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['artifacts' => $artifacts]))
            ->assertStatus(422)->assertJsonValidationErrors(['artifacts']);
        expect(CaptureSession::query()->count())->toBe(0);
    })->with([
        'two primaries' => [['primary', 'primary']],
        'duplicate channel' => [['primary', 'channel_1', 'channel_1']],
        'no primary' => [['channel_1']],
        'channels only' => [['channel_1', 'channel_2']],
    ]);

    it('does not leave a half-created session behind when the second artifact is invalid', function () {
        $body = Fixtures::sessionBody($this->bytes, ['artifacts' => [
            Fixtures::artifact($this->bytes),
            Fixtures::artifact($this->bytes, ['role' => 'channel_1', 'size_bytes' => 0]),
        ]]);

        $this->postJson('/api/sessions', $body)->assertStatus(422);

        expect(CaptureSession::query()->withTrashed()->count())->toBe(0)->and(Recording::query()->count())->toBe(0);
    });
});

describe('idempotency', function () {
    it('returns the existing session with 200 when the same client_ref is sent again', function () {
        $first = $this->createSession($this->bytes, ['client_ref' => 'ref-1'])->assertCreated();
        $second = $this->createSession($this->bytes, ['client_ref' => 'ref-1'])->assertOk();

        expect($second->json('session.id'))->toBe($first->json('session.id'))
            ->and($second->json('recordings.0.id'))->toBe($first->json('recordings.0.id'))
            ->and(CaptureSession::query()->count())->toBe(1)
            ->and(Recording::query()->count())->toBe(1);
    });

    it('does not rewrite the stored session when the replay differs', function () {
        $this->createSession($this->bytes, ['client_ref' => 'ref-2', 'title' => 'Original'])->assertCreated();

        $other = random_bytes(500);
        $this->createSession($other, ['client_ref' => 'ref-2', 'title' => 'Changed', 'source_app' => 'zoom'])
            ->assertOk()->assertJsonPath('session.title', 'Original')->assertJsonPath('session.source_app', 'whatsapp');

        expect(Recording::query()->sole()->sha256)->toBe(hash('sha256', $this->bytes));
    });

    it('reports upload progress on replay so a restarted client can resume', function () {
        $created = $this->createSession($this->bytes, ['client_ref' => 'resume'])->assertCreated();
        $recording = $created->json('recordings.0.id');
        $this->postChunk($recording, 0, $this->bytes)->assertOk();

        $replay = $this->createSession($this->bytes, ['client_ref' => 'resume'])->assertOk();

        expect($replay->json('recordings.0.upload.received_chunks'))->toBe([0])
            ->and($replay->json('recordings.0.upload.status'))->toBe('uploading');
    });

    it('reports a finished upload as ready on replay', function () {
        $flow = $this->uploadRecording($this->bytes, ['client_ref' => 'done']);

        $replay = $this->createSession($this->bytes, ['client_ref' => 'done'])->assertOk();

        expect($replay->json('recordings.0.id'))->toBe($flow['recording'])
            ->and($replay->json('recordings.0.upload.status'))->toBe('ready');
    });

    it('validates a replay like any other request', function () {
        $this->createSession($this->bytes, ['client_ref' => 'ref-3'])->assertCreated();

        $this->createSession($this->bytes, ['client_ref' => 'ref-3', 'source_app' => 'bogus'])->assertStatus(422);
    });

    it('scopes client_ref to the device, so another device or user may reuse it', function () {
        $this->createSession($this->bytes, ['client_ref' => 'shared-ref'])->assertCreated();

        [, $sameOwner] = $this->enroll($this->device->user);
        $this->asToken($sameOwner)->createSession($this->bytes, ['client_ref' => 'shared-ref'])->assertCreated();

        [, $stranger] = $this->enroll();
        $this->asToken($stranger)->createSession($this->bytes, ['client_ref' => 'shared-ref'])->assertCreated();

        expect(CaptureSession::query()->where('client_ref', 'shared-ref')->count())->toBe(3);
    });

    it('does not resurrect a deleted session when the device retries its creation', function () {
        $created = $this->createSession($this->bytes, ['client_ref' => 'gone'])->assertCreated();
        $this->deleteJson('/api/sessions/'.$created->json('session.id'))->assertNoContent();

        $replay = $this->createSession($this->bytes, ['client_ref' => 'gone'])->assertOk();

        expect($replay->json('session.deleted'))->toBeTrue()
            ->and($replay->json('recordings'))->toBe([])
            ->and(CaptureSession::query()->count())->toBe(0)
            ->and(Recording::query()->count())->toBe(0);
    });

    it('is enforced by the database as well as the controller', function () {
        $this->createSession($this->bytes, ['client_ref' => 'unique-ref'])->assertCreated();
        $existing = CaptureSession::query()->sole();

        expect(fn () => CaptureSession::query()->create([
            'user_id' => $existing->user_id, 'device_id' => $existing->device_id, 'client_ref' => 'unique-ref',
            'source_device' => 'phone', 'source_app' => 'zoom', 'started_at' => now(), 'status' => 'uploading',
        ]))->toThrow(UniqueConstraintViolationException::class);
    });
});

describe('adding a recording to a session', function () {
    beforeEach(function () {
        $this->session = $this->createSession($this->bytes, ['client_ref' => 'host'])->assertCreated()->json('session.id');
        $this->channel = random_bytes(1200);
    });

    it('creates the slot with the documented body', function () {
        $response = $this->postJson("/api/sessions/{$this->session}/recordings", Fixtures::artifact($this->channel, ['role' => 'channel_1', 'mime' => 'audio/wav', 'channels' => 1]));

        $response->assertCreated()
            ->assertJsonPath('role', 'channel_1')
            ->assertJsonPath('upload.status', 'pending')
            ->assertJsonPath('upload.received_chunks', [])
            ->assertJsonPath('upload.total_chunks', 1);
        expect($response->json('id'))->toMatch(Fixtures::ULID)
            ->and(Recording::query()->where('capture_session_id', $this->session)->count())->toBe(2);
    });

    it('rejects a role that already exists on the session', function () {
        $this->postJson("/api/sessions/{$this->session}/recordings", Fixtures::artifact($this->bytes))
            ->assertStatus(422)->assertJsonValidationErrors(['role']);

        expect(Recording::query()->count())->toBe(1);
    });

    it('validates the artifact like session creation does', function (array $override, string $field) {
        $this->postJson("/api/sessions/{$this->session}/recordings", Fixtures::artifact($this->channel, array_merge(['role' => 'channel_1'], $override)))
            ->assertStatus(422)->assertJsonValidationErrors([$field]);
    })->with([
        'bad mime' => [['mime' => 'video/mp4'], 'mime'],
        'bad hash' => [['sha256' => 'abc'], 'sha256'],
        'zero size' => [['size_bytes' => 0], 'size_bytes'],
        'too many channels' => [['channels' => 3], 'channels'],
        'unknown role' => [['role' => 'channel_3'], 'role'],
    ]);

    it('rejects an oversized artifact', function () {
        $this->postJson("/api/sessions/{$this->session}/recordings", Fixtures::artifact($this->channel, ['role' => 'channel_1', 'size_bytes' => config('echoledger.max_recording_bytes') + 1]))
            ->assertStatus(422)->assertJsonValidationErrors(['size_bytes']);
    });

    it('treats an unknown sample rate of zero as absent', function () {
        $this->postJson("/api/sessions/{$this->session}/recordings", Fixtures::artifact($this->channel, ['role' => 'channel_1', 'sample_rate' => 0]))->assertCreated();

        expect(Recording::query()->where('role', 'channel_1')->sole()->sample_rate)->toBeNull();
    });

    it('answers 404 for a missing, foreign or deleted session', function () {
        $body = Fixtures::artifact($this->channel, ['role' => 'channel_1']);
        $this->postJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV/recordings', $body)->assertNotFound();

        $foreign = CaptureSession::factory()->create();
        $this->postJson("/api/sessions/{$foreign->id}/recordings", $body)->assertNotFound();

        $this->deleteJson("/api/sessions/{$this->session}")->assertNoContent();
        $this->postJson("/api/sessions/{$this->session}/recordings", $body)->assertNotFound();
        expect(Recording::query()->count())->toBe(0);
    });
});
