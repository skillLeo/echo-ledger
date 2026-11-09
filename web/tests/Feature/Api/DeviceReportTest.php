<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Admin\Pages\DeviceReports;
use App\Models\DeviceReport;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| POST /api/devices/report (since v1.5): the device check an app chose to send, and who sees it in the console.
*/

function reportBody(array $overrides = []): array
{
    return [
        'platform' => 'android', 'app_version' => '1.5.0', 'brand' => 'Xiaomi', 'model' => '23078PND5G', 'os_version' => '14',
        'checks' => [
            ['key' => 'voice_notes', 'state' => 'ok', 'detail' => null],
            ['key' => 'app_calls', 'state' => 'limited', 'detail' => 'Your voice only.'],
        ],
        ...$overrides,
    ];
}

beforeEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('app'));
});

it('keeps the last report of a device, in that device\'s workspace', function () {
    $user = Fx::user();
    $device = Fx::device($user);
    $token = Fx::token($device);

    $this->api($token)->postJson('/api/devices/report', reportBody())->assertCreated()->assertJsonStructure(['data' => ['reported_at']]);
    $this->api($token)->postJson('/api/devices/report', reportBody(['os_version' => '15']))->assertCreated();

    $reports = DeviceReport::query()->withoutGlobalScopes()->where('device_id', $device->id)->get();
    expect($reports)->toHaveCount(1)
        ->and($reports[0]->os_version)->toBe('15')
        ->and($reports[0]->user_id)->toBe($user->id)
        ->and($reports[0]->workspace_id)->toBe($device->workspace_id)
        ->and($reports[0]->summary())->toBe('1 work · 1 limited');
});

it('lets a viewer\'s device send one', function () {
    $owner = Fx::user();
    $viewer = Fx::user();
    $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
    app(Workspaces::class)->addMember($workspace, $viewer, WorkspaceRole::Viewer);
    $viewer->forceFill(['current_workspace_id' => $workspace->id])->save();

    $this->api(Fx::token(Fx::device($viewer)))->postJson('/api/devices/report', reportBody())->assertCreated();
});

it('refuses what is not a report', function (array $change) {
    $this->api(Fx::token(Fx::device()))->postJson('/api/devices/report', reportBody($change))->assertStatus(422)->assertJsonPath('code', 'validation_failed');
})->with([
    'no checks' => [['checks' => []]],
    'an unknown platform' => [['platform' => 'toaster']],
    'an unknown state' => [['checks' => [['key' => 'a', 'state' => 'great']]]],
    'a key that is not one' => [['checks' => [['key' => 'A key!', 'state' => 'ok']]]],
    'too long a detail' => [['checks' => [['key' => 'a', 'state' => 'ok', 'detail' => str_repeat('x', 301)]]]],
]);

it('needs a device and slows down after ten an hour', function () {
    $this->postJson('/api/devices/report', reportBody())->assertUnauthorized();

    $device = Fx::device();
    $token = Fx::token($device);
    RateLimiter::clear('device-report:'.$device->getAuthIdentifier());
    foreach (range(1, 10) as $_) {
        $this->api($token)->postJson('/api/devices/report', reportBody())->assertCreated();
    }
    $this->api($token)->postJson('/api/devices/report', reportBody())->assertStatus(429)->assertHeader('Retry-After');
});

it('goes with its device', function () {
    $device = Fx::device();
    $this->api(Fx::token($device))->postJson('/api/devices/report', reportBody())->assertCreated();

    $device->delete();

    expect(DeviceReport::query()->withoutGlobalScopes()->count())->toBe(0);
});

describe('System → Device reports', function () {
    function reportOf(User $user, string $model): DeviceReport
    {
        $device = Fx::device($user);

        return DeviceReport::query()->withoutGlobalScopes()->create([
            'device_id' => $device->id, 'user_id' => $user->id, 'workspace_id' => $device->workspace_id, 'platform' => 'android',
            'brand' => 'Xiaomi', 'model' => $model, 'os_version' => '14', 'app_version' => '1.5.0',
            'checks' => [['key' => 'app_calls', 'state' => 'limited', 'detail' => '<b>Your voice only.</b>']], 'reported_at' => now(),
        ]);
    }

    it('is on the admin panel since v1.6: staff see every device\'s, an owner none', function () {
        $owner = Fx::user();
        $other = Fx::user();
        $mine = reportOf($owner, 'Mine-1');
        $theirs = reportOf($other, 'Theirs-1');

        $this->actingAs($owner, 'web')->get(DeviceReports::getUrl(panel: 'admin'))->assertForbidden();

        $admin = Fx::user();
        $admin->forceFill(['is_super_admin' => true])->save();
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel('admin');
        Livewire::test(DeviceReports::class)->assertCanSeeTableRecords([$mine, $theirs])->assertSee('Mine-1')->assertSee('Theirs-1');
    });

    it('is closed to members and viewers', function () {
        $owner = Fx::user();
        $member = Fx::user();
        $workspace = Workspace::query()->findOrFail($owner->current_workspace_id);
        app(Workspaces::class)->addMember($workspace, $member, WorkspaceRole::Member);
        $member->forceFill(['current_workspace_id' => $workspace->id])->save();

        $this->actingAs($member, 'web')->get(DeviceReports::getUrl(panel: 'admin'))->assertForbidden();
    });

    it('prints what a phone sent as text', function () {
        $html = view('filament.pages.device-report', ['report' => reportOf(Fx::user(), 'Mine-1')])->render();

        expect($html)->toContain('&lt;b&gt;Your voice only.&lt;/b&gt;')->not->toContain('<b>Your voice only.')->toContain('app calls');
    });
});
