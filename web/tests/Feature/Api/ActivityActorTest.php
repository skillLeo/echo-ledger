<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\Accounts\Avatars;
use App\Services\ActivityActors;
use App\Services\ActivityFeed;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Contract 2.0.0-aa (UX-18): an Activity item about a person carries `actor` (id, name and picture as the API shows
 * people), only to its recipient: the item's actor_id, else for an older share its sender as Sent to keeps them.
 */

beforeEach(function () {
    $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
    [, $this->anaToken] = $this->enroll($this->ana, platform: 'windows');
    $this->omar = User::factory()->create(['name' => 'Omar', 'email' => 'omar@example.com']);
    [, $this->omarToken] = $this->enroll($this->omar);
    $this->asToken($this->anaToken);
    $this->call = $this->makeReadySession($this->ana->devices()->firstOrFail(), Fixtures::audio(3000), ['title' => 'Call with Omar', 'status' => SessionStatus::Completed]);
});

/** [$person]'s Activity as their own device lists it. */
function listedActivityOf(object $test, string $token): array
{
    return $test->asToken($token)->getJson('/api/activity')->assertOk()->json('data');
}

/** The one shared_with_you item [$person] holds. */
function sharedItemHeldBy(User $person): DatabaseNotification
{
    return DatabaseNotification::query()->where('notifiable_id', $person->id)->get()->sole(fn ($n): bool => ActivityFeed::kindOf($n) === 'shared_with_you');
}

/** Stores actor_id on an item, as the web stores it since UX-18 (fix/W-avatar-menu). */
function storeActorIdOn(DatabaseNotification $item, int|string $actorId): void
{
    $data = $item->data;
    $data['viewData']['actor_id'] = $actorId;
    $item->forceFill(['data' => $data])->save();
}

it('names the sender of a share from before actor_id, as Sent to keeps them', function () {
    $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();

    $item = collect(listedActivityOf($this, $this->omarToken))->firstWhere('kind', 'shared_with_you');
    expect($item['actor'])->toBe(['id' => $this->ana->id, 'name' => 'Ana', 'avatar_url' => null, 'avatar_version' => null,
        'avatar_color' => $this->ana->avatarColor()]);
});

it('takes the item\'s actor_id first, with the picture by its signed, versioned URL like everywhere else', function () {
    $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
    $staff = User::factory()->create(['name' => 'Sara (EchoLedger)']);
    $staff->forceFill(['avatar_version' => 1763700000])->save();
    storeActorIdOn(sharedItemHeldBy($this->omar), (string) $staff->id);

    $actor = collect(listedActivityOf($this, $this->omarToken))->firstWhere('kind', 'shared_with_you')['actor'];
    expect($actor)->toMatchArray(['id' => $staff->id, 'name' => 'Sara (EchoLedger)', 'avatar_version' => 1763700000, 'avatar_color' => $staff->avatarColor()])
        ->and($actor['avatar_url'])->toBe(Avatars::url($staff->id, 1763700000));

    // The same access rule as the avatar endpoint: the signature opens it (no picture stored here: 404), a changed one is 403.
    $this->get($actor['avatar_url'])->assertNotFound();
    $this->get(str_replace('signature=', 'signature=0', $actor['avatar_url']))->assertForbidden();
});

it('gives every item the key, null when it is about nobody or about someone no longer there', function () {
    $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
    storeActorIdOn(sharedItemHeldBy($this->omar), 999999);

    $items = listedActivityOf($this, $this->omarToken);
    expect($items)->not->toBeEmpty()
        ->and(collect($items)->every(fn (array $i): bool => array_key_exists('actor', $i) && $i['actor'] === null))->toBeTrue();
});

it('never reads who else a recording went to, nor anyone else\'s items', function () {
    $lina = User::factory()->create(['name' => 'Lina']);
    [, $linaToken] = $this->enroll($lina);
    // Lina holds a share item for the call, but it was sent only to Omar: Sent to names nobody for her.
    $this->asToken($this->anaToken)->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
    app(ActivityFeed::class)->sharedWithYou($lina, 'Someone sent you a call', '', $this->call->id);

    expect(collect(listedActivityOf($this, $linaToken))->firstWhere('kind', 'shared_with_you')['actor'])->toBeNull()
        ->and(ActivityActors::of($lina, [sharedItemHeldBy($this->omar)]))->toBe([])
        ->and(array_values(array_map(fn (User $u): int => $u->id, ActivityActors::of($this->omar, [sharedItemHeldBy($this->omar)]))))->toBe([$this->ana->id]);
});

it('finds the people of a whole page in the same number of queries however many items name someone', function () {
    $queries = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        listedActivityOf($this, $this->omarToken);
        // The token's own stamp (last_used_at, written only when the second changed) is not the page's work.
        $count = collect(DB::getQueryLog())->reject(fn (array $q): bool => str_contains($q['query'], 'personal_access_tokens'))->count();
        DB::disableQueryLog();

        return $count;
    };
    $send = function (int $i): void {
        $session = CaptureSession::factory()->create(['user_id' => $this->ana->id, 'workspace_id' => $this->ana->current_workspace_id, 'status' => SessionStatus::Completed]);
        $this->asToken($this->anaToken)->postJson("/api/sessions/{$session->id}/send", ['to' => ['omar@example.com']])->assertCreated();
    };
    $send(1);
    $one = $queries();
    foreach (range(2, 12) as $i) {
        $send($i);
    }

    expect($queries())->toBe($one)
        ->and(collect(listedActivityOf($this, $this->omarToken))->where('kind', 'shared_with_you')->pluck('actor.id')->unique()->values()->all())->toBe([$this->ana->id]);
});
