<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\WorkspaceSettings;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

beforeEach(fn () => Filament::setCurrentPanel('admin'));

it('lets an owner add a new person as a member with a one-time password', function () {
    $owner = Fx::user();
    $this->actingAs($owner, 'web');

    Livewire::test(WorkspaceSettings::class)
        ->callAction('addMember', ['email' => 'New.Person@Example.test', 'name' => 'New Person', 'role' => 'member'])
        ->assertNotified();

    $person = User::query()->where('email', 'new.person@example.test')->firstOrFail();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    expect($workspace->roleOf($person))->toBe(WorkspaceRole::Member)
        ->and($person->current_workspace_id)->toBe($workspace->id)
        ->and($person->is_super_admin)->toBeFalse()
        ->and($person->workspaces()->count())->toBe(1);
});

it('adds an existing account, changes roles and removes members, but never the last owner', function () {
    $owner = Fx::user();
    $other = Fx::user();
    $this->actingAs($owner, 'web');
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);

    $page = Livewire::test(WorkspaceSettings::class)
        ->callAction('addMember', ['email' => $other->email, 'role' => 'member'])
        ->assertCanSeeTableRecords([$owner, $other]);
    $page->callAction(TestAction::make('changeRole')->table($other));
    expect($workspace->roleOf($other))->toBe(WorkspaceRole::Owner);
    $page->callAction(TestAction::make('remove')->table($other));
    expect($workspace->roleOf($other))->toBeNull()
        ->and($other->fresh()->current_workspace_id)->not->toBe($workspace->id);

    $page->callAction(TestAction::make('remove')->table($owner))->assertNotified('A workspace needs at least one owner.');
    expect($workspace->roleOf($owner))->toBe(WorkspaceRole::Owner)
        ->and(Hash::check('password', (string) $other->fresh()->password))->toBeTrue(); // an existing account keeps its password
});

it('shows members the list but no member management', function () {
    $owner = Fx::user();
    $member = withoutWorkspace(Fx::user());
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $member);
    $this->actingAs($member->fresh(), 'web');

    Livewire::test(WorkspaceSettings::class)
        ->assertCanSeeTableRecords([$owner, $member])
        ->assertActionHidden('addMember')->assertActionHidden('rename')
        ->assertActionHidden(TestAction::make('remove')->table($owner))
        ->assertActionHidden(TestAction::make('changeRole')->table($owner))
        ->assertDontSee('All workspaces');
});

it('switches between the workspaces a person belongs to and only those', function () {
    $user = Fx::user();
    $second = app(Workspaces::class)->createFor($user, 'Second');
    $stranger = Workspace::query()->findOrFail(Fx::user()->current_workspace_id);
    $this->actingAs($user, 'web');

    Livewire::test(WorkspaceSettings::class)->callAction('switch', ['workspace_id' => $second->id]);
    expect($user->fresh()->current_workspace_id)->toBe($second->id);

    Livewire::test(WorkspaceSettings::class)->callAction('switch', ['workspace_id' => $stranger->id])->assertHasActionErrors(['workspace_id']);
    expect($user->fresh()->current_workspace_id)->toBe($second->id);
});

it('gives the server admin an overview of every workspace without content', function () {
    $admin = Fx::superAdmin();
    Fx::session(Fx::user(), null, ['title' => 'Private title']);
    $this->actingAs($admin, 'web');

    Livewire::test(WorkspaceSettings::class)->assertSee('All workspaces')->assertDontSee('Private title');
    $this->get(WorkspaceSettings::getUrl())->assertOk();
});
