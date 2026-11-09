<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6: GET /api/account says whether the person is platform staff (the apps' Admin panel item) and lists every
| workspace they belong to, the device's marked current (docs/API.md "Your account").
*/

uses(InteractsWithDeviceApi::class);

it('lists every workspace of the person with their role there, the device\'s current', function () {
    $ana = Fx::user();
    $owner = Fx::user();
    $team = Workspace::query()->findOrFail($owner->current_workspace_id);
    $team->forceFill(['name' => 'Acme team'])->save();
    app(Workspaces::class)->addMember($team, $ana, WorkspaceRole::Viewer);
    [, $token] = $this->enroll($ana->fresh(), ['config:read']);

    $answer = $this->asToken($token)->getJson('/api/account')->assertOk();

    expect($answer->json('super_admin'))->toBeFalse()
        ->and($answer->json('workspaces'))->toBe([
            ['id' => (int) $ana->current_workspace_id, 'name' => 'Personal', 'role' => 'owner', 'current' => true],
            ['id' => $team->id, 'name' => 'Acme team', 'role' => 'viewer', 'current' => false],
        ]);
});

it('never lists another person\'s workspaces', function () {
    $ana = Fx::user();
    $bob = Fx::user();
    Workspace::query()->whereKey($bob->current_workspace_id)->update(['name' => 'Bob only']);
    [, $token] = $this->enroll($ana, ['config:read']);

    $answer = $this->asToken($token)->getJson('/api/account')->assertOk();

    expect(collect($answer->json('workspaces'))->pluck('id')->all())->toBe([(int) $ana->current_workspace_id])
        ->and(json_encode($answer->json()))->not->toContain('Bob only');
});

it('says true for platform staff only', function () {
    $staff = Fx::superAdmin();
    [, $token] = $this->enroll($staff, ['config:read']);
    $this->asToken($token)->getJson('/api/account')->assertOk()->assertJsonPath('super_admin', true);

    $customer = User::factory()->create();
    [, $other] = $this->enroll($customer, ['config:read']);
    $this->asToken($other)->getJson('/api/account')->assertOk()->assertJsonPath('super_admin', false);
});
