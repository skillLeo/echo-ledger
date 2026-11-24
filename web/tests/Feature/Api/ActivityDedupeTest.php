<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Models\Recording;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActionBadge;
use App\Services\ActivityFeed;
use App\Services\ChunkedUploadService;
use App\Services\Workspaces\Workspaces;
use Filament\Notifications\DatabaseNotification;
use Illuminate\Database\QueryException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| Contract 2.0.0-r, BE-02 (docs/API.md "Activity: one item per recording and event"): one Activity item per person,
| recording and kind, updated when it happens again (`repeat_count`), upload failures in one group
| (GET /api/activity/groups), and resolved once the recording reaches the server. The items already there are merged
| once by the migration, which changes nothing when it runs again.
*/

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-24 10:00:00', 'UTC'));
    Fx::fakeRecordingsDisk();
});

/** A recording of [$owner]'s whose upload fails (the listener posts "Upload failed — retry"). */
function failingUpload(User $owner, string $title): Recording
{
    $session = Fx::session($owner, null, ['title' => $title]);
    $recording = Fx::pendingRecording($session, 'audio of '.$title);
    $recording->update(['upload_status' => UploadStatus::Failed]);

    return $recording;
}

function failAgain(Recording $recording): void
{
    $recording->update(['upload_status' => UploadStatus::Uploading]);
    $recording->update(['upload_status' => UploadStatus::Failed]);
}

function activityOf(User $user, string $path = '/api/activity?limit=100'): array
{
    return test()->api(Fx::token(Fx::device($user, ['workspace_id' => $user->current_workspace_id])))->getJson($path)->assertOk()->json();
}

it('gives one group of 3 when the same 3 uploads fail twice, each item counted twice', function () {
    $me = Fx::user();
    $recordings = [failingUpload($me, 'Call one'), failingUpload($me, 'Call two'), failingUpload($me, 'Call three')];
    Carbon::setTestNow(now()->addMinutes(5));
    array_map(failAgain(...), $recordings);

    $items = collect(activityOf($me)['data'])->where('kind', 'upload_failed');
    expect($items)->toHaveCount(3)
        ->and($items->pluck('repeat_count')->unique()->values()->all())->toBe([2])
        ->and($items->pluck('group')->unique()->values()->all())->toBe(['upload_failed'])
        ->and($items->pluck('resolved')->unique()->values()->all())->toBe([false]);

    $groups = activityOf($me, '/api/activity/groups')['data'];
    expect($groups)->toHaveCount(1)
        ->and($groups[0])->toMatchArray(['group' => 'upload_failed', 'count' => 3, 'unread' => 3, 'at' => now()->toAtomString()])
        ->and(collect($groups[0]['session_ids'])->sort()->values()->all())->toBe(collect($recordings)->pluck('capture_session_id')->sort()->values()->all());
    expect(DB::table('notifications')->where('dedupe_key', 'like', 'upload_failed:%')->count())->toBe(3);
});

it('updates the item when it happens again: newest words and time, unread again, at the top', function () {
    $me = Fx::user();
    $first = failingUpload($me, 'Payment terms');
    $other = failingUpload($me, 'Other call');
    $me->unreadNotifications()->get()->each->markAsRead();
    $first->captureSession->update(['title' => 'Payment terms, renamed']);
    Carbon::setTestNow(now()->addHour());
    failAgain($first);

    $items = collect(activityOf($me)['data'])->where('kind', 'upload_failed')->values()->all();
    expect($items[0])->toMatchArray(['session_id' => $first->capture_session_id, 'read' => false, 'repeat_count' => 2, 'at' => now()->toAtomString()])
        ->and($items[0]['body'])->toContain('Payment terms, renamed')
        ->and(collect($items)->firstWhere('session_id', $other->capture_session_id))->toMatchArray(['read' => true, 'repeat_count' => 1]);
});

it('never merges items without a recording', function () {
    $me = Fx::user();
    foreach ([1, 2] as $i) {
        app(ActivityFeed::class)->post($me, 'budget_reached', "Limit {$i}", 'Body', null, 'heroicon-o-no-symbol');
    }
    Fx::device($me);
    Fx::device($me);

    $kinds = collect(activityOf($me)['data'])->countBy('kind')->all();
    expect($kinds['budget_reached'])->toBe(2)->and($kinds['device_paired'])->toBeGreaterThanOrEqual(2);
});

it('resolves an upload failure once its recording reaches the server: it reads Uploaded, is read and leaves the group', function () {
    $me = Fx::user();
    $done = failingUpload($me, 'Sent in the end');
    failingUpload($me, 'Still failing');

    $plain = 'audio of Sent in the end';
    $path = app(ChunkedUploadService::class)->tempPath('test');
    file_put_contents($path, $plain);
    $done->update(['upload_status' => UploadStatus::Assembling]);
    app(ChunkedUploadService::class)->storeEncrypted($done->fresh(), $path);
    @unlink($path);

    $item = collect(activityOf($me)['data'])->firstWhere('session_id', $done->capture_session_id);
    expect($item)->toMatchArray(['kind' => 'upload_failed', 'title' => 'Uploaded', 'read' => true, 'resolved' => true]);
    $groups = activityOf($me, '/api/activity/groups')['data'];
    expect($groups[0])->toMatchArray(['count' => 1, 'session_ids' => [Recording::query()->where('id', '!=', $done->id)->value('capture_session_id')]]);
    expect(app(ActionBadge::class)->count($me, (int) $me->current_workspace_id))->toBe(1);

    // It failing again later is a new failure: unresolved and unread again.
    failAgain($done->fresh());
    expect(collect(activityOf($me)['data'])->firstWhere('session_id', $done->capture_session_id))->toMatchArray(['resolved' => false, 'read' => false, 'repeat_count' => 2]);
});

it('lists one group\'s items with ?group=, and refuses a group it does not know', function () {
    $me = Fx::user();
    failingUpload($me, 'A call');
    app(ActivityFeed::class)->post($me, 'budget_reached', 'Limit', 'Body', null, 'heroicon-o-no-symbol');

    expect(collect(activityOf($me, '/api/activity?group=upload_failed')['data'])->pluck('kind')->all())->toBe(['upload_failed']);
    test()->api(Fx::token(Fx::device($me)))->getJson('/api/activity?group=budget')->assertStatus(422);
    expect(activityOf(Fx::user(), '/api/activity/groups')['data'])->toBe([]);
});

it('keeps each person\'s items apart: a teammate\'s and another workspace\'s failures never show', function () {
    $me = Fx::user();
    $teammate = withoutWorkspace(Fx::user());
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($me->current_workspace_id), $teammate);
    $teammate->forceFill(['current_workspace_id' => $me->current_workspace_id])->save();
    $stranger = Fx::user();
    failingUpload($teammate->fresh(), 'Teammate call');
    failingUpload($stranger, 'Stranger call');
    failingUpload($me, 'My call');

    expect(activityOf($me, '/api/activity/groups')['data'][0]['count'])->toBe(1)
        ->and(collect(activityOf($me)['data'])->pluck('body')->implode(' '))->not->toContain('Teammate call')->not->toContain('Stranger call');
});

it('merges the items already there once, newest kept, unread when any was, and changes nothing the second time', function () {
    $me = Fx::user();
    $other = Fx::user();
    $session = Fx::session($me, null, ['title' => 'Old call']);
    // Its upload still failed (written without the listener), so the merge keeps it unresolved.
    DB::table('recordings')->where('id', Fx::pendingRecording($session, 'audio')->id)->update(['upload_status' => 'failed']);
    $raw = function (User $user, string $kind, ?string $sessionId, string $at, ?string $readAt, string $title) {
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => DatabaseNotification::class,
            'notifiable_type' => $user->getMorphClass(), 'notifiable_id' => $user->id, 'read_at' => $readAt, 'created_at' => $at, 'updated_at' => $at,
            'data' => json_encode(['title' => $title, 'body' => 'Body', 'viewData' => ['kind' => $kind, 'session_id' => $sessionId]])]);
    };
    $raw($me, 'upload_failed', $session->id, '2026-11-20 09:00:00', null, 'Oldest');
    $raw($me, 'upload_failed', $session->id, '2026-11-22 09:00:00', '2026-11-22 10:00:00', 'Newest');
    $raw($me, 'upload_failed', $session->id, '2026-11-21 09:00:00', '2026-11-21 10:00:00', 'Middle');
    $raw($me, 'transcript_ready', $session->id, '2026-11-21 09:00:00', null, 'Ready');
    $raw($me, 'budget_reached', null, '2026-11-21 09:00:00', null, 'Limit');
    $raw($me, 'budget_reached', null, '2026-11-22 09:00:00', null, 'Limit again');
    $raw($other, 'upload_failed', $session->id, '2026-11-22 09:00:00', null, 'Not mine');

    $migration = require database_path('migrations/2026_12_30_000001_add_activity_dedupe_to_notifications.php');
    $migration->up();
    $after = DB::table('notifications')->orderBy('created_at')->get(['notifiable_id', 'data', 'read_at', 'dedupe_key', 'repeat_count']);

    $mine = $after->where('notifiable_id', $me->id);
    $failed = $mine->firstWhere('dedupe_key', 'upload_failed:'.$session->id);
    expect($mine)->toHaveCount(4)
        ->and(json_decode($failed->data, true)['title'])->toBe('Newest')
        ->and($failed->repeat_count)->toBe(3)
        ->and($failed->read_at)->toBeNull()
        ->and($mine->whereNull('dedupe_key')->count())->toBe(2)
        ->and($after->where('notifiable_id', $other->id)->first()->repeat_count)->toBe(1);

    $migration->up();
    expect(DB::table('notifications')->orderBy('created_at')->get(['notifiable_id', 'data', 'read_at', 'dedupe_key', 'repeat_count'])->all())->toEqual($after->all());
});

it('resolves the upload failures the migration finds already over, and leaves a real one; twice changes nothing', function () {
    $me = Fx::user();
    $gone = Fx::session($me, null, ['title' => 'Gone']);
    $binned = Fx::session($me, null, ['title' => 'In the bin']);
    $uploaded = Fx::session($me, null, ['title' => 'Uploaded since']);
    $failing = Fx::session($me, null, ['title' => 'Still failing']);
    Fx::pendingRecording($failing, 'audio')->update(['upload_status' => UploadStatus::Failed]);
    DB::table('notifications')->delete();
    foreach ([$gone, $binned, $uploaded, $failing] as $session) {
        DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => DatabaseNotification::class,
            'notifiable_type' => $me->getMorphClass(), 'notifiable_id' => $me->id, 'read_at' => null, 'created_at' => now(), 'updated_at' => now(),
            'data' => json_encode(['title' => 'Upload failed — retry', 'body' => 'b', 'viewData' => ['kind' => 'upload_failed', 'session_id' => $session->id]])]);
    }
    DB::table('capture_sessions')->where('id', $gone->id)->delete();
    $binned->delete();
    DB::table('notifications')->where('notifiable_id', $me->id)->update(['resolved_at' => null, 'read_at' => null]);

    $migration = require database_path('migrations/2026_12_30_000001_add_activity_dedupe_to_notifications.php');
    $migration->up();
    $state = fn (): array => DB::table('notifications')->where('notifiable_id', $me->id)->get(['dedupe_key', 'resolved_at', 'read_at'])
        ->mapWithKeys(fn ($row): array => [substr((string) $row->dedupe_key, 14) => [$row->resolved_at !== null, $row->read_at !== null]])->all();

    expect($state())->toBe([$gone->id => [true, true], $binned->id => [true, true], $uploaded->id => [true, true], $failing->id => [false, false]]);
    $before = DB::table('notifications')->orderBy('id')->get()->all();
    $migration->up();
    expect(DB::table('notifications')->orderBy('id')->get()->all())->toEqual($before)
        ->and(activityOf($me, '/api/activity/groups')['data'][0]['session_ids'])->toBe([$failing->id]);
});

it('resolves an upload failure when its recording is deleted, and only once no upload of it is still failed', function () {
    $me = Fx::user();
    $deleted = failingUpload($me, 'Deleted after');
    $deleted->captureSession->delete();
    expect(collect(activityOf($me)['data'])->firstWhere('session_id', $deleted->capture_session_id))->toMatchArray(['resolved' => true, 'read' => true]);

    // Two artifacts of one recording failed: the first one arriving does not resolve it, the second does.
    $first = failingUpload($me, 'Two parts');
    $second = Fx::pendingRecording($first->captureSession, 'second part', 1024, 'channel_2');
    $second->update(['upload_status' => UploadStatus::Failed]);
    $path = app(ChunkedUploadService::class)->tempPath('test');
    foreach ([[$first, 'audio of Two parts', false], [$second, 'second part', true]] as [$recording, $plain, $resolved]) {
        file_put_contents($path, $plain);
        $recording->update(['upload_status' => UploadStatus::Assembling]);
        app(ChunkedUploadService::class)->storeEncrypted($recording->fresh(), $path);
        expect(collect(activityOf($me)['data'])->firstWhere('session_id', $first->capture_session_id)['resolved'])->toBe($resolved);
    }
    @unlink($path);
});

it('reports a failed write without its words: the class and the SQLSTATE only', function () {
    Exceptions::fake();
    $me = Fx::user();
    $session = Fx::session($me, null, ['title' => 'Confidential supplier call']);
    Schema::rename('notifications', 'notifications_away');
    try {
        app(ActivityFeed::class)->post($me, 'transcript_failed', 'Transcription failed', 'Confidential supplier call', $session, 'heroicon-o-bell');
    } finally {
        Schema::rename('notifications_away', 'notifications');
    }

    Exceptions::assertReported(fn (RuntimeException $e): bool => str_contains($e->getMessage(), 'QueryException (SQLSTATE')
        && ! str_contains($e->getMessage(), 'Confidential'));
    Exceptions::assertNotReported(QueryException::class);
});

it('writes nothing of a recording to the log while it merges, groups and resolves', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    $me = Fx::user();
    $recording = failingUpload($me, 'Secret supplier call');
    failAgain($recording);
    activityOf($me, '/api/activity/groups');

    expect(implode("\n", $logged))->not->toContain('Secret supplier call');
});
