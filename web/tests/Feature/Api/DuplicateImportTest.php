<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

// Imports (since v1.4): "skip_duplicate" skips a file whose audio is already in the workspace's Library.
beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
    $this->bytes = Fixtures::audio(3000);
    $this->first = (string) $this->createSession($this->bytes)->assertCreated()->json('session.id');
});

it('refuses the same file again with duplicate_recording and the recording it matches', function () {
    $this->createSession($this->bytes, ['skip_duplicate' => true, 'title' => 'Again'])
        ->assertStatus(409)
        ->assertJsonPath('code', 'duplicate_recording')
        ->assertJsonPath('message', 'This file is already in your Library.')
        ->assertJsonPath('session_id', $this->first);

    expect(CaptureSession::query()->count())->toBe(1);
});

it('stores the same file twice when the device does not ask to skip duplicates', function () {
    $this->createSession($this->bytes)->assertCreated();

    expect(CaptureSession::query()->count())->toBe(2);
});

it('accepts a different file', function () {
    $this->createSession(Fixtures::audio(3001), ['skip_duplicate' => true])->assertCreated();
});

it('answers a repeated create with the same client_ref as before, not as a duplicate', function () {
    $body = Fixtures::sessionBody(Fixtures::audio(2999), ['skip_duplicate' => true]);
    $id = $this->postJson('/api/sessions', $body)->assertCreated()->json('session.id');

    $this->postJson('/api/sessions', $body)->assertOk()->assertJsonPath('session.id', $id);
});

it('does not count a deleted recording', function () {
    $this->deleteJson("/api/sessions/{$this->first}")->assertNoContent();

    $this->createSession($this->bytes, ['skip_duplicate' => true])->assertCreated();
});

it('does not count the same file in another workspace', function () {
    [, $other] = $this->enroll();
    $this->asToken($other);

    $this->createSession($this->bytes, ['skip_duplicate' => true])->assertCreated();
});

it('rejects a skip_duplicate that is not a boolean', function () {
    $this->createSession(Fixtures::audio(3002), ['skip_duplicate' => 'sometimes'])->assertUnprocessable();
});
