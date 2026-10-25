<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\UploadStatus;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * A6.W24 / docs/API.md `too_many_open_uploads`: a device may keep at most 50 recordings waiting for chunks, so a
 * misbehaving app cannot pile up unbounded upload slots.
 */

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->asToken($token);
    $this->bytes = Fixtures::audio(3000);
});

/** Give the device $count unfinished upload slots in the given status (one session each); returns the last session. */
function fillOpenUploads(object $test, int $count, UploadStatus $status = UploadStatus::Pending, ?string $deviceId = null): CaptureSession
{
    $uploads = app(ChunkedUploadService::class);
    $session = null;
    foreach (range(1, $count) as $_) {
        $session = CaptureSession::factory()->create([
            'user_id' => $test->device->user_id, 'device_id' => $deviceId ?? $test->device->id, 'status' => SessionStatus::Uploading,
        ]);
        $uploads->createRecording($session, Fixtures::artifact($test->bytes), 1024 * 1024)->update(['upload_status' => $status]);
    }

    return $session;
}

function expectTooManyOpenUploads(object $response): void
{
    $response->assertStatus(409)->assertExactJson([
        'message' => 'Too many unfinished uploads. Finish or delete some first.',
        'code' => 'too_many_open_uploads',
    ]);
}

it('allows the fiftieth unfinished upload and refuses the fifty-first on a one-shot create', function () {
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS - 1);

    $this->createSession($this->bytes, ['client_ref' => 'fiftieth'])->assertCreated();
    expectTooManyOpenUploads($this->createSession($this->bytes, ['client_ref' => 'fifty-first']));

    expect(CaptureSession::query()->where('client_ref', 'fifty-first')->exists())->toBeFalse()
        ->and(Recording::query()->count())->toBe(ChunkedUploadService::MAX_OPEN_UPLOADS);
});

it('counts every artifact of a request against the limit', function () {
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS - 2);

    expectTooManyOpenUploads($this->postJson('/api/sessions', Fixtures::sessionBody($this->bytes, ['artifacts' => [
        Fixtures::artifact($this->bytes),
        Fixtures::artifact($this->bytes, ['role' => 'channel_1', 'mime' => 'audio/wav', 'channels' => 1]),
        Fixtures::artifact($this->bytes, ['role' => 'channel_2', 'mime' => 'audio/wav', 'channels' => 1]),
    ]])));
    expect(Recording::query()->count())->toBe(ChunkedUploadService::MAX_OPEN_UPLOADS - 2);
});

it('applies to finishing an open session, which stays open when refused', function () {
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS);
    $id = $this->openSession();

    expectTooManyOpenUploads($this->postJson("/api/sessions/{$id}/finish", Fixtures::finishBody($this->bytes)));

    expect(CaptureSession::query()->find($id)->status)->toBe(SessionStatus::Recording);
});

it('applies to adding a recording to a session', function () {
    $session = fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS);

    expectTooManyOpenUploads($this->postJson("/api/sessions/{$session->id}/recordings", Fixtures::artifact($this->bytes, ['role' => 'channel_1'])));
});

it('counts uploads that already received chunks', function () {
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS, UploadStatus::Uploading);

    expectTooManyOpenUploads($this->createSession($this->bytes));
});

it('does not count finished, assembling or failed uploads', function (UploadStatus $status) {
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS, $status);

    $this->createSession($this->bytes)->assertCreated();
})->with([UploadStatus::Ready, UploadStatus::Assembling, UploadStatus::Failed]);

it('counts per device, not per owner', function () {
    [$laptop] = $this->enroll($this->device->user, platform: 'windows');
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS, UploadStatus::Pending, $laptop->id);

    $this->createSession($this->bytes)->assertCreated();
});

it('frees a slot once an upload completes', function () {
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS - 1);
    $flow = $this->uploadRecording($this->bytes, ['client_ref' => 'finishes']);
    $flow['complete']->assertStatus(202);

    $this->createSession($this->bytes, ['client_ref' => 'fits-again'])->assertCreated();
});

it('still answers a replayed create of an existing session', function () {
    $first = $this->createSession($this->bytes, ['client_ref' => 'replayed'])->assertCreated();
    fillOpenUploads($this, ChunkedUploadService::MAX_OPEN_UPLOADS);

    $this->createSession($this->bytes, ['client_ref' => 'replayed'])->assertOk()->assertJsonPath('session.id', $first->json('session.id'));
});
