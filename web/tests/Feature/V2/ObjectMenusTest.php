<?php

declare(strict_types=1);

use App\Filament\Pages\Activity;
use App\Filament\Pages\RecentlyDeleted;
use App\Livewire\Shell\Sidebar;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use App\Services\InterfaceFlags;
use App\Services\Library\SavedViews;
use App\Services\Lists\LabelLists;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * UX-22 3b on the web console past the Library's rows: a view, a label, a project and Trash in the panel, an Activity
 * item and a recording in Trash each answer a right-click (and Shift+F10; a view's or a label's ⋯) with their menu
 * (resources/js/context-menu-core.cjs objectMenu), and each pick runs on the person's own things only.
 */

beforeEach(function () {
    $this->owner = Fx::user();
    $this->ws = (int) $this->owner->current_workspace_id;
    $this->other = Fx::user();
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

/** The sidebar's snapshot on a page, and a call on it (the panel's own requests). */
function objectSidebar(object $test, string $url = '/app/sessions'): string
{
    app('auth')->forgetGuards();
    $html = (string) $test->actingAs($test->owner->fresh(), 'web')->get($url)->assertOk()->getContent();
    preg_match_all('/wire:snapshot="([^"]*)"[^>]*wire:name="([^"]*)"/s', $html, $found);
    foreach ($found[2] as $i => $name) {
        if ($name === Sidebar::class) {
            return html_entity_decode($found[1][$i], ENT_QUOTES | ENT_HTML5);
        }
    }

    throw new RuntimeException("No sidebar on {$url}");
}

/** @param list<array{path: string, method: string, params: list<mixed>}> $calls */
function objectSidebarCall(object $test, array $calls, string $url = '/app/sessions'): array
{
    $response = $test->postJson(Livewire::getUpdateUri(), ['components' => [['snapshot' => objectSidebar($test, $url), 'updates' => [], 'calls' => $calls]]], ['X-Livewire' => 'true']);
    $response->assertOk();

    return (array) $response->json('components.0.effects');
}

function objectCall(string $kind, string $action, string $id, ?string $value = null): array
{
    return ['path' => '', 'method' => 'objectAction', 'params' => [$kind, $action, $id, $value]];
}

it('marks the panel\'s views, labels, projects and Trash for their menus, with what each component can run', function () {
    $views = app(SavedViews::class);
    $views->create($this->ws, $this->owner->id, ['name' => 'Calls this week']);
    $views->create($this->ws, $this->owner->id, ['name' => 'Meetings']);
    app(LabelLists::class)->create($this->ws, $this->owner->id, 'Clients', null);
    $project = Client::query()->create(['workspace_id' => $this->ws, 'user_id' => $this->owner->id, 'name' => 'Acme', 'pinned' => true]);
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    // The first view cannot move up, the last cannot move down; each has a ⋯ that opens the same menu.
    expect($html)->toMatch('~data-v2-object="view" data-v2-object-id="[^"]+" data-v2-object-not="view.moveUp">~')
        ->toMatch('~data-v2-object="view" data-v2-object-id="[^"]+" data-v2-object-not="view.moveDown">~')
        ->toContain('data-v2-object="label" data-v2-object-id="clients"')
        ->toContain('data-v2-object="trash" data-v2-object-id="trash" data-place="trash"')
        ->toContain('window.elV2ContextMenu.openObjectFromButton($el, $event)');
    preg_match('~<script type="application/json" data-v2-object-menus>(.*?)</script>~s', $html, $m);
    $data = json_decode($m[1], true);
    expect($data['runnable'])->toBe(Sidebar::OBJECT_ACTIONS)
        ->and(collect($data['actions'])->pluck('id')->sort()->values()->all())->toBe(collect(Sidebar::OBJECT_ACTIONS)->flatten()->unique()->sort()->values()->all())
        ->and(collect($data['colours'])->pluck('value')->all())->toBe(['grey', 'red', 'orange', 'amber', 'green', 'teal', 'blue', 'pink']);
    $projects = (string) $this->get('/app/clients')->assertOk()->getContent();
    expect($projects)->toContain('data-v2-object="project" data-v2-object-id="'.$project->id.'" data-v2-object-pinned="1" data-project="'.$project->id.'"');
});

it('duplicates, moves and renames a view from its menu, and opens it with its filters to edit; never another person\'s', function () {
    $views = app(SavedViews::class);
    $first = $views->create($this->ws, $this->owner->id, ['name' => 'Calls this week'])['view'];
    $second = $views->create($this->ws, $this->owner->id, ['name' => 'Meetings'])['view'];
    $theirs = $views->create((int) $this->other->current_workspace_id, $this->other->id, ['name' => 'Theirs'])['view'];

    objectSidebarCall($this, [objectCall('view', 'view.duplicate', $first['id'])]);
    expect(collect($views->index($this->ws, $this->owner->id))->pluck('name')->all())->toBe(['Calls this week', 'Meetings', 'Calls this week (copy)']);
    objectSidebarCall($this, [objectCall('view', 'view.moveUp', $second['id'])]);
    expect(collect($views->index($this->ws, $this->owner->id))->pluck('name')->first())->toBe('Meetings');
    $effects = objectSidebarCall($this, [objectCall('view', 'view.editFilters', $first['id'])]);
    expect($effects['redirect'])->toContain('v='.$first['id'])->toContain('compose=filters');

    // Another person's view, by its id: nothing happens to it, and nothing is made.
    objectSidebarCall($this, [objectCall('view', 'view.duplicate', $theirs['id']), objectCall('view', 'view.delete', $theirs['id'])]);
    expect($views->index((int) $this->other->current_workspace_id, $this->other->id))->toHaveCount(1)
        ->and($views->index($this->ws, $this->owner->id))->toHaveCount(3);
});

it('recolours, renames and deletes a label from its menu (its recordings stay); never another person\'s', function () {
    $lists = app(LabelLists::class);
    $lists->create($this->ws, $this->owner->id, 'Clients', null);
    $lists->create((int) $this->other->current_workspace_id, $this->other->id, 'Clients', null);
    $session = Fx::session($this->owner, Fx::device($this->owner));
    $session->labels()->create(['label' => 'Clients', 'workspace_id' => $this->ws]);

    objectSidebarCall($this, [objectCall('label', 'label.colour', 'clients', 'teal')]);
    expect(collect($lists->index($this->ws, $this->owner->id))->firstWhere('key', 'clients')['color'])->toBe('#26978f')
        ->and(collect($lists->index((int) $this->other->current_workspace_id, $this->other->id))->firstWhere('key', 'clients')['color'])->not->toBe('#26978f');
    // A colour that is not one of the eight changes nothing.
    objectSidebarCall($this, [objectCall('label', 'label.colour', 'clients', '#000000')]);
    expect(collect($lists->index($this->ws, $this->owner->id))->firstWhere('key', 'clients')['color'])->toBe('#26978f');

    $effects = objectSidebarCall($this, [objectCall('label', 'file.rename', 'clients')]);
    expect(json_encode($effects))->toContain('renameLabel');
    Livewire::test(Sidebar::class)->call('mountAction', 'deleteLabel', ['key' => 'clients'])->call('callMountedAction');
    expect($lists->index($this->ws, $this->owner->id))->toBe([])
        ->and(CaptureSession::query()->whereKey($session->id)->exists())->toBeTrue()
        ->and($lists->index((int) $this->other->current_workspace_id, $this->other->id))->toHaveCount(1);
});

it('pins, renames and deletes a project from its menu; another person\'s project is never touched', function () {
    $mine = Client::query()->create(['workspace_id' => $this->ws, 'user_id' => $this->owner->id, 'name' => 'Acme']);
    $theirs = Client::query()->create(['workspace_id' => (int) $this->other->current_workspace_id, 'user_id' => $this->other->id, 'name' => 'Theirs']);

    objectSidebarCall($this, [objectCall('project', 'project.pin', (string) $mine->id), objectCall('project', 'project.pin', (string) $theirs->id)], '/app/clients');
    expect($mine->fresh()->pinned)->toBeTrue()->and($theirs->fresh()->pinned)->toBeFalse();

    Livewire::test(Sidebar::class)->call('mountAction', 'renameProject', ['id' => (string) $mine->id])
        ->set('mountedActions.0.data.name', 'Acme Ltd')->call('callMountedAction');
    expect($mine->fresh()->name)->toBe('Acme Ltd');
    Livewire::test(Sidebar::class)->call('mountAction', 'renameProject', ['id' => (string) $theirs->id])
        ->set('mountedActions.0.data.name', 'Mine now')->call('callMountedAction');
    expect($theirs->fresh()->name)->toBe('Theirs');

    Livewire::test(Sidebar::class)->call('mountAction', 'deleteProject', ['id' => (string) $mine->id])->call('callMountedAction');
    expect(Client::query()->whereKey($mine->id)->exists())->toBeFalse()->and(Client::withoutGlobalScopes()->whereKey($theirs->id)->exists())->toBeTrue();
});

it('empties the person\'s own Trash here, from Trash\'s menu and the page, asking first', function () {
    $device = Fx::device($this->owner);
    $binned = Fx::session($this->owner, $device);
    $binned->delete();
    $kept = Fx::session($this->owner, $device);
    $theirs = Fx::session($this->other, Fx::device($this->other));
    $theirs->delete();

    $effects = objectSidebarCall($this, [objectCall('trash', 'trash.empty', 'trash')]);
    expect(json_encode($effects))->toContain('emptyTrash');
    Livewire::test(Sidebar::class)->call('mountAction', 'emptyTrash')->call('callMountedAction');
    expect(CaptureSession::withoutGlobalScopes()->whereKey($binned->id)->value('media_erased_at'))->not->toBeNull()
        ->and(CaptureSession::query()->find($kept->id))->not->toBeNull()
        ->and(CaptureSession::withoutGlobalScopes()->whereKey($theirs->id)->value('media_erased_at'))->toBeNull();
});

it('restores a recording from its Trash row\'s menu, and asks before Delete immediately; only the person\'s own', function () {
    $device = Fx::device($this->owner);
    $binned = Fx::session($this->owner, $device);
    $binned->delete();
    $theirs = Fx::session($this->other, Fx::device($this->other));
    $theirs->delete();

    $page = Livewire::test(RecentlyDeleted::class);
    expect($page->html())->toContain('data-v2-object="trashItem" data-v2-object-id="'.$binned->id.'"')->toContain('data-v2-object-menus');
    $page->call('objectAction', 'trashItem', 'trash.restore', (string) $theirs->id);
    expect(CaptureSession::withoutGlobalScopes()->whereKey($theirs->id)->value('deleted_at'))->not->toBeNull();
    $page->call('objectAction', 'trashItem', 'trash.restore', (string) $binned->id);
    expect(CaptureSession::query()->find($binned->id))->not->toBeNull();

    $binned->delete();
    Livewire::test(RecentlyDeleted::class)->call('objectAction', 'trashItem', 'recording.deleteNow', (string) $binned->id)
        ->assertActionMounted([TestAction::make('eraseNow')->table($binned)]);
});

it('opens, marks as read and retries an Activity item from its menu, each only where it fits; never another person\'s', function () {
    $note = fn (User $user, string $kind) => $user->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test',
        'data' => ['title' => 'Something', 'body' => 'Happened', 'viewData' => ['kind' => $kind]]]);
    $failed = $note($this->owner, 'upload_failed');
    $paired = $note($this->owner, 'device_paired');
    $theirs = $note($this->other, 'upload_failed');

    $page = Livewire::test(Activity::class);
    expect($page->html())->toContain('data-v2-object="activity" data-v2-object-id="'.$failed->id.'"')
        // A device signing in is about nothing to retry.
        ->toMatch('~data-v2-object-id="'.$paired->id.'" data-v2-object-not="[^"]*activity\.retry~');
    $page->call('objectAction', 'activity', 'activity.markRead', $paired->id)
        ->call('objectAction', 'activity', 'activity.markRead', $theirs->id);
    expect($paired->fresh()->read_at)->not->toBeNull()->and($theirs->fresh()->read_at)->toBeNull();

    $page->call('objectAction', 'activity', 'activity.retry', $failed->id);
    expect($this->owner->fresh()->retry_requested_at)->not->toBeNull()->and($this->other->fresh()->retry_requested_at)->toBeNull();
    // A kind the page does not run from this menu does nothing.
    $page->call('objectAction', 'activity', 'trash.empty', $failed->id);
});
