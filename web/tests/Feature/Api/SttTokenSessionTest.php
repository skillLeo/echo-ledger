<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "POST /api/stt/token" (since v1.1): live captions need an open session of the calling device, still in
 * status recording and younger than four hours. Dictation needs no session.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->configureAssemblyAi();
    Http::fake(['streaming.assemblyai.com/v3/token*' => Http::response(['token' => 'tmp_stream_token'], 200)]);
});

/** An open session of $device created $minutesAgo minutes ago. */
function sessionOpenedAgo(object $test, int $minutesAgo, array $attributes = []): CaptureSession
{
    return CaptureSession::factory()->open()->create(array_merge([
        'user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'created_at' => now()->subMinutes($minutesAgo),
    ], $attributes));
}

it('mints live-caption grants for an open session of the calling device', function () {
    $id = $this->openSession();

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $id])->assertOk()->assertJsonCount(2, 'grants');
});

it('answers 422 session_required without a session id and calls no provider', function (array $payload) {
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions'] + $payload)
        ->assertStatus(422)
        ->assertExactJson([
            'message' => 'The session id field is required for live captions.',
            'code' => 'session_required',
            'errors' => ['session_id' => ['The session id field is required for live captions.']],
        ]);
    Http::assertNothingSent();
})->with(['absent' => [[]], 'null' => [['session_id' => null]], 'empty' => [['session_id' => '']]]);

it('validates the shape of the session id', function () {
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => 'short'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors(['session_id']);
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV']])
        ->assertStatus(422)->assertJsonValidationErrors(['session_id']);
});

it('answers 404 session_not_found for an unknown session', function () {
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
        ->assertNotFound()
        ->assertExactJson(['message' => 'Session not found.', 'code' => 'session_not_found']);
    Http::assertNothingSent();
});

it('answers 404 session_not_found for a session of another device, even of the same owner', function () {
    [$phone] = $this->enroll($this->device->user);
    $theirs = CaptureSession::factory()->open()->create(['user_id' => $phone->user_id, 'device_id' => $phone->id]);
    $stranger = CaptureSession::factory()->open()->create();

    foreach ([$theirs, $stranger] as $session) {
        $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $session->id])
            ->assertNotFound()->assertJsonPath('code', 'session_not_found');
    }
    Http::assertNothingSent();
});

it('answers 404 session_not_found for a discarded session', function () {
    $id = $this->openSession();
    $this->deleteJson("/api/sessions/{$id}")->assertNoContent();

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $id])
        ->assertNotFound()->assertJsonPath('code', 'session_not_found');
});

it('answers 409 session_not_recording once the session was finished', function (SessionStatus $status) {
    $session = sessionOpenedAgo($this, 5, ['status' => $status]);

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $session->id])
        ->assertStatus(409)
        ->assertExactJson(['message' => 'Live captions need an open recording session.', 'code' => 'session_not_recording']);
    Http::assertNothingSent();
})->with([SessionStatus::Uploading, SessionStatus::Processing, SessionStatus::Completed, SessionStatus::Failed]);

it('refuses a token after finishing over the API', function () {
    $id = $this->openSession();
    $this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody(Fixtures::audio(2000)))->assertOk();

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $id])->assertStatus(409)->assertJsonPath('code', 'session_not_recording');
});

it('serves a session younger than four hours and refuses one of exactly four hours', function () {
    $this->freezeTime();
    $young = sessionOpenedAgo($this, 4 * 60 - 1);
    $old = sessionOpenedAgo($this, 4 * 60);

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $young->id])->assertOk();
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $old->id])
        ->assertStatus(409)->assertJsonPath('code', 'session_not_recording');
});

it('checks the session before the client', function () {
    $foreignClient = Client::factory()->create();

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $foreignClient->id])->assertStatus(422)->assertJsonPath('code', 'session_required');
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $this->openSession(), 'client_id' => $foreignClient->id])
        ->assertNotFound()->assertExactJson(['message' => 'Client not found.', 'code' => 'client_not_found']);
});

it('checks the session before answering that captions are off', function () {
    app(SettingsRepository::class)->set('captions.channels', 'off');

    $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertStatus(422)->assertJsonPath('code', 'session_required');
    $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $this->openSession()])->assertOk()->assertJsonPath('mode', 'off');
});

it('serves dictation without a session and ignores one that is sent', function () {
    $this->postJson('/api/stt/token', ['use_case' => 'dictation'])->assertOk()->assertJsonPath('grants.0.channel', 'me');
    $this->postJson('/api/stt/token', ['use_case' => 'dictation', 'session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertOk();
});
