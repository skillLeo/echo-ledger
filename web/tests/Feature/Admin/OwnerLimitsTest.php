<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\Plans;
use App\Filament\Admin\Pages\Settings;
use App\Models\AssistSession;
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

require_once __DIR__.'/../Assist/Support/assist_helpers.php';

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

    it('says the server\'s Trash time in the privacy policy: whole days in days, else hours', function () {
        foreach ([720 => '30 days', 48 => '2 days', 36 => '36 hours', 24 => '1 day', 1 => '1 hour'] as $hours => $words) {
            // The repository is scoped: the one this request uses, each time.
            app(SettingsRepository::class)->set('trash.keep_hours', $hours);
            $this->get(route('legal.privacy'))->assertOk()->assertSee("deleted ones wait {$words} in the undo bin");
        }
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
            ->and(AuditEvent::query()->where('event', 'plan_changed')->latest('id')->value('meta')['changes']['assist_minutes'] ?? null)->toBe(['from' => 300, 'to' => 480]);
        // The changed fields with their old and new values; unchanged ones are not listed.
        $changes = AuditEvent::query()->where('event', 'plan_changed')->latest('id')->value('meta')['changes'];
        expect($changes)->toHaveKeys(['assist_minutes', 'assist_usd', 'practice_minutes', 'practice_usd'])->not->toHaveKey('name');

        Livewire::test(Plans::class)->callTableAction('edit', $pro, ['assist_minutes' => null, 'assist_usd' => null])->assertHasNoTableActionErrors();
        // Empty: config/assist.php's value for the plan.
        expect(AssistPlans::config('pro', $pro->fresh()))->toMatchArray(['seconds' => 5 * 3600, 'cents' => 1500]);
    });

    it('stops at nothing left, never below, when a plan is lowered under this month\'s use', function () {
        $device = assistPerson('pro');
        Plan::query()->where('key', 'pro')->update(['assist_minutes' => 300]);
        AssistSession::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'mode' => 'fast', 'practice' => false,
            'platform' => 'desktop', 'status' => 'ended', 'started_at' => now()->startOfMonth()->addHour(), 'ended_at' => now()->startOfMonth()->addHours(7), 'seconds_used' => 6 * 3600]);
        $plans = app(AssistPlans::class);

        expect($plans->left((int) $device->workspace_id, (int) $device->user_id, false)['seconds_left'])->toBe(0);
        try {
            $plans->assertLeft((int) $device->workspace_id, (int) $device->user_id, false);
            $this->fail('Live Assist started past its allowance.');
        } catch (ApiError $e) {
            expect($e->getStatusCode())->toBe(402)->and($e->apiCode())->toBe('budget_exhausted');
        }
        $this->api(Fx::token($device))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'fast'])
            ->assertStatus(402)->assertJsonPath('code', 'budget_exhausted');
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
