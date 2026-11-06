<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * GET /api/config `plan` and `workspace` (since v1.5, docs/API.md "Plans and features"): every v1.5 server sends both.
 * The phone hides what the plan leaves out and shows the role; the desktop takes a config without a plan for a server
 * older than screen moments.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $this->asToken($this->token);
});

it('names the workspace plan with its features, limits and what is used', function () {
    $workspace = Workspace::query()->findOrFail($this->device->workspace_id);
    $plan = Plan::query()->create(['key' => 'team-test', 'name' => 'Team', 'monthly_minutes' => 300, 'storage_bytes' => 5 * 1024 ** 3, 'max_devices' => 5]);
    $workspace->forceFill(['plan_id' => $plan->id])->save();

    $this->getJson('/api/config')->assertOk()->assertJson([
        'plan' => [
            'key' => 'team-test',
            'name' => 'Team',
            'features' => ['live_translate' => true, 'screen_moments' => true, 'ai_notes' => true, 'connector' => true],
            'limits' => ['monthly_minutes' => 300, 'storage_bytes' => 5 * 1024 ** 3, 'retention_days' => null, 'seats' => null],
            'used' => ['minutes' => 0, 'storage_bytes' => 0],
        ],
    ]);
});

it('gives the device its workspace and its person\'s role there', function () {
    $workspace = Workspace::query()->findOrFail($this->device->workspace_id);

    $this->getJson('/api/config')->assertOk()->assertJsonPath('workspace', [
        'id' => $workspace->id,
        'name' => $workspace->name,
        'role' => 'owner',
    ]);
});

it('says null for a limit the plan does not set', function () {
    $workspace = Workspace::query()->findOrFail($this->device->workspace_id);
    $plan = Plan::query()->create(['key' => 'open-test', 'name' => 'Open', 'monthly_minutes' => null, 'storage_bytes' => null, 'max_devices' => null]);
    $workspace->forceFill(['plan_id' => $plan->id])->save();

    $limits = $this->getJson('/api/config')->assertOk()->json('plan.limits');
    expect($limits['monthly_minutes'])->toBeNull()->and($limits['storage_bytes'])->toBeNull();
});

it('says the features the plan leaves out and its retention and seats', function () {
    $workspace = Workspace::query()->findOrFail($this->device->workspace_id);
    $workspace->forceFill(['plan_id' => Plan::free()->id])->save();

    $this->getJson('/api/config')->assertOk()->assertJson([
        'plan' => [
            'key' => 'free',
            'name' => 'Free',
            'features' => ['live_translate' => false, 'screen_moments' => true, 'ai_notes' => false, 'connector' => true],
            'limits' => ['monthly_minutes' => 300, 'storage_bytes' => 5 * 1024 ** 3, 'retention_days' => 30, 'seats' => 1],
        ],
    ]);
});

it('has every feature on and no limits on Personal, and reads a plan that names no features as all on', function () {
    $this->getJson('/api/config')->assertOk()->assertJson([
        'plan' => [
            'key' => 'personal',
            'features' => ['live_translate' => true, 'screen_moments' => true, 'ai_notes' => true, 'connector' => true],
            'limits' => ['monthly_minutes' => null, 'storage_bytes' => null, 'retention_days' => null, 'seats' => null],
        ],
    ]);
    Plan::personal()->update(['features' => null]);
    $this->getJson('/api/config')->assertOk()->assertJsonPath('plan.features.ai_notes', true);
});

it('gives the shorter of the console\'s and the plan\'s retention at the top of the config', function () {
    app(SettingsRepository::class)->set('retention_days', 90);
    expect($this->getJson('/api/config')->assertOk()->json('retention_days'))->toBe(90);

    Plan::personal()->update(['retention_days' => 30]);
    expect($this->getJson('/api/config')->assertOk()->json('retention_days'))->toBe(30);
});

it('names each role, with what is used by the whole workspace', function () {
    $workspace = Workspace::query()->findOrFail($this->device->workspace_id);
    foreach ([WorkspaceRole::Admin, WorkspaceRole::Member, WorkspaceRole::Viewer] as $role) {
        $person = withoutWorkspace(User::factory()->create());
        app(Workspaces::class)->addMember($workspace, $person, $role);
        [, $token] = $this->enroll($person->fresh());
        $this->asToken($token)->getJson('/api/config')->assertOk()->assertJsonPath('workspace.role', $role->value);
    }
});
