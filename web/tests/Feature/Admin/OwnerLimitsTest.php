<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Plans;
use App\Filament\Admin\Pages\Settings;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Plan;
use App\Services\Assist\AssistPlans;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * The owner's decisions of 2026-11-17, editable by an admin: Trash keeps a deleted recording 48 hours (Admin › Settings
 * › Storage, 1 to 720, the apps' `trash.keep_hours`), and Live Assist's allowances per plan (Admin › Plans): Free
 * Practice 30 minutes and $1, Pro 5 hours and $15, Ultra 10 hours and $60.
 */

describe('Trash', function () {
    it('keeps a deleted recording 48 hours by default, and the apps read the same', function () {
        $device = Fx::device();
        expect(app(SettingsRepository::class)->trashKeepHours())->toBe(48)->and(CaptureSession::binHours())->toBe(48);

        $this->api(Fx::token($device))->getJson('/api/config')->assertOk()->assertJsonPath('trash.keep_hours', 48);
    });

    it('lets an admin change it in Settings › Storage, 1 to 720 hours, with an audit entry, and everything follows', function () {
        $admin = Fx::superAdmin();
        $this->actingAs($admin);
        Filament::setCurrentPanel('app');

        foreach ([0, 721, -5] as $wrong) {
            Livewire::test(Settings::class)->fillForm(['trash_keep_hours' => $wrong])->call('save')->assertHasFormErrors(['trash_keep_hours']);
        }
        Livewire::test(Settings::class)->assertFormSet(['trash_keep_hours' => 48])
            ->fillForm(['trash_keep_hours' => 24])->call('save')->assertHasNoFormErrors();

        expect(app(SettingsRepository::class)->trashKeepHours())->toBe(24)
            ->and(AuditEvent::query()->where('event', 'settings_changed')->latest('id')->value('meta')['keys'] ?? [])->toContain('trash.keep_hours');

        // The API, the bin and its erasing follow: 30 hours ago is past 24.
        $device = Fx::device();
        $this->api(Fx::token($device))->getJson('/api/config')->assertJsonPath('trash.keep_hours', 24);
        $old = Fx::session($device->user, $device);
        $old->delete();
        $old->forceFill(['deleted_at' => now()->subHours(30)])->saveQuietly();
        expect($old->fresh()->restorable())->toBeFalse();

        $this->actingAs($admin);
        Filament::setCurrentPanel('app');
        foreach ([1, 720] as $edge) {
            Livewire::test(Settings::class)->fillForm(['trash_keep_hours' => $edge])->call('save')->assertHasNoFormErrors();
            expect(app(SettingsRepository::class)->trashKeepHours())->toBe($edge);
        }
        expect($old->fresh()->restorable())->toBeTrue();
    });

    it('never reads outside 1 to 720 hours, whatever is stored', function () {
        $settings = app(SettingsRepository::class);
        $settings->set('trash.keep_hours', 5000);
        expect($settings->trashKeepHours())->toBe(720);
        $settings->set('trash.keep_hours', 0);
        expect($settings->trashKeepHours())->toBe(1);
        $settings->set('trash.keep_hours', 'nonsense');
        expect($settings->trashKeepHours())->toBe(48);
    });
});

describe('Live Assist plan limits', function () {
    it('starts with the owner\'s values on Free, Pro and Ultra, and Live Assist reads them', function () {
        $plans = Plan::query()->whereIn('key', ['free', 'pro', 'business'])->get()->keyBy('key');

        expect($plans['free']->only(['assist_minutes', 'practice_minutes']))->toBe(['assist_minutes' => 0, 'practice_minutes' => 30])
            ->and((float) $plans['free']->practice_usd)->toBe(1.0)
            ->and($plans['pro']->assist_minutes)->toBe(300)->and((float) $plans['pro']->assist_usd)->toBe(15.0)
            ->and($plans['business']->assist_minutes)->toBe(600)->and((float) $plans['business']->assist_usd)->toBe(60.0);

        expect(AssistPlans::config('free', $plans['free'])['practice'])->toBe(['seconds' => 1800, 'cents' => 100])
            ->and(AssistPlans::config('pro', $plans['pro']))->toMatchArray(['seconds' => 5 * 3600, 'cents' => 1500])
            ->and(AssistPlans::config('business', $plans['business']))->toMatchArray(['seconds' => 10 * 3600, 'cents' => 6000]);
    });

    it('lets an admin change them per plan in Admin › Plans, validated, and an empty box is the default', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        Filament::setCurrentPanel('admin');
        $pro = Plan::query()->where('key', 'pro')->firstOrFail();

        Livewire::test(Plans::class)->callTableAction('edit', $pro, ['assist_minutes' => -1])->assertHasTableActionErrors(['assist_minutes']);
        Livewire::test(Plans::class)->callTableAction('edit', $pro, ['assist_usd' => 10001])->assertHasTableActionErrors(['assist_usd']);
        Livewire::test(Plans::class)->callTableAction('edit', $pro, ['assist_minutes' => 480, 'assist_usd' => 20.5, 'practice_minutes' => 120, 'practice_usd' => 2])
            ->assertHasNoTableActionErrors();

        $pro = $pro->fresh();
        expect(AssistPlans::config('pro', $pro))->toMatchArray(['seconds' => 480 * 60, 'cents' => 2050, 'practice' => ['seconds' => 7200, 'cents' => 200]])
            ->and(AuditEvent::query()->where('event', 'plan_changed')->exists())->toBeTrue();

        Livewire::test(Plans::class)->callTableAction('edit', $pro, ['assist_minutes' => null, 'assist_usd' => null])->assertHasNoTableActionErrors();
        // Empty: config/assist.php's value for the plan.
        expect(AssistPlans::config('pro', $pro->fresh()))->toMatchArray(['seconds' => 5 * 3600, 'cents' => 1500]);
    });

    it('migrates up and down: only the plans change, and down drops the columns', function () {
        $migration = require database_path('migrations/2026_12_17_000001_add_live_assist_limits_to_plans.php');
        $workspaces = DB::table('workspaces')->count();

        $migration->down();
        expect(Schema::hasColumn('plans', 'assist_minutes'))->toBeFalse();
        $migration->up();
        expect(Schema::hasColumns('plans', ['assist_minutes', 'assist_usd', 'practice_minutes', 'practice_usd']))->toBeTrue()
            ->and(Plan::query()->where('key', 'pro')->value('assist_minutes'))->toBe(300)
            ->and(DB::table('workspaces')->count())->toBe($workspaces);
    });
});
