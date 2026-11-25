<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\SentItem;
use App\Models\SessionOpen;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "POST / DELETE /api/sessions/opened" (contract 2.0.0-y): Mark as read / unread for up to 200 recordings
 * in one call, each id as the single routes do it, the ones the person cannot read listed back as not found.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
    $this->person = User::query()->findOrFail($this->device->user_id);
    $this->mine = CaptureSession::factory()->count(3)->create(['user_id' => $this->person->id, 'workspace_id' => $this->device->workspace_id])
        ->pluck('id')->all();
});

function unreadNow(object $test): int
{
    return (int) $test->getJson('/api/library/facets')->assertOk()->json('library.unread');
}

function openedOf(object $test): array
{
    return collect($test->getJson('/api/library')->assertOk()->json('data'))->pluck('opened', 'id')->all();
}

it('marks many read and unread in one call each, the library counting them', function () {
    $before = unreadNow($this);

    $this->postJson('/api/sessions/opened', ['ids' => $this->mine])->assertOk()->assertExactJson(['done' => $this->mine, 'not_found' => []]);
    expect(unreadNow($this))->toBe($before - 3)
        ->and(array_values(array_intersect_key(openedOf($this), array_flip($this->mine))))->toBe([true, true, true]);

    $this->deleteJson('/api/sessions/opened', ['ids' => [$this->mine[0], $this->mine[2]]])->assertOk()
        ->assertExactJson(['done' => [$this->mine[0], $this->mine[2]], 'not_found' => []]);
    expect(unreadNow($this))->toBe($before - 1)
        ->and(openedOf($this)[$this->mine[1]])->toBeTrue()->and(openedOf($this)[$this->mine[0]])->toBeFalse();
});

it('lists back what the person cannot read, and never marks it: another workspace\'s, a teammate\'s, the bin, none at all', function () {
    $foreign = CaptureSession::factory()->create()->id;
    $mate = User::factory()->create();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
    $teammates = CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id])->id;
    $binned = CaptureSession::factory()->create(['user_id' => $this->person->id, 'workspace_id' => $this->device->workspace_id]);
    $binned->delete();
    $nobody = (string) Str::ulid();
    app('auth')->forgetGuards();

    $ids = [$this->mine[0], $foreign, $teammates, $binned->id, $nobody, 'not-an-id'];
    $this->postJson('/api/sessions/opened', ['ids' => $ids])->assertOk()
        ->assertExactJson(['done' => [$this->mine[0]], 'not_found' => [$foreign, $teammates, $binned->id, $nobody, 'not-an-id']]);
    $this->deleteJson('/api/sessions/opened', ['ids' => $ids])->assertOk()->assertJsonPath('not_found', [$foreign, $teammates, $binned->id, $nobody, 'not-an-id']);

    expect(SessionOpen::query()->whereIn('capture_session_id', [$foreign, $teammates, $binned->id])->count())->toBe(0);
});

it('marks a recording a teammate sent, as the single route does', function () {
    $mate = User::factory()->create();
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($this->device->workspace_id), $mate);
    $sent = CaptureSession::factory()->create(['user_id' => $mate->id, 'workspace_id' => $this->device->workspace_id]);
    SentItem::query()->create(['workspace_id' => $sent->workspace_id, 'from_user_id' => $mate->id, 'to_user_id' => $this->person->id,
        'kind' => 'session', 'capture_session_id' => $sent->id, 'title' => 'Sent', 'sent_at' => now()]);
    app('auth')->forgetGuards();

    $this->postJson('/api/sessions/opened', ['ids' => [$sent->id]])->assertOk()->assertExactJson(['done' => [$sent->id], 'not_found' => []]);
    $this->postJson("/api/sessions/{$sent->id}/opened")->assertNoContent();
});

it('is idempotent, counts an id sent twice once, and keeps the order it was sent in', function () {
    $ids = [$this->mine[2], $this->mine[0], $this->mine[2]];

    foreach (range(1, 2) as $_) {
        $this->postJson('/api/sessions/opened', ['ids' => $ids])->assertOk()->assertExactJson(['done' => [$this->mine[2], $this->mine[0]], 'not_found' => []]);
    }
    expect(SessionOpen::query()->where('user_id', $this->person->id)->count())->toBe(2);
    foreach (range(1, 2) as $_) {
        // Never opened (mine[1]) or already unread: done all the same.
        $this->deleteJson('/api/sessions/opened', ['ids' => $this->mine])->assertOk()->assertExactJson(['done' => $this->mine, 'not_found' => []]);
    }
    expect(SessionOpen::query()->where('user_id', $this->person->id)->count())->toBe(0);
});

it('takes back only the asking person\'s own opens, and never touches a recording', function () {
    $mate = User::factory()->create();
    SessionOpen::mark($this->mine[0], $mate->id);
    $this->postJson('/api/sessions/opened', ['ids' => $this->mine])->assertOk();
    $updated = CaptureSession::query()->whereKey($this->mine)->pluck('updated_at', 'id')->map(fn ($at) => (string) $at)->all();
    $this->travel(1)->minutes();

    $this->deleteJson('/api/sessions/opened', ['ids' => $this->mine])->assertOk();
    $this->postJson('/api/sessions/opened', ['ids' => $this->mine])->assertOk();

    expect(SessionOpen::query()->where('capture_session_id', $this->mine[0])->where('user_id', $mate->id)->exists())->toBeTrue()
        ->and(CaptureSession::query()->whereKey($this->mine)->pluck('updated_at', 'id')->map(fn ($at) => (string) $at)->all())->toBe($updated);
});

it('takes an id in any case, answers it as sent, and counts it once', function () {
    $upper = strtoupper($this->mine[0]);

    $this->postJson('/api/sessions/opened', ['ids' => [$upper, $this->mine[0]]])->assertOk()->assertExactJson(['done' => [$upper], 'not_found' => []]);
    expect(SessionOpen::query()->where('user_id', $this->person->id)->pluck('capture_session_id')->all())->toBe([$this->mine[0]]);

    $this->deleteJson('/api/sessions/opened', ['ids' => [$upper]])->assertOk()->assertExactJson(['done' => [$upper], 'not_found' => []]);
    expect(SessionOpen::query()->where('user_id', $this->person->id)->count())->toBe(0);
});

it('writes one query each way, however many ids', function () {
    $many = CaptureSession::factory()->count(40)->create(['user_id' => $this->person->id, 'workspace_id' => $this->device->workspace_id])->pluck('id')->all();
    $writes = function (callable $call): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $call();
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        return count(array_filter($log, fn (array $q): bool => preg_match('/^\s*(insert|update|delete)\b.*session_opens/i', $q['query']) === 1));
    };

    expect($writes(fn () => $this->postJson('/api/sessions/opened', ['ids' => $many])->assertOk()))->toBe(1)
        ->and($writes(fn () => $this->deleteJson('/api/sessions/opened', ['ids' => $many])->assertOk()))->toBe(1);
});

it('takes 1 to 200 ids, with codes, and marks nothing past them', function () {
    $tooMany = [...$this->mine, ...array_map(fn (): string => (string) Str::ulid(), range(1, 198))];

    $this->postJson('/api/sessions/opened', ['ids' => $tooMany])->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')->assertJsonPath('error_codes.ids.0', ['code' => 'too_long', 'max' => 200]);
    $this->deleteJson('/api/sessions/opened', ['ids' => $tooMany])->assertStatus(422);
    $this->postJson('/api/sessions/opened', ['ids' => []])->assertStatus(422)->assertJsonPath('error_codes.ids.0.code', 'required');
    $this->postJson('/api/sessions/opened', ['ids' => [12345]])->assertStatus(422);
    $this->postJson('/api/sessions/opened', [])->assertStatus(422);

    expect(SessionOpen::query()->count())->toBe(0);
    $this->postJson('/api/sessions/opened', ['ids' => array_slice($tooMany, 0, 200)])->assertOk()->assertJsonCount(3, 'done');
});

it('never moves a recording to the bin, while DELETE /api/sessions/{id} still does', function () {
    $this->deleteJson('/api/sessions/opened', ['ids' => $this->mine])->assertOk();
    $this->deleteJson('/api/sessions/opened')->assertStatus(422);
    expect(CaptureSession::query()->whereKey($this->mine)->count())->toBe(3)
        ->and(CaptureSession::onlyTrashed()->count())->toBe(0);

    // A path that is not a recording's id is never one: no sessions route takes it.
    $this->deleteJson('/api/sessions/not-a-recording')->assertNotFound();
    $this->deleteJson("/api/sessions/{$this->mine[0]}")->assertSuccessful();
    expect(CaptureSession::onlyTrashed()->whereKey($this->mine[0])->exists())->toBeTrue();
});
