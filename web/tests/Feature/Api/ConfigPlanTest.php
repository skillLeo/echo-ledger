<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\Workspace;
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

it('names the workspace plan with every feature on, the B20 limits and what is used', function () {
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
