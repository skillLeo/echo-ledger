<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\WorkspaceRole;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Device;
use App\Models\LabelList;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountEraser;
use App\Services\RetentionPolicy;
use App\Services\SettingsRepository;
use App\Services\Sharing\Teammates;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * docs/API.md "Important, label colours and days left (since 2.0.0-i)": the important flag (PATCH, bulk, the filter),
 * label colours (PUT /api/labels/color, the library item's label_colors, the views' labels; kept on the person's lists
 * since 2.0.0-m), expires_at as the hourly
 * clean-up works it out, and who may see or change each of them.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-21 10:00:00', 'UTC'));
    app(SettingsRepository::class)->set('retention_days', 30);
    $this->me = Fx::user();
    $this->token = Fx::token($this->myDevice = Fx::device($this->me));
    $this->workspace = Workspace::query()->findOrFail($this->me->current_workspace_id);
});

/** A teammate in my workspace, with a device of their own there. */
function teammate(object $test, WorkspaceRole $role = WorkspaceRole::Member): array
{
    $mate = Fx::user();
    app(Workspaces::class)->addMember($test->workspace, $mate, $role);
    $mate->forceFill(['current_workspace_id' => $test->workspace->id])->save();
    $device = Fx::device($mate, ['workspace_id' => $test->workspace->id]);

    return [$mate, Fx::token($device), $device];
}

function rowSession(User $owner, array $state = []): CaptureSession
{
    return CaptureSession::factory()->create(['user_id' => $owner->id, ...$state]);
}

/** @return array<string, array<string, mixed>> the library as [$token]'s device lists it, by id */
function rowItems(object $test, string $token, string $query = ''): array
{
    app('auth')->forgetGuards();

    return collect($test->flushHeaders()->withHeaders(['Accept' => 'application/json'])->withToken($token)->getJson('/api/library'.($query !== '' ? '?'.$query : ''))->assertOk()->json('data'))->keyBy('id')->all();
}

function stored(string $id): CaptureSession
{
    return CaptureSession::query()->withoutGlobalScopes()->withTrashed()->findOrFail($id);
}

describe('important', function () {
    it('is set with PATCH and bulk, filtered with important=1|0, and moves updated_at', function () {
        [$a, $b, $c] = [rowSession($this->me), rowSession($this->me), rowSession($this->me)];
        $before = $a->updated_at;
        $this->travel(5)->seconds();

        $patched = $this->api($this->token)->patchJson("/api/sessions/{$a->id}", ['important' => true])->assertOk();
        expect($patched->json('data.important'))->toBeTrue()
            // Since 2.0.0-i the PATCH answer carries `opened` for this person too.
            ->and($patched->json('data.opened'))->toBeFalse()
            ->and(stored($a->id)->updated_at->gt($before))->toBeTrue();
        $this->api($this->token)->postJson('/api/sessions/bulk', ['ids' => [$b->id, $c->id], 'action' => 'important'])->assertOk()->assertExactJson(['changed' => 2]);
        $this->api($this->token)->postJson('/api/sessions/bulk', ['ids' => [$c->id], 'action' => 'unimportant'])->assertOk()->assertExactJson(['changed' => 1]);

        expect(array_keys(rowItems($this, $this->token, 'important=1')))->toEqualCanonicalizing([$a->id, $b->id])
            ->and(array_keys(rowItems($this, $this->token, 'important=0')))->toBe([$c->id])
            ->and(rowItems($this, $this->token)[$c->id]['important'])->toBeFalse();
        // It changes nothing else.
        expect(stored($a->id)->only(['pinned', 'keep_forever']))->toBe(['pinned' => false, 'keep_forever' => false]);

        $this->api($this->token)->patchJson("/api/sessions/{$a->id}", ['important' => 'yes'])->assertStatus(422);
        $this->api($this->token)->getJson('/api/library?important=maybe')->assertStatus(422);
    });

    it('never touches a teammate\'s or another workspace\'s recording', function () {
        [$mate, $mateToken] = teammate($this);
        $theirs = rowSession($mate, ['workspace_id' => $this->workspace->id]);
        $foreign = rowSession(Fx::user());

        foreach ([$theirs, $foreign] as $session) {
            $this->api($this->token)->patchJson("/api/sessions/{$session->id}", ['important' => true])->assertNotFound()->assertJson(['code' => 'session_not_found']);
        }
        $this->api($this->token)->postJson('/api/sessions/bulk', ['ids' => [$theirs->id, $foreign->id], 'action' => 'important'])->assertOk()->assertExactJson(['changed' => 0]);

        expect(stored($theirs->id)->important)->toBeFalse()->and(stored($foreign->id)->important)->toBeFalse()
            ->and(rowItems($this, $this->token, 'important=0'))->toBe([])
            ->and(array_keys(rowItems($this, $mateToken)))->toBe([$theirs->id]);
    });

    it('is refused to a viewer\'s device', function () {
        $session = rowSession($this->me);
        $session->labels()->create(['label' => 'Invoice']);
        [, $viewerToken] = teammate($this, WorkspaceRole::Viewer);

        $this->api($viewerToken)->patchJson("/api/sessions/{$session->id}", ['important' => true])->assertForbidden()->assertJson(['code' => 'not_allowed']);
        $this->api($viewerToken)->postJson('/api/sessions/bulk', ['ids' => [$session->id], 'action' => 'important'])->assertForbidden();
        $this->api($viewerToken)->putJson('/api/labels/color', ['label' => 'Invoice', 'color' => '#000000'])->assertForbidden()->assertJson(['code' => 'not_allowed']);

        expect(stored($session->id)->important)->toBeFalse()->and(LabelList::query()->whereNotNull('color')->count())->toBe(0);
    });
});

describe('label colours', function () {
    it('colours every recording of mine with the label, in the views too, and clears it', function () {
        $a = rowSession($this->me);
        $a->labels()->create(['label' => 'Invoice']);
        $b = rowSession($this->me);
        $b->labels()->create(['label' => 'invoice']);
        $b->labels()->create(['label' => 'Follow up']);
        $before = $b->updated_at;
        $this->travel(5)->seconds();

        $this->api($this->token)->putJson('/api/labels/color', ['label' => '  INVOICE ', 'color' => '#16A34A'])->assertOk()
            ->assertExactJson(['label' => 'Invoice', 'color' => '#16a34a']);
        expect(stored($b->id)->updated_at->gt($before))->toBeTrue();

        $items = rowItems($this, $this->token);
        expect($items[$a->id]['label_colors'])->toBe(['Invoice' => '#16a34a'])
            ->and($items[$b->id]['label_colors'])->toBe(['invoice' => '#16a34a']);
        $labels = collect($this->api($this->token)->getJson('/api/library/views')->assertOk()->json('labels'))->keyBy(fn ($l) => mb_strtolower($l['label']));
        expect($labels['follow up']['color'])->toBeNull()->and($labels['invoice']['color'])->toBe('#16a34a');

        // A row stored in capitals some other way still reads in lower case.
        LabelList::query()->whereNotNull('color')->update(['color' => '#16A34A']);
        expect(rowItems($this, $this->token)[$a->id]['label_colors'])->toBe(['Invoice' => '#16a34a']);

        // Changing it again keeps one row.
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'invoice', 'color' => '#2563eb'])->assertOk()->assertJson(['color' => '#2563eb']);
        expect(LabelList::query()->whereNotNull('color')->count())->toBe(1);

        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'invoice', 'color' => null])->assertOk()->assertExactJson(['label' => 'Invoice', 'color' => null]);
        expect(LabelList::query()->whereNotNull('color')->count())->toBe(0);
        $json = $this->api($this->token)->getJson('/api/library')->assertOk()->getContent();
        // An item without colours carries an empty object, never a list.
        expect($json)->toContain('"label_colors":{}')
            ->and(rowItems($this, $this->token)[$a->id]['label_colors'])->toBe([]);
    });

    it('keeps one person\'s colours from their teammate and from other workspaces', function () {
        [$mate, $mateToken] = teammate($this);
        $mine = rowSession($this->me);
        $mine->labels()->create(['label' => 'Invoice']);
        $mine->labels()->create(['label' => 'Secret']);
        $theirs = rowSession($mate, ['workspace_id' => $this->workspace->id]);
        $theirs->labels()->create(['label' => 'Invoice']);
        $theirs->labels()->create(['label' => 'Their own']);
        $stranger = Fx::user();
        rowSession($stranger)->labels()->create(['label' => 'Elsewhere']);
        $theirsBefore = $theirs->updated_at;
        $this->travel(5)->seconds();

        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Invoice', 'color' => '#dc2626'])->assertOk();

        // The teammate's recording with the same label is neither coloured nor re-synced.
        expect(rowItems($this, $mateToken)[$theirs->id]['label_colors'])->toBe([])
            ->and(stored($theirs->id)->updated_at->equalTo($theirsBefore))->toBeTrue()
            ->and(collect($this->api($mateToken)->getJson('/api/library/views')->json('labels'))->firstWhere('label', 'Invoice')['color'])->toBeNull();

        // A label only someone else carries is not found, whoever has it.
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Their own', 'color' => '#000000'])->assertNotFound()->assertJson(['code' => 'label_not_found']);
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Elsewhere', 'color' => '#000000'])->assertNotFound();
        $this->api($mateToken)->putJson('/api/labels/color', ['label' => 'Secret', 'color' => '#000000'])->assertNotFound();

        // The teammate's own colour for the same name is theirs alone.
        $this->api($mateToken)->putJson('/api/labels/color', ['label' => 'invoice', 'color' => '#0d9488'])->assertOk();
        expect(rowItems($this, $this->token)[$mine->id]['label_colors'])->toBe(['Invoice' => '#dc2626'])
            ->and(rowItems($this, $mateToken)[$theirs->id]['label_colors'])->toBe(['Invoice' => '#0d9488'])
            ->and(LabelList::query()->whereNotNull('color')->orderBy('user_id')->pluck('user_id')->all())->toBe([$this->me->id, $mate->id]);
    });

    it('matches labels in any script ignoring capitals only, the same on every database', function () {
        $ete = rowSession($this->me);
        $ete->labels()->create(['label' => 'Été']);
        $resume = rowSession($this->me);
        $resume->labels()->create(['label' => 'Résumé']);
        $plain = rowSession($this->me);
        $plain->labels()->create(['label' => 'resume']);
        $urdu = rowSession($this->me);
        $urdu->labels()->create(['label' => 'بل']);

        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'ÉTÉ', 'color' => '#dc2626'])->assertOk()->assertJson(['label' => 'Été']);
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'RÉSUMÉ', 'color' => '#16a34a'])->assertOk()->assertJson(['label' => 'Résumé']);
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'بل', 'color' => '#2563eb'])->assertOk();

        $items = rowItems($this, $this->token);
        expect($items[$ete->id]['label_colors'])->toBe(['Été' => '#dc2626'])
            ->and($items[$resume->id]['label_colors'])->toBe(['Résumé' => '#16a34a'])
            // Accents count: "resume" is another label and has no colour.
            ->and($items[$plain->id]['label_colors'])->toBe([])
            ->and($items[$urdu->id]['label_colors'])->toBe(['بل' => '#2563eb'])
            ->and(LabelList::query()->whereNotNull('color')->count())->toBe(3);
    });

    it('refuses a missing label, a missing colour and a colour that is not #rrggbb', function () {
        rowSession($this->me)->labels()->create(['label' => 'Invoice']);

        $this->api($this->token)->putJson('/api/labels/color', ['label' => '  ', 'color' => '#000000'])->assertStatus(422);
        $this->api($this->token)->putJson('/api/labels/color', ['label' => str_repeat('x', 41), 'color' => '#000000'])->assertStatus(422);
        foreach (['green', '#12345', '#1234567', '#GGGGGG', 12] as $bad) {
            $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Invoice', 'color' => $bad])->assertStatus(422);
        }
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Invoice'])->assertStatus(422);
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Nobody', 'color' => '#000000'])->assertNotFound();
        $this->api()->putJson('/api/labels/color', ['label' => 'Invoice', 'color' => '#000000'])->assertUnauthorized();

        expect(LabelList::query()->whereNotNull('color')->count())->toBe(0);
    });

    it('goes with the account when it is erased', function () {
        Fx::fakeRecordingsDisk();
        rowSession($this->me)->labels()->create(['label' => 'Invoice']);
        $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Invoice', 'color' => '#dc2626'])->assertOk();
        $other = Fx::user();
        LabelList::query()->create(['workspace_id' => $other->current_workspace_id, 'user_id' => $other->id, 'label_key' => 'kept', 'name' => 'Kept', 'color' => '#000000']);
        app('auth')->forgetGuards();

        app(AccountEraser::class)->erase($this->me->fresh());

        expect(LabelList::query()->pluck('user_id')->all())->toBe([$other->id]);
    });
});

describe('expires_at', function () {
    it('follows the general setting, the project\'s days and keep, and is null while recording', function () {
        $general = rowSession($this->me, ['created_at' => now()->subDays(12)]);
        $client = Client::factory()->create(['user_id' => $this->me->id, 'retention_days' => 7]);
        $project = rowSession($this->me, ['created_at' => now()->subDays(2), 'client_id' => $client->id]);
        $kept = rowSession($this->me, ['created_at' => now()->subDays(2), 'keep_forever' => true]);
        $open = rowSession($this->me, ['status' => SessionStatus::Recording]);

        $items = rowItems($this, $this->token);
        expect($items[$general->id]['expires_at'])->toBe(now()->subDays(12)->addDays(30)->toAtomString())
            ->and($items[$project->id]['expires_at'])->toBe(now()->subDays(2)->addDays(7)->toAtomString())
            ->and($items[$kept->id]['expires_at'])->toBeNull()
            ->and(rowItems($this, $this->token, 'status=recording')[$open->id]['expires_at'])->toBeNull();

        // Keep forever and back, and a project's new days, re-sync the date.
        $this->travel(5)->seconds();
        expect($this->api($this->token)->patchJson("/api/sessions/{$kept->id}", ['keep' => false])->assertOk()->json('data.expires_at'))
            ->toBe(now()->subSeconds(5)->subDays(2)->addDays(30)->toAtomString());
        $before = stored($project->id)->updated_at;
        $this->travel(5)->seconds();
        $this->api($this->token)->patchJson("/api/clients/{$client->id}", ['retention_days' => 14])->assertOk();
        expect(stored($project->id)->updated_at->gt($before))->toBeTrue()
            ->and(rowItems($this, $this->token)[$project->id]['expires_at'])->toBe(stored($project->id)->created_at->copy()->addDays(14)->toAtomString());
    });

    it('takes the plan\'s days when they come first, only for recordings made since they were set, and the owner\'s own', function () {
        $policy = app(RetentionPolicy::class);
        $onPlan = function (array $plan, array $overrides = []): User {
            $user = Fx::user($overrides !== [] ? ['entitlement_overrides' => ['limits' => $overrides]] : []);
            $made = Plan::query()->create(['key' => 'test-'.Str::lower(Str::random(6)), 'name' => 'Test', ...$plan]);
            Workspace::query()->findOrFail($user->current_workspace_id)->forceFill(['plan_id' => $made->id])->save();

            return $user;
        };
        $short = rowSession($onPlan(['retention_days' => 10, 'retention_from' => now()->subYear()]), ['created_at' => now()->subDays(3)]);
        $late = rowSession($onPlan(['retention_days' => 10, 'retention_from' => now()->subDay()]), ['created_at' => now()->subDays(3)]);
        $long = rowSession($onPlan(['retention_days' => 90, 'retention_from' => now()->subYear()]), ['created_at' => now()->subDays(3)]);
        $own = rowSession($onPlan(['retention_days' => 10, 'retention_from' => now()->subYear()], ['retention_days' => 4]), ['created_at' => now()->subDays(3)]);
        $never = rowSession($onPlan(['retention_days' => 10, 'retention_from' => now()->subYear()], ['retention_days' => null]), ['created_at' => now()->subDays(3)]);

        $at = fn (CaptureSession $s): ?string => $policy->expiresAt(stored($s->id))?->toAtomString();
        $made = now()->subDays(3);
        expect($at($short))->toBe($made->copy()->addDays(10)->toAtomString())
            // The plan's days were set after the recording was made: the general 30 days.
            ->and($at($late))->toBe($made->copy()->addDays(30)->toAtomString())
            // A plan's longer days never stretch the general setting.
            ->and($at($long))->toBe($made->copy()->addDays(30)->toAtomString())
            ->and($at($own))->toBe($made->copy()->addDays(4)->toAtomString())
            // The owner's own "no limit" lifts the plan's days, not the general setting.
            ->and($at($never))->toBe($made->copy()->addDays(30)->toAtomString());
    });

    it('agrees with the hourly clean-up: still there a minute before, in Trash an hour after', function () {
        $client = Client::factory()->create(['user_id' => $this->me->id, 'retention_days' => 5]);
        $sessions = [
            rowSession($this->me, ['created_at' => now()->subDays(1), 'client_id' => $client->id]),
            rowSession($this->me, ['created_at' => now()->subDays(20)]),
        ];
        $planUser = Fx::user(['entitlement_overrides' => ['limits' => ['retention_days' => 8]]]);
        $plan = Plan::query()->create(['key' => 'test-agree', 'name' => 'Test', 'retention_days' => 12, 'retention_from' => now()->subYear()]);
        Workspace::query()->findOrFail($planUser->current_workspace_id)->forceFill(['plan_id' => $plan->id])->save();
        $sessions[] = rowSession($planUser, ['created_at' => now()->subDays(2)]);

        $expiry = collect($sessions)->mapWithKeys(fn (CaptureSession $s) => [$s->id => app(RetentionPolicy::class)->expiresAt(stored($s->id))])->sort();
        expect($expiry->filter()->count())->toBe(3);
        app('auth')->forgetGuards();

        foreach ($expiry as $id => $at) {
            $this->travelTo($at->copy()->subMinute());
            app(RetentionPolicy::class)->applyRetention();
            expect(stored($id)->trashed())->toBeFalse();
            $this->travelTo($at->copy()->addHour());
            app(RetentionPolicy::class)->applyRetention();
            expect(stored($id)->trashed())->toBeTrue();
        }
    });

    it('keeps the sender\'s project days on a recording a teammate sent, as the clean-up does', function () {
        [$mate, $mateToken] = teammate($this);
        $project = Client::factory()->create(['user_id' => $this->me->id, 'retention_days' => 6]);
        $sent = rowSession($this->me, ['created_at' => now()->subDays(2), 'client_id' => $project->id]);
        app(Teammates::class)->send($this->me, $sent, [$mate->email], null);
        $at = $sent->created_at->copy()->addDays(6);

        // The recipient sees neither the project nor a different date than the sender.
        $shared = rowItems($this, $mateToken, 'folder=shared')[$sent->id];
        expect($shared['client'])->toBeNull()
            ->and($shared['expires_at'])->toBe($at->toAtomString())
            ->and(rowItems($this, $this->token)[$sent->id]['expires_at'])->toBe($at->toAtomString());
        app('auth')->forgetGuards();

        $this->travelTo($at->copy()->subMinute());
        app(RetentionPolicy::class)->applyRetention();
        expect(stored($sent->id)->trashed())->toBeFalse();
        $this->travelTo($at->copy()->addHour());
        app(RetentionPolicy::class)->applyRetention();
        expect(stored($sent->id)->trashed())->toBeTrue();
    });

    it('is left out of a tombstone', function () {
        $session = rowSession($this->me);
        $this->travel(5)->seconds();
        $this->api($this->token)->deleteJson("/api/sessions/{$session->id}")->assertSuccessful();

        $tombstone = rowItems($this, $this->token, 'since='.urlencode(now()->subMinute()->toAtomString()))[$session->id];
        expect($tombstone)->toHaveKeys(['id', 'deleted', 'updated_at'])->not->toHaveKeys(['expires_at', 'important', 'label_colors']);
    });
});

it('rehearses the migration: down takes out exactly what up added, and up runs again safely', function () {
    $migration = 'database/migrations/2026_12_21_000001_add_important_and_label_colors.php';
    $session = rowSession($this->me, ['title' => 'Still here']);

    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasColumn('capture_sessions', 'important'))->toBeFalse()
        ->and(Schema::hasTable('label_colors'))->toBeFalse()
        ->and(stored($session->id)->title)->toBe('Still here');

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    (require base_path($migration))->up();
    expect(Schema::hasColumn('capture_sessions', 'important'))->toBeTrue()
        ->and(Schema::hasTable('label_colors'))->toBeTrue()
        ->and(stored($session->id)->important)->toBeFalse();
});
