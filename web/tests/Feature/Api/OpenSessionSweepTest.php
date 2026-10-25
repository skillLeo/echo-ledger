<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Services\ChunkedUploadService;
use Illuminate\Support\Facades\Artisan;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll(platform: 'windows');
    $this->asToken($token);
    $this->freezeTime();
});

/** An open session (status `recording`) of the test device, opened $minutesAgo minutes ago. */
function openSessionAged(object $test, int $minutesAgo, array $attributes = []): CaptureSession
{
    $at = now()->subMinutes($minutesAgo);

    return CaptureSession::factory()->create(array_merge([
        'user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'status' => SessionStatus::Recording,
        'created_at' => $at, 'updated_at' => $at,
    ], $attributes));
}

it('keeps an open session younger than six hours and sweeps one of six hours', function () {
    $young = openSessionAged($this, 6 * 60 - 1);
    $old = openSessionAged($this, 6 * 60);

    Artisan::call('sessions:sweep-open');

    expect(CaptureSession::query()->find($young->id))->not->toBeNull()
        ->and(CaptureSession::query()->find($old->id))->toBeNull()
        ->and(CaptureSession::onlyTrashed()->find($old->id))->not->toBeNull();
});

it('keeps an old open session that already has a recording', function () {
    $session = openSessionAged($this, 12 * 60);
    app(ChunkedUploadService::class)->createRecording($session, Fixtures::artifact(Fixtures::audio(2000)), 1024 * 1024);

    Artisan::call('sessions:sweep-open');

    expect(CaptureSession::query()->find($session->id))->not->toBeNull();
});

it('never sweeps sessions that left the recording state', function (SessionStatus $status) {
    $session = openSessionAged($this, 24 * 60, ['status' => $status]);

    Artisan::call('sessions:sweep-open');

    expect(CaptureSession::query()->find($session->id))->not->toBeNull();
})->with([SessionStatus::Uploading, SessionStatus::Processing, SessionStatus::Completed, SessionStatus::Failed]);

it('honours a different age', function () {
    $session = openSessionAged($this, 3 * 60);

    Artisan::call('sessions:sweep-open', ['--hours' => 2]);

    expect(CaptureSession::query()->find($session->id))->toBeNull();
});

it('turns a swept session into a tombstone that the next sync delivers', function () {
    $session = openSessionAged($this, 7 * 60, ['client_ref' => 'crashed-recording']);
    $first = $this->getJson('/api/library')->assertOk();
    expect($first->json('data'))->toBe([]); // open sessions are not listed

    $this->travel(1)->minutes();
    Artisan::call('sessions:sweep-open');

    $next = $this->getJson('/api/library?since='.urlencode($first->json('server_time')))->assertOk();
    expect($next->json('data'))->toHaveCount(1)
        ->and($next->json('data.0'))->toMatchArray(['id' => $session->id, 'client_ref' => 'crashed-recording', 'deleted' => true]);
    // The device that still holds the id learns it is gone when it tries to finish.
    $this->postJson("/api/sessions/{$session->id}/finish", Fixtures::finishBody(Fixtures::audio(1000)))
        ->assertNotFound()->assertJsonPath('code', 'session_not_found');
});

it('is scheduled every fifteen minutes', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('#\*/15 \* \* \* \*\s+php artisan sessions:sweep-open#');
});
