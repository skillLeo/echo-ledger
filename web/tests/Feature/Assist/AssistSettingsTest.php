<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\LiveAssist\AssistPreps;
use App\Filament\Pages\LiveAssist\AssistSettings;
use App\Models\Workspace;
use App\Services\Assist\AssistDefaults;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Live Assist's settings (v1.9 L8): a person's defaults per meeting type, which a new prep starts with when it names
 * none, in the console and through the device API.
 */

beforeEach(function () {
    assistRelaySetUp();
    Filament::setCurrentPanel('app');
});

it('starts from Balanced, the meeting type\'s own hint level and no language help, and keeps only known values', function () {
    $device = assistPerson('pro');
    expect(AssistDefaults::for($device->user, 'interview'))->toBe(['mode' => 'balanced', 'hint_level' => 'outline', 'language_help' => 'off'])
        ->and(AssistDefaults::for(null, 'boss')['hint_level'])->toBe('full');

    AssistDefaults::save($device->user, ['boss' => ['mode' => 'deep', 'hint_level' => 'keywords', 'language_help' => 'klingon'], 'nope' => ['mode' => 'fast']]);
    expect($device->user->fresh()->assist_settings)->toBe(['types' => ['interview' => [], 'boss' => ['mode' => 'deep', 'hint_level' => 'keywords'],
        'existing_client' => [], 'new_client' => [], 'general' => []]])
        ->and(AssistDefaults::for($device->user->fresh(), 'boss'))->toBe(['mode' => 'deep', 'hint_level' => 'keywords', 'language_help' => 'off']);
});

it('saves the defaults on the page, and a new prep starts with them, in the console and through the apps', function () {
    $device = assistPerson('pro');
    $this->actingAs($device->user->fresh(), 'web');
    $this->get(AssistSettings::getUrl())->assertOk()->assertSee('Live Assist settings')->assertSee('set in the desktop app');

    Livewire::test(AssistSettings::class)->assertSet('data.interview.hint_level', 'outline')
        ->set('data.interview.mode', 'fast')->set('data.interview.language_help', 'roman_urdu')->set('data.boss.hint_level', 'keywords')
        ->call('save')->assertHasNoErrors()->assertNotified('Live Assist settings saved');

    $this->api(Fx::token($device))->postJson('/api/assist/preps', ['meeting_type' => 'interview'])->assertCreated()
        ->assertJsonPath('mode', 'fast')->assertJsonPath('hint_level', 'outline')->assertJsonPath('language_help', 'roman_urdu');
    // What the app names wins.
    $this->api(Fx::token($device))->postJson('/api/assist/preps', ['meeting_type' => 'interview', 'mode' => 'deep'])->assertJsonPath('mode', 'deep');

    $this->actingAs($device->user->fresh(), 'web');
    Livewire::test(AssistPreps::class)->mountAction('create')->assertSchemaStateSet(['meeting_type' => 'general', 'mode' => 'balanced'])
        ->set('mountedActions.0.data.meeting_type', 'boss')->assertSchemaStateSet(['hint_level' => 'keywords'])
        ->set('mountedActions.0.data.meeting_type', 'interview')->assertSchemaStateSet(['mode' => 'fast', 'language_help' => 'roman_urdu']);
});

it('is not there without the flag, and the small read-only console changes nothing', function () {
    $this->actingAs(assistPerson('pro', flag: false)->user->fresh(), 'web');
    $this->get(AssistSettings::getUrl())->assertForbidden();

    $owner = assistPerson('pro');
    $viewer = Fx::user();
    $team = Workspace::query()->findOrFail($owner->workspace_id);
    app(Workspaces::class)->addMember($team, $viewer, WorkspaceRole::Viewer);
    app(Workspaces::class)->switchTo($viewer, $team);
    app(InterfaceFlags::class)->set('live_assist', false, 0, [], [$owner->user_id, $viewer->id]);
    $this->actingAs($viewer->fresh(), 'web');

    Livewire::test(AssistSettings::class)->assertOk()->set('data.general.mode', 'max')->call('save');
    expect($viewer->fresh()->assist_settings)->toBeNull();
});
