<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\MissedRecordings;
use App\Models\Device;
use App\Models\MissedRecording;
use App\Models\StaffRole;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Admin → Diagnostics → Missed recordings (since v1.8, P0 0.2): calls the apps saw but did not record, only as counts per
| device and reason over the last 7 and 30 days, for staff with the device-reports permission. The API that fills it:
| Api/MissedRecordingsTest.
*/

describe('Admin → Diagnostics → Missed recordings', function () {
    beforeEach(function () {
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->travelTo(Carbon::parse('2026-11-16 12:00:00'));
    });

    function missedCall(Device $device, string $reason, int $daysAgo, array $more = []): MissedRecording
    {
        return unscoped(fn (): MissedRecording => MissedRecording::query()->create([
            'user_id' => $device->user_id, 'device_id' => $device->id, 'workspace_id' => $device->workspace_id, 'client_ref' => (string) Str::uuid(),
            'platform' => $device->platform->value, 'app_version' => '1.8.0', 'source_app' => 'whatsapp', 'call_started_at' => now()->subDays($daysAgo)->subMinutes(5),
            'call_duration_s' => 60, 'reason' => $reason, ...$more,
        ]));
    }

    function missedStaff(array $permissions): User
    {
        $role = StaffRole::query()->create(['key' => 'k'.uniqid(), 'name' => 'Role '.uniqid(), 'permissions' => $permissions, 'is_system' => false]);
        $user = Fx::user();
        $user->forceFill(['staff_role_id' => $role->id, 'app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();

        return $user->fresh();
    }

    /** @return array{phone: Device, laptop: Device, gone: string} three people's devices with calls; the third device was removed */
    function missedWorld(): array
    {
        $phone = Fx::device(Fx::user(['email' => 'ana@example.test']), ['name' => 'samsung SM-S938B', 'platform' => 'android', 'app_version' => '1.8.0']);
        foreach ([1, 2, 3, 10, 20] as $days) {
            missedCall($phone, 'helper_not_armed', $days);
        }
        missedCall($phone, 'permission', 8);
        missedCall($phone, 'permission', 9);
        // Older than 30 days: not counted.
        missedCall($phone, 'permission', 31);
        $laptop = Fx::device(Fx::user(['email' => 'omar@example.test']), ['name' => 'Omar work laptop', 'name_chosen' => true, 'platform' => 'windows', 'os_version' => '11', 'app_version' => '1.8.0']);
        missedCall($laptop, 'mic_busy', 0, ['source_app' => 'zoom']);
        missedCall($laptop, 'mic_busy', 6, ['source_app' => 'zoom']);
        $removed = Fx::device(Fx::user(['email' => 'sara@example.test']));
        missedCall($removed, 'crash', 2);
        $removed->delete();

        return ['phone' => $phone, 'laptop' => $laptop, 'gone' => 'sara@example.test'];
    }

    it('opens to staff with the device-reports permission only', function () {
        $this->actingAs(missedStaff(['devices.reports']), 'web')->get(MissedRecordings::getUrl(panel: 'admin'))->assertOk()->assertSee('Missed recordings');
        auth()->logout();
        $this->actingAs(missedStaff(['customers.view', 'usage.view']), 'web')->get(MissedRecordings::getUrl(panel: 'admin'))->assertForbidden();
        auth()->logout();
        $this->actingAs(Fx::user(), 'web')->get(MissedRecordings::getUrl(panel: 'admin'))->assertForbidden();
    });

    it('counts per device and reason over the last 7 and 30 days, the most recent trouble first', function () {
        ['phone' => $phone, 'laptop' => $laptop] = missedWorld();

        $rows = MissedRecordings::counts()->get()->map(fn (MissedRecording $r): array => [
            $r->device_id, $r->user?->email, $r->reason, (int) $r->getAttribute('last_7_days'), (int) $r->getAttribute('last_30_days'),
        ])->all();

        expect($rows)->toBe([
            [$phone->id, 'ana@example.test', 'helper_not_armed', 3, 5],
            [$laptop->id, 'omar@example.test', 'mic_busy', 2, 2],
            [null, 'sara@example.test', 'crash', 1, 1],
            [$phone->id, 'ana@example.test', 'permission', 0, 2],
        ]);
        $helper = MissedRecordings::counts()->get()->first();
        expect(Carbon::parse((string) $helper->getAttribute('last_at'))->toDateTimeString())->toBe('2026-11-15 11:55:00');
    });

    it('shows the device, its person and the reason, and nothing of the calls themselves', function () {
        missedWorld();
        $secret = Fx::device(Fx::user(['email' => 'lee@example.test']), ['name' => 'Lee phone', 'name_chosen' => true]);
        missedCall($secret, 'detection', 4, ['detail' => 'SECRET-DETAIL from the app', 'client_ref' => 'SECRET-REF', 'source_app' => 'microsoft_teams', 'call_duration_s' => 4321]);

        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(MissedRecordings::class)
            ->assertSee('Galaxy S25 Ultra')->assertSee('Android · 1.8.0')->assertSee('ana@example.test')->assertSee('Helper not connected')
            ->assertSee('Omar work laptop')->assertSee('Windows · 1.8.0')->assertSee('Microphone busy')
            ->assertSee('A removed device')->assertSee('sara@example.test')->assertSee('App stopped')
            ->assertSee('Lee phone')->assertSee('Call not detected')
            ->assertDontSee('SECRET-DETAIL')->assertDontSee('SECRET-REF')->assertDontSee('Microsoft Teams')->assertDontSee('1:12:01');
    });

    it('filters by reason and by system', function () {
        missedWorld();

        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(MissedRecordings::class)
            ->filterTable('reason', 'mic_busy')->assertSee('Omar work laptop')->assertDontSee('Galaxy S25 Ultra')->assertDontSee('sara@example.test')
            ->resetTableFilters()
            ->filterTable('platform', 'android')->assertSee('Galaxy S25 Ultra')->assertSee('sara@example.test')->assertDontSee('Omar work laptop');
    });

    it('shows forty devices with the queries of four: none per row', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            Livewire::test(MissedRecordings::class)->set('tableRecordsPerPage', 50)->assertOk();
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        foreach (range(1, 4) as $_) {
            missedCall(Fx::device(), 'crash', 1);
        }
        $few = $queries();
        foreach (range(1, 36) as $_) {
            missedCall(Fx::device(), 'crash', 1);
        }

        expect($queries())->toBeLessThanOrEqual($few + 2);
    });

    it('says so when nothing has arrived in the last 30 days', function () {
        missedCall(Fx::device(Fx::user(), ['name' => 'Old phone', 'name_chosen' => true]), 'crash', 40);

        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(MissedRecordings::class)->assertSee('No missed recordings in the last 30 days')->assertDontSee('Old phone');
    });
});
