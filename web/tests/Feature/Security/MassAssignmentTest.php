<?php

declare(strict_types=1);

use App\Enums\DevicePlatform;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\User;
use App\Services\PairingService;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

beforeEach(function () {
    Fx::fakeRecordingsDisk();
});

it('ignores privileged fields smuggled into device registration', function () {
    $owner = Fx::user();
    $other = Fx::user();
    $code = app(PairingService::class)->createCode($owner);

    $response = $this->api()->postJson('/api/devices/register', [
        'pairing_code' => $code, 'name' => 'Pixel', 'platform' => 'android',
        'id' => '01JBADBADBADBADBADBADBADBA', 'user_id' => $other->id, 'is_super_admin' => true,
        'abilities' => ['*'], 'last_ip' => '203.0.113.66', 'last_seen_at' => '2001-01-01 00:00:00',
        'created_at' => '2001-01-01 00:00:00', 'token_expires_at' => '2099-01-01T00:00:00Z', 'expires_at' => '2099-01-01',
    ])->assertCreated();

    $device = Device::query()->findOrFail($response->json('device.id'));
    expect($device->id)->not->toBe('01JBADBADBADBADBADBADBADBA')
        ->and($device->user_id)->toBe($owner->id)
        ->and($device->last_ip)->not->toBe('203.0.113.66')
        ->and($device->created_at->year)->toBe((int) now()->year)
        ->and(User::query()->where('is_super_admin', true)->count())->toBe(0);

    $token = PersonalAccessToken::query()->firstOrFail();
    expect($token->abilities)->toBe(Device::ABILITIES)
        ->and($token->expires_at->lessThanOrEqualTo(now()->addDays(366)))->toBeTrue();
});

it('registers the device against the owner of the pairing code and nobody else', function () {
    $owner = Fx::user();
    $bystander = Fx::user();
    $code = app(PairingService::class)->createCode($owner);

    $this->api()->postJson('/api/devices/register?user_id='.$bystander->id, [
        'pairing_code' => $code, 'name' => 'Laptop', 'platform' => 'windows', 'user_id' => $bystander->id,
    ])->assertCreated();

    expect(Device::query()->firstOrFail()->user_id)->toBe($owner->id);
});

it('ignores ownership, status and storage fields smuggled into session creation', function () {
    $device = Fx::device(null, ['platform' => DevicePlatform::Android]);
    $other = Fx::user();
    $otherDevice = Fx::device($other);
    $token = Fx::token($device);

    $payload = Fx::sessionPayload([
        'id' => '01JBADBADBADBADBADBADBADBA', 'user_id' => $other->id, 'device_id' => $otherDevice->id,
        'source_device' => 'web', 'status' => 'completed', 'final_provider' => 'evil_provider',
        'deleted_at' => '2001-01-01 00:00:00', 'created_at' => '2001-01-01 00:00:00', 'updated_at' => '2001-01-01 00:00:00',
        'artifacts' => [Fx::artifact([
            'stored_path' => '../../../../etc/passwd', 'upload_status' => 'ready', 'received_chunks' => [0, 1, 2], 'assembled_at' => '2001-01-01',
            'key_id' => 'v99', 'error' => 'forged', 'chunk_size_bytes' => 1, 'total_chunks' => 1, 'capture_session_id' => 'x', 'id' => 'x',
        ])],
    ]);
    $response = $this->api($token)->postJson('/api/sessions', $payload)->assertCreated();

    $session = CaptureSession::query()->findOrFail($response->json('session.id'));
    expect($session->id)->not->toBe('01JBADBADBADBADBADBADBADBA')
        ->and($session->user_id)->toBe($device->user_id)
        ->and($session->device_id)->toBe($device->id)
        ->and($session->source_device->value)->toBe('phone')
        ->and($session->status->value)->toBe('uploading')
        ->and($session->final_provider)->toBe('assemblyai')
        ->and($session->deleted_at)->toBeNull()
        ->and($session->created_at->year)->toBe((int) now()->year);

    $recording = $session->recordings()->firstOrFail();
    expect($recording->upload_status->value)->toBe('pending')
        ->and($recording->received_chunks)->toBe([])
        ->and($recording->stored_path)->toBeNull()
        ->and($recording->assembled_at)->toBeNull()
        ->and($recording->key_id)->toBe('v1')
        ->and($recording->error)->toBeNull()
        ->and($recording->capture_session_id)->toBe($session->id)
        ->and($recording->chunk_size_bytes)->toBeGreaterThanOrEqual(256 * 1024);
});

it('ignores forged fields when adding a recording to an existing session', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);
    $otherSession = Fx::session(Fx::user());

    $response = $this->api($token)->postJson("/api/sessions/{$session->id}/recordings", Fx::artifact([
        'role' => 'channel_2', 'capture_session_id' => $otherSession->id, 'stored_path' => 'x', 'upload_status' => 'ready',
        'received_chunks' => [0], 'id' => '01JBADBADBADBADBADBADBADBA',
    ]))->assertCreated();

    $recording = Recording::query()->findOrFail($response->json('id'));
    expect($recording->capture_session_id)->toBe($session->id)
        ->and($recording->upload_status->value)->toBe('pending')
        ->and($recording->received_chunks)->toBe([])
        ->and($recording->stored_path)->toBeNull()
        ->and(Recording::query()->where('capture_session_id', $otherSession->id)->count())->toBe(0);
});

it('ignores forged transcript fields and keeps the transcript on the addressed session', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);
    $otherSession = Fx::session(Fx::user());

    $response = $this->api($token)->postJson("/api/sessions/{$session->id}/transcripts", [
        'kind' => 'live', 'provider' => 'assemblyai', 'segments' => Fx::segments('Hello'),
        'id' => '01JBADBADBADBADBADBADBADBA', 'capture_session_id' => $otherSession->id, 'status' => 'failed',
        'provider_job_id' => 'job_forged', 'provider_transcript_id' => 'x', 'error' => 'forged', 'attempts' => 99,
        'meta' => ['evil' => true], 'text' => 'FORGED TEXT', 'completed_at' => '2001-01-01', 'provider_deleted_at' => '2001-01-01',
    ])->assertCreated();

    $transcript = Transcript::query()->findOrFail($response->json('id'));
    expect($transcript->capture_session_id)->toBe($session->id)
        ->and($transcript->status->value)->toBe('completed')
        ->and($transcript->provider_job_id)->toBeNull()
        ->and($transcript->provider_transcript_id)->toBeNull()
        ->and($transcript->error)->toBeNull()
        ->and($transcript->attempts)->toBe(0)
        ->and($transcript->text)->toContain('Hello')->not->toContain('FORGED')
        ->and($transcript->meta)->not->toHaveKey('evil')
        ->and(Transcript::query()->where('capture_session_id', $otherSession->id)->count())->toBe(0);
});

it('does not let a device submit the final transcript of a session the server transcribes', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);

    $this->api($token)->postJson("/api/sessions/{$session->id}/transcripts", [
        'kind' => 'final', 'provider' => 'assemblyai', 'segments' => Fx::segments('Forged final'),
    ])->assertStatus(422);

    expect(Transcript::query()->count())->toBe(0);
});

it('ignores a user id in the library query string', function () {
    $alice = Fx::device();
    $bob = Fx::user();
    Fx::session($bob, null, ['title' => 'Bob call']);

    $response = $this->api(Fx::token($alice))->getJson('/api/library?user_id='.$bob->id.'&device_id=x&owner='.$bob->id);

    $response->assertOk();
    expect($response->json('data'))->toBe([]);
});

it('ignores abilities sent when rotating a token', function () {
    $device = Fx::device();
    $limitedToken = Fx::token($device, ['library:read', 'config:read']);

    $this->api($limitedToken)->postJson('/api/devices/rotate-token', ['abilities' => ['*'], 'expires_at' => '2099-01-01'])->assertOk();

    $rotated = PersonalAccessToken::query()->latest('id')->firstOrFail();
    expect($rotated->abilities)->not->toContain('*')
        ->and($rotated->expires_at->lessThanOrEqualTo(now()->addDays(366)))->toBeTrue();
});

it('never exposes secrets or internals through the device and session JSON', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);
    Fx::readyRecording($session);
    Fx::transcript($session);

    $bodies = [
        $this->api($token)->getJson('/api/config')->getContent(),
        $this->api($token)->getJson("/api/sessions/{$session->id}")->getContent(),
        $this->api($token)->getJson('/api/library')->getContent(),
        $this->api($token)->getJson('/api/transcripts/'.$session->transcripts()->value('id'))->getContent(),
        $this->api($token)->getJson('/api/recordings/'.$session->recordings()->value('id'))->getContent(),
    ];
    $storedPath = (string) $session->recordings()->value('stored_path');
    $hash = (string) DB::table('personal_access_tokens')->value('token');

    foreach ($bodies as $body) {
        expect($body)->not->toContain($storedPath)->not->toContain('stored_path')->not->toContain($hash)
            ->not->toContain('password')->not->toContain('remember_token')->not->toContain('last_ip');
    }
});
