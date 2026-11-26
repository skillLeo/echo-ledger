<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Library\SavedViews;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * docs/API.md "Saved views (since 2.0.0-p)": a person's My views, kept by the server so every device shows the same ones
 * in the same order. At most 30; a view keeps filters, a search and how the list is shown, never content; every change
 * moves `views_version`, which the library sync carries. Another person's views are never seen or changed.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    $this->me = Fx::user();
    $this->token = Fx::token(Fx::device($this->me));
    $this->workspace = Workspace::query()->findOrFail($this->me->current_workspace_id);
});

/** A teammate in my workspace with a device there: [user, token]. */
function viewMate(object $test, WorkspaceRole $role = WorkspaceRole::Member): array
{
    $mate = Fx::user();
    app(Workspaces::class)->addMember($test->workspace, $mate, $role);
    $mate->forceFill(['current_workspace_id' => $test->workspace->id])->save();

    return [$mate, Fx::token(Fx::device($mate, ['workspace_id' => $test->workspace->id]))];
}

const WHATSAPP_WEEK = ['source' => ['op' => 'is', 'values' => ['whatsapp']], 'date' => ['op' => 'is', 'values' => ['last_7_days']]];

function viewsOf(object $test, string $token): array
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->withHeaders(['Accept' => 'application/json'])->withToken($token)->getJson('/api/views')->assertOk()->json();
}

function syncedVersion(object $test, string $token): int
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->withHeaders(['Accept' => 'application/json'])->withToken($token)->getJson('/api/library')->assertOk()->json('views_version');
}

describe('making and reading views', function () {
    it('saves the filters, the search and how the list is shown, at the end of My views', function () {
        $made = $this->api($this->token)->postJson('/api/views', [
            'name' => '  WhatsApp   this week ', 'filters' => WHATSAPP_WEEK, 'search' => ' invoice ', 'sort' => 'oldest', 'group' => 'project', 'density' => 'compact',
        ])->assertCreated()->json();

        expect($made['views_version'])->toBe(1)
            ->and($made['data'])->toMatchArray([
                'name' => 'WhatsApp this week', 'search' => 'invoice', 'sort' => 'oldest', 'group' => 'project', 'density' => 'compact', 'position' => 0, 'client_ref' => null,
                // In the sections' order, as the server keeps them.
                'filters' => ['date' => ['op' => 'is', 'values' => ['last_7_days']], 'source' => ['op' => 'is', 'values' => ['whatsapp']]],
            ])
            ->and($made['data']['id'])->toHaveLength(26);
        $plain = $this->api($this->token)->postJson('/api/views', ['name' => 'Everything'])->assertCreated()->json('data');
        expect($plain)->toMatchArray(['filters' => [], 'search' => null, 'sort' => 'newest', 'group' => 'date', 'density' => 'comfortable', 'position' => 1]);
        // An empty filter set is an object for the apps, never a list.
        expect($this->api($this->token)->getJson('/api/views')->getContent())->toContain('"filters":{}');

        $list = viewsOf($this, $this->token);
        expect(array_column($list['data'], 'name'))->toBe(['WhatsApp this week', 'Everything'])
            ->and($list['views_version'])->toBe(2)
            ->and($list['limit'])->toBe(30);
    });

    it('keeps no content: only what the person typed as the name and the search', function () {
        $this->api($this->token)->postJson('/api/views', ['name' => 'Mine', 'filters' => WHATSAPP_WEEK, 'search' => 'acme'])->assertCreated();
        $row = (array) DB::table('saved_views')->first();

        expect(array_keys($row))->toEqualCanonicalizing(['id', 'workspace_id', 'user_id', 'client_ref', 'name', 'filters', 'search', 'sort', 'group_by', 'density', 'position', 'created_at', 'updated_at']);
    });

    it('answers the same view for a client_ref sent again, so a view saved offline is made once', function () {
        $first = $this->api($this->token)->postJson('/api/views', ['name' => 'Offline', 'client_ref' => 'phone-7f3a'])->assertCreated()->json('data');
        $again = $this->api($this->token)->postJson('/api/views', ['name' => 'Offline (sent twice)', 'client_ref' => 'phone-7f3a'])->assertOk()->json();

        expect($again['data'])->toBe($first)->and($again['views_version'])->toBe(1)
            ->and(SavedView::query()->withoutGlobalScopes()->count())->toBe(1);
    });

    it('refuses a 31st view with views_limit, and makes room again after a delete', function () {
        foreach (range(1, SavedViews::MAX) as $i) {
            $this->api($this->token)->postJson('/api/views', ['name' => "View {$i}"])->assertCreated();
        }
        $this->api($this->token)->postJson('/api/views', ['name' => 'One too many'])->assertStatus(422)
            ->assertJson(['code' => 'views_limit', 'limit' => 30]);
        expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(30);

        $id = viewsOf($this, $this->token)['data'][0]['id'];
        $this->api($this->token)->deleteJson("/api/views/{$id}")->assertOk();
        $this->api($this->token)->postJson('/api/views', ['name' => 'Room again'])->assertCreated()->assertJsonPath('data.position', 29);
        // The limit is per person: a teammate has 30 of their own.
        [, $mateToken] = viewMate($this);
        $this->api($mateToken)->postJson('/api/views', ['name' => 'Theirs'])->assertCreated();
    });

    it('refuses what a view cannot keep', function () {
        foreach ([
            [['name' => ''], 'name'],
            [['name' => str_repeat('n', 61)], 'name'],
            [['filters' => WHATSAPP_WEEK], 'name'],
            [['name' => 'Bad', 'filters' => ['colour' => ['op' => 'is', 'values' => ['red']]]], 'filters.colour'],
            [['name' => 'Bad', 'filters' => ['source' => ['op' => 'is', 'values' => ['fax']]]], 'filters.source.values'],
            [['name' => 'Bad', 'sort' => 'loudest'], 'sort'],
            [['name' => 'Bad', 'group' => 'mood'], 'group'],
            [['name' => 'Bad', 'density' => 'tiny'], 'density'],
            [['name' => 'Bad', 'search' => str_repeat('s', 201)], 'search'],
        ] as [$body, $field]) {
            $this->api($this->token)->postJson('/api/views', $body)->assertStatus(422)->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors([$field]);
        }
        expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('changing views', function () {
    it('renames a view and changes what it keeps, leaving out what is not sent', function () {
        $id = $this->api($this->token)->postJson('/api/views', ['name' => 'Calls', 'filters' => WHATSAPP_WEEK, 'sort' => 'longest'])->json('data.id');

        $renamed = $this->api($this->token)->patchJson("/api/views/{$id}", ['name' => 'WhatsApp calls'])->assertOk()->json();
        expect($renamed['data'])->toMatchArray(['name' => 'WhatsApp calls', 'sort' => 'longest'])->and($renamed['views_version'])->toBe(2)
            ->and($renamed['data']['filters'])->toBe(['date' => ['op' => 'is', 'values' => ['last_7_days']], 'source' => ['op' => 'is', 'values' => ['whatsapp']]]);

        $changed = $this->api($this->token)->patchJson("/api/views/{$id}", [
            'filters' => ['labels' => ['op' => 'is_not', 'values' => ['Clients']]], 'search' => '', 'group' => 'none',
        ])->assertOk()->json('data');
        expect($changed)->toMatchArray(['filters' => ['labels' => ['op' => 'is_not', 'values' => ['clients']]], 'search' => null, 'group' => 'none', 'name' => 'WhatsApp calls']);

        // The same again changes nothing, and the version stays.
        $this->api($this->token)->patchJson("/api/views/{$id}", ['group' => 'none'])->assertOk()->assertJsonPath('views_version', 3);
    });

    it('deletes a view and moves the ones after it up', function () {
        $ids = array_map(fn (string $name): string => $this->api($this->token)->postJson('/api/views', ['name' => $name])->json('data.id'), ['A', 'B', 'C']);

        $this->api($this->token)->deleteJson("/api/views/{$ids[0]}")->assertOk()->assertExactJson(['id' => $ids[0], 'views_version' => 4]);
        expect(array_map(fn (array $v): array => [$v['name'], $v['position']], viewsOf($this, $this->token)['data']))->toBe([['B', 0], ['C', 1]]);
        $this->api($this->token)->deleteJson("/api/views/{$ids[0]}")->assertNotFound()->assertJson(['code' => 'view_not_found']);
    });

    it('puts every view in the order given, and refuses an order that leaves one out or names one twice', function () {
        $ids = array_map(fn (string $name): string => $this->api($this->token)->postJson('/api/views', ['name' => $name])->json('data.id'), ['A', 'B', 'C']);

        $ordered = $this->api($this->token)->putJson('/api/views/order', ['ids' => [$ids[2], $ids[0], $ids[1]]])->assertOk()->json();
        expect(array_map(fn (array $v): array => [$v['name'], $v['position']], $ordered['data']))->toBe([['C', 0], ['A', 1], ['B', 2]])
            ->and($ordered['views_version'])->toBe(4);
        // The order it already has is no change.
        $this->api($this->token)->putJson('/api/views/order', ['ids' => [$ids[2], $ids[0], $ids[1]]])->assertOk()->assertJsonPath('views_version', 4);

        foreach ([[$ids[0], $ids[1]], [$ids[0], $ids[0], $ids[1]], [$ids[0], $ids[1], Fx::MISSING_ID]] as $wrong) {
            $this->api($this->token)->putJson('/api/views/order', ['ids' => $wrong])->assertStatus(422)->assertJson(['code' => 'validation_failed']);
        }
        expect(array_column(viewsOf($this, $this->token)['data'], 'name'))->toBe(['C', 'A', 'B']);
    });
});

describe('the sync signal', function () {
    it('carries views_version in the library sync, moving with every change on any device of the person', function () {
        $other = Fx::token(Fx::device($this->me));
        expect(syncedVersion($this, $other))->toBe(0);

        $id = $this->api($this->token)->postJson('/api/views', ['name' => 'Calls'])->json('data.id');
        expect(syncedVersion($this, $other))->toBe(1);
        $this->api($this->token)->patchJson("/api/views/{$id}", ['name' => 'Calls this week'])->assertOk();
        expect(syncedVersion($this, $other))->toBe(2);
        // The other device sees the change once it reads the views again.
        expect(viewsOf($this, $other)['data'][0]['name'])->toBe('Calls this week');
        $this->api($other)->deleteJson("/api/views/{$id}")->assertOk();
        expect(syncedVersion($this, $this->token))->toBe(3)
            ->and($this->api($this->token)->getJson('/api/library/facets')->json('views_version'))->toBe(3);
    });
});

describe('tenant isolation', function () {
    it('never shows, changes or counts a teammate\'s or another workspace\'s views', function () {
        [$mate, $mateToken] = viewMate($this, WorkspaceRole::Owner);
        $theirs = $this->api($mateToken)->postJson('/api/views', ['name' => 'Their view'])->assertCreated()->json('data.id');
        $strangerToken = Fx::token(Fx::device(Fx::user()));
        $stranger = $this->api($strangerToken)->postJson('/api/views', ['name' => 'Stranger'])->assertCreated()->json('data.id');
        $mine = $this->api($this->token)->postJson('/api/views', ['name' => 'Mine'])->assertCreated()->json('data.id');

        expect(array_column(viewsOf($this, $this->token)['data'], 'name'))->toBe(['Mine'])
            ->and(viewsOf($this, $this->token)['views_version'])->toBe(1)
            ->and(syncedVersion($this, $mateToken))->toBe(1);
        foreach ([$theirs, $stranger] as $id) {
            $this->api($this->token)->patchJson("/api/views/{$id}", ['name' => 'Taken'])->assertNotFound()->assertJson(['code' => 'view_not_found']);
            $this->api($this->token)->deleteJson("/api/views/{$id}")->assertNotFound();
        }
        // An order naming someone else's view is refused, and nothing moves.
        $this->api($this->token)->putJson('/api/views/order', ['ids' => [$mine, $theirs]])->assertStatus(422);
        expect(SavedView::query()->withoutGlobalScopes()->pluck('name')->sort()->values()->all())->toBe(['Mine', 'Stranger', 'Their view']);

        // My views in my other workspace are that workspace's.
        $current = (int) $this->me->current_workspace_id;
        $second = app(Workspaces::class)->createFor($this->me, 'Second');
        $this->me->forceFill(['current_workspace_id' => $current])->save();
        $there = Fx::token(Fx::device($this->me, ['workspace_id' => $second->id]));
        expect(viewsOf($this, $there)['data'])->toBe([]);
        $this->api($there)->postJson('/api/views', ['name' => 'Second workspace'])->assertCreated();
        expect(array_column(viewsOf($this, $this->token)['data'], 'name'))->toBe(['Mine']);
    });

    it('lets a viewer keep views of their own: a view changes nothing of the workspace', function () {
        [, $viewerToken] = viewMate($this, WorkspaceRole::Viewer);

        $this->api($viewerToken)->postJson('/api/views', ['name' => 'Read only'])->assertCreated();
    });

    it('needs the abilities of the library and of changes', function () {
        $readOnly = Fx::token(Fx::device($this->me), ['library:read']);
        $this->api($readOnly)->getJson('/api/views')->assertOk();
        $this->api($readOnly)->postJson('/api/views', ['name' => 'No'])->assertStatus(403);
        $this->api()->getJson('/api/views')->assertStatus(401);
    });
});

it('rehearses the migration: up adds the two tables, down drops only them, and up again works', function () {
    $migration = 'database/migrations/2026_12_27_000001_create_saved_views.php';
    $this->api($this->token)->postJson('/api/views', ['name' => 'Before'])->assertCreated();

    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('saved_views'))->toBeFalse()->and(Schema::hasColumn('users', 'views_version'))->toBeFalse()
        ->and(Schema::hasTable('label_lists'))->toBeTrue()->and(User::query()->whereKey($this->me->id)->exists())->toBeTrue();

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    // Running up again on tables that are there changes nothing.
    (require base_path($migration))->up();
    expect(Schema::hasTable('saved_views'))->toBeTrue()->and(DB::table('users')->where('id', $this->me->id)->value('views_version'))->toBe(0);
    $this->api($this->token)->postJson('/api/views', ['name' => 'After'])->assertCreated()->assertJsonPath('data.position', 0)->assertJsonPath('views_version', 1);
});
