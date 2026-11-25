<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Library\LibraryFilters;
use App\Services\LibraryOrganiser;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

/*
 * Contract 2.0.0-z, lead decision 20: a call waits for the person's choice only once it is over (ended_at, sent by
 * PATCH /api/sessions/{id} when it ends) or started more than 4 hours ago. A call still being recorded, captions on,
 * raises no badge, is not in Needs you and is not `waiting`.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
    $this->person = User::query()->findOrFail($this->device->user_id);
});

/** An open session as an app makes it at the start of a call (no ended_at), with live captions on. */
function openLiveCall(object $test, array $state = []): CaptureSession
{
    $session = CaptureSession::factory()->create(['user_id' => $test->person->id, 'workspace_id' => $test->device->workspace_id,
        'device_id' => $test->device->id, 'status' => SessionStatus::Recording, 'started_at' => now()->subMinutes(3), 'ended_at' => null,
        'duration_ms' => null, ...$state]);
    Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Live, 'provider' => 'deepgram',
        'status' => 'completed', 'language' => 'en', 'text' => 'still talking']);

    return $session;
}

/** What every app reads: the badge, the waiting view, Needs you, and the item's state. */
function waitingAsSeen(object $test, CaptureSession $session): array
{
    $item = collect($test->getJson('/api/library?view=waiting')->assertOk()->json('data'))->firstWhere('id', $session->id);
    $needsYou = collect($test->getJson('/api/library?view=needs_you')->assertOk()->json('data'))->firstWhere('id', $session->id);

    return [
        'badge' => (int) $test->getJson('/api/badge')->assertOk()->json('count'),
        'count' => app(LibraryOrganiser::class)->waitingCount((int) $session->workspace_id),
        'view' => $item !== null,
        'needs_you' => $needsYou !== null,
        'state' => LibraryFilters::itemFacets($session->fresh(), false, null, null)['state'],
    ];
}

it('raises nothing for a call still being recorded with captions on', function () {
    $call = openLiveCall($this);

    expect(waitingAsSeen($this, $call))->toBe(['badge' => 0, 'count' => 0, 'view' => false, 'needs_you' => false, 'state' => 'waiting_to_upload']);
});

it('waits for the choice once the call is over: the app sends ended_at', function () {
    $call = openLiveCall($this);

    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => now()->toIso8601ZuluString(), 'duration_ms' => 180000])->assertOk()
        ->assertJsonPath('data.status', 'recording');

    expect(waitingAsSeen($this, $call))->toBe(['badge' => 1, 'count' => 1, 'view' => true, 'needs_you' => true, 'state' => 'waiting'])
        ->and($call->fresh()->duration_ms)->toBe(180000);
});

it('counts a call with no ended_at once it started more than 4 hours ago (older apps)', function () {
    $old = openLiveCall($this, ['started_at' => now()->subHours(5)]);

    expect(waitingAsSeen($this, $old))->toBe(['badge' => 1, 'count' => 1, 'view' => true, 'needs_you' => true, 'state' => 'waiting']);
    expect(LibraryFilters::WAITING_FALLBACK_HOURS)->toBe(4);
});

it('keeps the first end and length: a later PATCH never moves them', function () {
    $call = openLiveCall($this);
    $first = now()->subMinute()->startOfSecond();

    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => $first->toIso8601ZuluString(), 'duration_ms' => 120000])->assertOk();
    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => now()->addMinute()->toIso8601ZuluString(), 'duration_ms' => 999])->assertOk();

    $fresh = $call->fresh();
    expect($fresh->ended_at->equalTo($first))->toBeTrue()->and($fresh->duration_ms)->toBe(120000)
        ->and($fresh->status)->toBe(SessionStatus::Recording);
});

it('answers not_recording once the session is finished, and changes nothing', function () {
    $done = openLiveCall($this, ['status' => SessionStatus::Uploading]);

    $this->patchJson("/api/sessions/{$done->id}", ['ended_at' => now()->toIso8601ZuluString()])->assertStatus(409)->assertJson(['code' => 'not_recording']);
    expect($done->fresh()->ended_at)->toBeNull();
});

it('refuses an end before the start, and another person\'s session', function () {
    $call = openLiveCall($this);
    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => now()->subHour()->toIso8601ZuluString()])->assertStatus(422)
        ->assertJsonPath('error_codes.ended_at.0.code', 'invalid');

    $other = Fx::user();
    $theirs = Fx::session($other, Fx::device($other), ['status' => SessionStatus::Recording, 'ended_at' => null]);
    $this->patchJson("/api/sessions/{$theirs->id}", ['ended_at' => now()->toIso8601ZuluString()])->assertNotFound();
    expect($theirs->fresh()->ended_at)->toBeNull();
});
