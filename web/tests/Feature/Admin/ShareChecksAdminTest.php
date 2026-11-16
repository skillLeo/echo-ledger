<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ShareChecks;
use App\Models\ShareCheckResult;
use App\Models\StaffRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Admin → Diagnostics → Screen-share checks (since v1.8.1): the only way anyone sees Share Check results, as counts per
| system, app version and way of checking; for staff with the device-reports permission.
*/

describe('Admin → Diagnostics → Screen-share checks', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
    });

    function shareCheckRow(array $overrides = []): array
    {
        return ['platform' => 'windows', 'os_build' => '10.0.26100', 'app_version' => '1.8.1', 'method' => 'wgc', 'result' => 'pass', ...$overrides];
    }

    function shareResult(array $attributes = []): ShareCheckResult
    {
        $device = Fx::device();
        $result = ShareCheckResult::query()->create([...['user_id' => $device->user_id, 'device_id' => $device->id], ...shareCheckRow(), ...$attributes]);
        if (isset($attributes['created_at'])) {
            $result->forceFill(['created_at' => $attributes['created_at']])->save();
        }

        return $result;
    }

    function staffWithPermissions(array $permissions): User
    {
        $role = StaffRole::query()->create(['key' => 'k'.uniqid(), 'name' => 'Role '.uniqid(), 'permissions' => $permissions, 'is_system' => false]);
        $user = Fx::user();
        $user->forceFill(['staff_role_id' => $role->id, 'app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();

        return $user->fresh();
    }

    it('opens to staff with the device-reports permission only', function () {
        $this->actingAs(staffWithPermissions(['devices.reports']), 'web')->get(ShareChecks::getUrl(panel: 'admin'))->assertOk()->assertSee('Screen-share checks');
        auth()->logout();
        $this->actingAs(staffWithPermissions(['customers.view', 'usage.view']), 'web')->get(ShareChecks::getUrl(panel: 'admin'))->assertForbidden();
        auth()->logout();
        $this->actingAs(Fx::user(), 'web')->get(ShareChecks::getUrl(panel: 'admin'))->assertForbidden();
    });

    it('counts pass, fail and error per group with the last date, newest group first', function () {
        $win = ['platform' => 'windows', 'os_build' => '10.0.26100', 'app_version' => '1.8.1', 'method' => 'wgc'];
        foreach (['pass', 'pass', 'pass', 'fail', 'error'] as $i => $result) {
            shareResult([...$win, 'result' => $result, 'created_at' => now()->subDays(10 - $i)]);
        }
        $zoom = ['platform' => 'macos', 'os_build' => '27.3', 'app_version' => '1.8.1', 'method' => 'guided', 'meeting_app' => 'zoom', 'meeting_app_version' => '6.4.1', 'share_mode' => 'screen'];
        shareResult([...$zoom, 'result' => 'fail', 'created_at' => now()->subDay()]);
        shareResult([...$zoom, 'share_mode' => 'window', 'result' => 'pass', 'created_at' => now()->subDays(2)]);
        shareResult(['platform' => 'macos', 'os_build' => '27.3', 'app_version' => '1.8.1', 'method' => 'sck', 'result' => 'pass', 'created_at' => now()->subDays(20)]);

        $rows = ShareChecks::matrix()->get()->map(fn (ShareCheckResult $r): array => [
            $r->platform, $r->method, $r->share_mode, (int) $r->getAttribute('passed'), (int) $r->getAttribute('failed'), (int) $r->getAttribute('errored'),
        ])->all();

        expect($rows)->toBe([
            ['macos', 'guided', 'screen', 0, 1, 0],
            ['macos', 'guided', 'window', 1, 0, 0],
            ['windows', 'wgc', null, 3, 1, 1],
            ['macos', 'sck', null, 1, 0, 0],
        ]);
        $windows = ShareChecks::matrix()->get()->firstWhere('method', 'wgc');
        expect(Carbon::parse((string) $windows->getAttribute('last_at'))->toDateString())->toBe(now()->subDays(6)->toDateString());
    });

    it('shows the matrix with no people and filters it by system and result', function () {
        $alice = Fx::user(['name' => 'Alice Private', 'email' => 'alice.private@example.test']);
        $aliceDevice = Fx::device($alice, ['name' => 'ALICE-LAPTOP']);
        ShareCheckResult::query()->create([...shareCheckRow(['result' => 'fail', 'os_build' => '10.0.19045']), 'user_id' => $alice->id, 'device_id' => $aliceDevice->id]);
        shareResult(['platform' => 'macos', 'os_build' => '27.3', 'method' => 'guided', 'meeting_app' => 'meet', 'meeting_app_version' => '142', 'share_mode' => 'tab']);

        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(ShareChecks::class)
            ->assertSee('Windows 10.0.19045')->assertSee('Windows Graphics Capture')
            ->assertSee('macOS 27.3')->assertSee('Google Meet 142, a tab')
            ->assertDontSee('Alice Private')->assertDontSee('alice.private@example.test')->assertDontSee('ALICE-LAPTOP')
            ->filterTable('platform', 'macos')->assertSee('macOS 27.3')->assertDontSee('Windows 10.0.19045')
            ->resetTableFilters()
            ->filterTable('result', 'fail')->assertSee('Windows 10.0.19045')->assertDontSee('macOS 27.3');
    });

    it('says so when nothing has arrived', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(ShareChecks::class)->assertSee('No screen-share checks yet')
            ->assertSee('In the desktop app: Settings → Screen-share privacy → Check screen-share privacy, with Send diagnostics on.')->assertDontSee('Check now');
    });
});
