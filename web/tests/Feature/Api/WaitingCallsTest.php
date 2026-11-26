<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Library\LibraryFilters;
use App\Services\LibraryOrganiser;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

/*
 * Contract 2.0.0-z, decision 20: a call waits for the person's choice only once it is over (ended_at, sent by
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

/**
 * What every app reads. In SQL: the badge, the waiting count, the views' count and list, and Needs you. In PHP: the
 * item's state and its Needs you reason. All of them are the one rule (LibraryFilters::isWaiting / ::whereWaiting).
 */
function waitingAsSeen(object $test, CaptureSession $session): array
{
    $item = collect($test->getJson('/api/library?view=waiting')->assertOk()->json('data'))->firstWhere('id', $session->id);
    $needsYou = collect($test->getJson('/api/library?view=needs_you')->assertOk()->json('data'))->firstWhere('id', $session->id);
    $fresh = $session->fresh();

    return [
        'badge' => (int) $test->getJson('/api/badge')->assertOk()->json('count'),
        'count' => app(LibraryOrganiser::class)->waitingCount((int) $session->workspace_id),
        'views' => $test->getJson('/api/library/views')->assertOk()->json('views.waiting'),
        'view' => $item !== null,
        'needs_you' => $needsYou !== null,
        'needs_you_php' => LibraryFilters::needsYou($fresh->status->value, 'processing', $fresh->ended_at?->getTimestamp(),
            $fresh->started_at->getTimestamp(), now()->getTimestamp()) === 'waiting',
        'state' => LibraryFilters::itemFacets($fresh, false, null, null)['state'],
    ];
}

/** Nothing of a call waits: every place says so. */
const CALL_NOT_WAITING = ['badge' => 0, 'count' => 0, 'views' => 0, 'view' => false, 'needs_you' => false, 'needs_you_php' => false, 'state' => 'waiting_to_upload'];

/** The call waits for the person's choice: every place says so. */
const CALL_WAITING = ['badge' => 1, 'count' => 1, 'views' => 1, 'view' => true, 'needs_you' => true, 'needs_you_php' => true, 'state' => 'waiting'];

it('raises nothing for a call still being recorded with captions on', function () {
    $call = openLiveCall($this);

    expect(waitingAsSeen($this, $call))->toBe(CALL_NOT_WAITING);
});

it('waits for the choice once the call is over: the app sends ended_at', function () {
    $call = openLiveCall($this);

    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => now()->toIso8601ZuluString(), 'duration_ms' => 180000])->assertOk()
        ->assertJsonPath('data.status', 'recording');

    expect(waitingAsSeen($this, $call))->toBe(CALL_WAITING)->and($call->fresh()->duration_ms)->toBe(180000);
});

it('counts a call with no ended_at once it started more than 4 hours ago (older apps)', function () {
    $old = openLiveCall($this, ['started_at' => now()->subHours(5)]);

    expect(waitingAsSeen($this, $old))->toBe(CALL_WAITING);
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

it('draws the 4-hour line the same in SQL and in PHP, to the second', function () {
    Carbon::setTestNow(Carbon::parse('2026-11-25 12:00:00', 'UTC'));
    $call = openLiveCall($this, ['started_at' => now()->subHours(4)]);

    // Exactly 4 hours: still being recorded, in the badge's SQL and in the item's PHP alike.
    expect(waitingAsSeen($this, $call))->toBe(CALL_NOT_WAITING);

    Carbon::setTestNow(now()->addSecond());
    expect(waitingAsSeen($this, $call))->toBe(CALL_WAITING);
    Carbon::setTestNow();
});

it('refuses an end more than 5 minutes ahead, and a length out of range, and stores neither', function () {
    $call = openLiveCall($this);

    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => now()->addMinutes(6)->toIso8601ZuluString()])->assertStatus(422)
        ->assertJsonPath('error_codes.ended_at.0.code', 'invalid');
    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => 1764072000])->assertStatus(422);
    $this->patchJson("/api/sessions/{$call->id}", ['duration_ms' => -1])->assertStatus(422)->assertJsonValidationErrors('duration_ms');
    $this->patchJson("/api/sessions/{$call->id}", ['duration_ms' => 86400001])->assertStatus(422)->assertJsonValidationErrors('duration_ms');
    expect($call->fresh()->only(['ended_at', 'duration_ms']))->toBe(['ended_at' => null, 'duration_ms' => null]);

    // A device clock a little fast is fine; a length sent as text is stored as a number.
    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => now()->addMinutes(4)->toIso8601ZuluString(), 'duration_ms' => '86400000'])->assertOk();
    expect($call->fresh()->duration_ms)->toBe(86400000);
});

it('answers not_recording for a call the person deleted, and 404 for anything else sent to it', function () {
    $call = openLiveCall($this);
    $this->deleteJson("/api/sessions/{$call->id}")->assertSuccessful();

    $this->patchJson("/api/sessions/{$call->id}", ['ended_at' => now()->toIso8601ZuluString()])->assertStatus(409)->assertJson(['code' => 'not_recording']);
    $this->patchJson("/api/sessions/{$call->id}", ['duration_ms' => 1000])->assertStatus(409)->assertJson(['code' => 'not_recording']);
    $this->patchJson("/api/sessions/{$call->id}", ['title' => 'Renamed'])->assertNotFound()->assertJson(['code' => 'session_not_found']);
    expect(CaptureSession::withTrashed()->findOrFail($call->id)->only(['ended_at', 'duration_ms']))->toBe(['ended_at' => null, 'duration_ms' => null]);
});

it('answers 404 for a teammate\'s live call in the same workspace, deleted or not, and changes nothing', function () {
    $mate = Fx::user();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
    $theirs = Fx::session($mate, Fx::device($mate, ['workspace_id' => $this->device->workspace_id]),
        ['workspace_id' => $this->device->workspace_id, 'status' => SessionStatus::Recording, 'ended_at' => null, 'duration_ms' => null]);

    $this->patchJson("/api/sessions/{$theirs->id}", ['ended_at' => now()->toIso8601ZuluString(), 'duration_ms' => 1000])->assertNotFound()
        ->assertJson(['code' => 'session_not_found']);
    $theirs->delete();
    $this->patchJson("/api/sessions/{$theirs->id}", ['ended_at' => now()->toIso8601ZuluString()])->assertNotFound();
    expect(CaptureSession::withoutGlobalScopes()->findOrFail($theirs->id)->only(['ended_at', 'duration_ms']))->toBe(['ended_at' => null, 'duration_ms' => null]);
});
