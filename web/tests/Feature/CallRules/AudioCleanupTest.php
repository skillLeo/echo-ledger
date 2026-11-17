<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\CallRules\AudioCleanup;
use App\Services\Entitlements\Entitlements;
use App\Services\RetentionPolicy;
use App\Services\SettingsRepository;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

/*
 * Audio clean-up (contract 2.1.0-a, docs/PLAN_CALL_RULES.md section 8): only audio-only, unstarred, unkept,
 * non-workspace recordings, after the admin's days; Never; Trash for trash.keep_hours; the warning 3 days before;
 * the weekly summary; changes apply to existing items; the person's own shorter choice.
 */

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    $this->owner = User::factory()->create();
    [$this->device, $this->token] = $this->enroll($this->owner);
    $this->plan = Plan::query()->create(['key' => 'cleanup_plan', 'name' => 'Cleanup plan', 'audio_retention_days' => 30]);
    Workspace::query()->whereKey($this->device->workspace_id)->update(['plan_id' => $this->plan->id]);
    app(Entitlements::class)->forget();
});

/** A recording of the device kept as audio $days days ago. */
function keptAudio(object $test, int $days, array $attributes = []): CaptureSession
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(3000), ['status' => 'completed', ...$attributes]);
    $session->forceFill(['choice' => 'keep_audio', 'choice_by' => 'user', 'choice_at' => now()->subDays($days)])->save();

    return $session->refresh();
}

function audioCleanup(): int
{
    app(Entitlements::class)->forget();

    return app(AudioCleanup::class)->run();
}

it('removes only audio-only recordings past their days, never transcribed, starred, kept or workspace ones', function () {
    $old = keptAudio($this, 31);
    $young = keptAudio($this, 29);
    $starred = keptAudio($this, 31, ['pinned' => true]);
    $kept = keptAudio($this, 31, ['keep_forever' => true]);
    $transcribed = keptAudio($this, 31);
    $transcribed->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'hi']);
    $legacy = $this->makeReadySession($this->device, Fixtures::audio(3000), ['status' => 'completed']); // no choice: made before 2.1.0-a
    CaptureSession::query()->whereKey($legacy->id)->update(['created_at' => now()->subDays(400)]);

    expect(audioCleanup())->toBe(1);

    expect(CaptureSession::query()->find($old->id))->toBeNull()
        ->and(CaptureSession::withTrashed()->find($old->id)->restorable())->toBeTrue();
    foreach ([$young, $starred, $kept, $transcribed, $legacy] as $session) {
        expect(CaptureSession::query()->find($session->id))->not->toBeNull();
    }
    expect(DB::table('call_choice_log')->where('outcome', 'cleaned')->count())->toBe(1)
        ->and(DB::table('call_choice_log')->where('outcome', 'deleted')->count())->toBe(0);
});

it('leaves a shared workspace\'s audio alone', function () {
    $old = keptAudio($this, 60);
    DB::table('workspace_user')->insert(['workspace_id' => $old->workspace_id, 'user_id' => User::factory()->create()->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);

    expect(audioCleanup())->toBe(0)->and(CaptureSession::query()->find($old->id))->not->toBeNull();
});

it('removes nothing on a plan that keeps audio for ever (Never), nor does the older retention', function () {
    $this->plan->update(['audio_retention_days' => null]);
    $old = keptAudio($this, 3000);
    CaptureSession::query()->whereKey($old->id)->update(['created_at' => now()->subDays(3000)]);

    expect(audioCleanup())->toBe(0);
    Artisan::call('recordings:retention');
    expect(CaptureSession::query()->find($old->id))->not->toBeNull();
});

it('applies a change by the admin, or the person\'s shorter choice, to recordings kept before it', function () {
    $tenDays = keptAudio($this, 10);
    expect(audioCleanup())->toBe(0);

    $this->plan->update(['audio_retention_days' => 7]);
    expect(audioCleanup())->toBe(1)->and(CaptureSession::query()->find($tenDays->id))->toBeNull();

    $this->plan->update(['audio_retention_days' => 30]);
    $fiveDays = keptAudio($this, 5);
    $this->owner->forceFill(['audio_retention_choice' => '7'])->save();
    expect(audioCleanup())->toBe(0);
    CaptureSession::query()->whereKey($fiveDays->id)->update(['choice_at' => now()->subDays(8)]);
    expect(audioCleanup())->toBe(1);
});

it('keeps what it removed in Trash for the admin\'s trash.keep_hours, then erases the audio for good', function () {
    app(SettingsRepository::class)->set('trash.keep_hours', 72);
    $old = keptAudio($this, 31);
    $path = Recording::query()->where('capture_session_id', $old->id)->value('stored_path');
    audioCleanup();

    CaptureSession::onlyTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subHours(50)]);
    app(RetentionPolicy::class)->emptyBin();
    expect(CaptureSession::withTrashed()->find($old->id)->media_erased_at)->toBeNull()
        ->and(Storage::disk('recordings')->exists($path))->toBeTrue();

    CaptureSession::onlyTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subHours(73)]);
    app(RetentionPolicy::class)->emptyBin();
    expect(CaptureSession::withTrashed()->find($old->id)->media_erased_at)->not->toBeNull()
        ->and(Recording::query()->where('capture_session_id', $old->id)->count())->toBe(0)
        ->and(Storage::disk('recordings')->exists($path))->toBeFalse();
});

it('warns three days before, on Home\'s count, and not earlier', function () {
    keptAudio($this, 26); // goes in 4 days (day 31)
    $this->asToken($this->token);
    expect($this->getJson('/api/library/views')->json('cleanup_soon'))->toBe(['count' => 0, 'on' => null]);

    keptAudio($this, 28); // goes in 2 days
    $soon = $this->getJson('/api/library/views')->json('cleanup_soon');
    expect($soon['count'])->toBe(1)->and($soon['on'])->not->toBeNull();
});

it('sends one quiet weekly summary per person, never one per recording', function () {
    keptAudio($this, 26);
    keptAudio($this, 27);
    keptAudio($this, 5); // not this week

    Artisan::call('recordings:audio-cleanup', ['--summary' => true]);
    Artisan::call('recordings:audio-cleanup', ['--summary' => true]);

    $entries = $this->owner->notifications()->get()->filter(fn ($n): bool => ActivityFeed::kindOf($n) === 'audio_cleanup');
    expect($entries)->toHaveCount(1)
        ->and($entries->first()->data['title'])->toStartWith('2 recordings will be removed on ');
});

it('runs once a day from the schedule', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e): bool => str_contains((string) $e->command, 'recordings:audio-cleanup'));

    expect($event)->not->toBeNull()->and($event->expression)->toBe('20 3 * * *');
});
