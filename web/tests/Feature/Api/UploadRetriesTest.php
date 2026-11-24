<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Filament\Pages\Activity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| Contract 2.0.0-r, BE-03 (docs/API.md "Upload retries and Retry all"): the apps retry a failed upload on their own 1,
| 5 and 30 minutes after it failed, then on a network change or app start; Retry all is a time on the person that the
| apps read with the Activity list, and act on once each. The server holds no file to send: it only asks.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'UTC'));
    Fx::fakeRecordingsDisk();
});

function retriesApi(User $user): object
{
    return test()->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])));
}

function failedUploadOf(User $owner, string $title): void
{
    $recording = Fx::pendingRecording(Fx::session($owner, null, ['title' => $title]), 'audio of '.$title);
    $recording->update(['upload_status' => UploadStatus::Failed]);
}

it('gives the apps the retry schedule in GET /api/config', function () {
    expect(retriesApi(Fx::user())->getJson('/api/config')->assertOk()->json('upload_retry'))
        ->toBe(['after_seconds' => [60, 300, 1800], 'then' => ['network_change', 'app_start']]);
});

it('asks the devices to retry with Retry all, and the Activity list and groups carry the time', function () {
    $me = Fx::user();
    failedUploadOf($me, 'Call one');
    failedUploadOf($me, 'Call two');
    $api = retriesApi($me);
    expect($api->getJson('/api/activity')->json('retry_requested_at'))->toBeNull();

    $api->postJson('/api/activity/retry-all', ['group' => 'upload_failed'])->assertStatus(202)
        ->assertExactJson(['retry_requested_at' => now()->toAtomString(), 'count' => 2]);

    expect($api->getJson('/api/activity')->json('retry_requested_at'))->toBe(now()->toAtomString())
        ->and($api->getJson('/api/activity/groups')->json('retry_requested_at'))->toBe(now()->toAtomString());

    // A later Retry all is newer, so every device runs again once.
    Carbon::setTestNow(now()->addMinutes(10));
    $api->postJson('/api/activity/retry-all', ['group' => 'upload_failed'])->assertStatus(202)->assertJsonPath('retry_requested_at', now()->toAtomString());
});

it('answers 202 with a count of 0 when nothing failed: the devices have nothing to do', function () {
    retriesApi(Fx::user())->postJson('/api/activity/retry-all', ['group' => 'upload_failed'])->assertStatus(202)->assertJsonPath('count', 0);
});

it('refuses any other group, and asks only the person\'s own devices', function () {
    [$me, $other] = [Fx::user(), Fx::user()];
    retriesApi($me)->postJson('/api/activity/retry-all', ['group' => 'budget'])->assertStatus(422);
    retriesApi($me)->postJson('/api/activity/retry-all', [])->assertStatus(422);

    retriesApi($me)->postJson('/api/activity/retry-all', ['group' => 'upload_failed'])->assertStatus(202);
    expect($me->fresh()->retry_requested_at?->toAtomString())->toBe(now()->toAtomString())
        ->and($other->fresh()->retry_requested_at)->toBeNull()
        ->and(retriesApi($other)->getJson('/api/activity')->json('retry_requested_at'))->toBeNull();
    retriesApi($me)->getJson('/api/config')->assertOk();
    test()->api(Fx::token(Fx::device($me), ['config:read']))->postJson('/api/activity/retry-all', ['group' => 'upload_failed'])->assertForbidden();
});

it('has Retry all on the console\'s Activity page while an upload failed, asking the same way', function () {
    $me = Fx::user();
    Filament::setCurrentPanel('app');
    $this->actingAs($me, 'web');
    Livewire::test(Activity::class)->assertActionHidden('retryAll');

    failedUploadOf($me, 'Call one');
    Livewire::test(Activity::class)->assertActionVisible('retryAll')->callAction('retryAll')
        ->assertNotified('Your devices will send 1 recording again.');
    expect($me->fresh()->retry_requested_at?->toAtomString())->toBe(now()->toAtomString());
});
