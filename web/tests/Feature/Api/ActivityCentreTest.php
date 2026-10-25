<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Models\CaptureSession;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\SettingsRepository;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/* The activity centre (B18): what makes a notification, the device API and the console bell. */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->owner = User::query()->findOrFail($this->device->user_id);
    $this->owner->notifications()->delete(); // the pairing above already made one
});

function kinds(User $user): array
{
    return $user->notifications()->oldest()->get()->map(fn (DatabaseNotification $n): ?string => ActivityFeed::kindOf($n))->all();
}

function usageRow(object $test, int $ms): void
{
    UsageLedgerEntry::query()->create([
        'user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'source' => 'device',
        'report_id' => 'r-'.bin2hex(random_bytes(6)), 'use_case' => 'live_captions', 'provider' => 'assemblyai',
        'duration_ms' => $ms, 'channels' => 1, 'billed_ms' => $ms, 'unit_price_usd' => 0.45, 'cost_usd' => 0.01,
        'billable' => true, 'occurred_at' => now(),
    ]);
}

describe('what makes a notification', function () {
    it('tells when a final transcript is ready or failed, not for live or other versions', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id, 'title' => 'Acme kickoff']);
        $final = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]);
        $session->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x']);
        $session->transcripts()->create(['kind' => TranscriptKind::Alternate, 'provider' => 'elevenlabs', 'status' => TranscriptStatus::Completed, 'text' => 'x']);
        expect(kinds($this->owner))->toBe([]);

        $final->update(['status' => TranscriptStatus::Completed, 'text' => 'done']);
        $final->update(['text' => 'edited']);
        $other = CaptureSession::factory()->create(['user_id' => $this->owner->id]);
        $other->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error' => 'The provider rejected the audio.']);

        expect(kinds($this->owner))->toEqualCanonicalizing(['transcript_ready', 'transcript_failed']);
        $ready = ActivityFeed::payload($this->owner->notifications()->get()->first(fn (DatabaseNotification $n): bool => ActivityFeed::kindOf($n) === 'transcript_ready'));
        expect($ready)->toMatchArray(['title' => 'Transcript ready', 'body' => 'Acme kickoff', 'session_id' => $session->id, 'read' => false]);
        $failed = $this->owner->notifications()->get()->first(fn (DatabaseNotification $n): bool => ActivityFeed::kindOf($n) === 'transcript_failed');
        expect($failed->data['body'])->toContain('The provider rejected the audio.');
    });

    it('tells when an upload failed and when a device was paired', function () {
        $session = $this->makeReadySession($this->device, Fixtures::audio(2000), ['title' => 'Late upload']);
        $session->recordings()->first()->update(['upload_status' => UploadStatus::Failed]);
        $this->enroll($this->owner, platform: 'android');

        expect(kinds($this->owner))->toEqualCanonicalizing(['upload_failed', 'device_paired']);
        $paired = $this->owner->notifications()->get()->first(fn (DatabaseNotification $n): bool => ActivityFeed::kindOf($n) === 'device_paired');
        expect($paired->data['title'])->toEndWith('paired');
    });

    it('warns at the monthly threshold and when the limit is reached, once each per month', function () {
        app(SettingsRepository::class)->set('budget.monthly_minutes', 10);
        usageRow($this, 60_000 * 5);
        expect(kinds($this->owner))->toBe([]);
        usageRow($this, 60_000 * 4);
        usageRow($this, 60_000 * 0 + 1_000);
        usageRow($this, 60_000 * 2);
        usageRow($this, 60_000);

        expect(kinds($this->owner))->toEqualCanonicalizing(['budget_warn', 'budget_reached']);
    });

    it('stays quiet about recordings in the undo bin', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id]);
        $final = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]);
        $session->delete();
        $final->update(['status' => TranscriptStatus::Completed]);

        expect(kinds($this->owner))->toBe([]);
    });
});

describe('the device API', function () {
    beforeEach(function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->owner->id, 'title' => 'One']);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x']);
        $this->travel(1)->seconds();
        $second = CaptureSession::factory()->create(['user_id' => $this->owner->id, 'title' => 'Two']);
        $second->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x']);
        app(ActivityFeed::class)->post(User::factory()->create(), 'device_paired', 'Someone else', 'x', null, 'heroicon-o-bell');
    });

    it('lists the owner\'s notifications newest first with the unread count', function () {
        $answer = $this->getJson('/api/activity')->assertOk();

        expect(array_column($answer->json('data'), 'body'))->toBe(['Two', 'One'])
            ->and($answer->json('unread_count'))->toBe(2)
            ->and($answer->json('data.0'))->toHaveKeys(['id', 'kind', 'title', 'body', 'session_id', 'read', 'at']);
        expect($this->getJson('/api/activity?limit=1')->json('data'))->toHaveCount(1);
    });

    it('marks some or all as read, never another owner\'s', function () {
        $first = $this->getJson('/api/activity')->json('data.1.id');
        $this->postJson('/api/activity/read', ['ids' => [$first]])->assertOk()->assertExactJson(['unread_count' => 1]);
        $this->postJson('/api/activity/read')->assertOk()->assertExactJson(['unread_count' => 0]);
        expect(DatabaseNotification::query()->whereNull('read_at')->count())->toBe(1);
        $this->postJson('/api/activity/read', ['ids' => ['not-a-uuid']])->assertStatus(422);
    });
});

describe('the console bell', function () {
    it('stores a Filament notification that opens the recording', function () {
        $admin = $this->signInAsAdmin();
        $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'title' => 'Acme kickoff']);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x']);

        $data = $admin->notifications()->sole()->data;
        expect($data['format'])->toBe('filament')->and($data['title'])->toBe('Transcript ready')
            ->and($data['actions'][0]['url'])->toContain($session->id);
        expect(Filament\Facades\Filament::getPanel('admin')->hasDatabaseNotifications())->toBeTrue();
    });

    it('forgets old notifications', function () {
        app(ActivityFeed::class)->post($this->owner, 'device_paired', 'Old read', 'x', null, 'heroicon-o-bell');
        app(ActivityFeed::class)->post($this->owner, 'device_paired', 'Old unread', 'x', null, 'heroicon-o-bell');
        app(ActivityFeed::class)->post($this->owner, 'device_paired', 'Fresh', 'x', null, 'heroicon-o-bell');
        DatabaseNotification::query()->where('data', 'like', '%Old read%')->update(['created_at' => now()->subDays(91), 'read_at' => now()->subDays(90)]);
        DatabaseNotification::query()->where('data', 'like', '%Old unread%')->update(['created_at' => now()->subDays(181)]);

        expect(ActivityFeed::prune())->toBe(2)->and($this->owner->notifications()->sole()->data['title'])->toBe('Fresh');
    });
});
