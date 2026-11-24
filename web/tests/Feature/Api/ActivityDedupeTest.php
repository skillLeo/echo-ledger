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
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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
