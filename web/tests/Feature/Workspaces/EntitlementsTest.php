<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\Plans;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\Invitations;
use App\Services\CredentialVault;
use App\Services\DoubleCheck;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use App\Stt\LanguageRouter;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Entitlements (since v1.6, P1.2, docs/API.md "Entitlements"): plans grant features and limits, a person's overrides
| come on top, a plan that ends falls back to the default plan, and every paid route asks.
*/

uses(ApiClient::class);

beforeEach(function () {
    Mail::fake();
    Fx::fakeRecordingsDisk();
    // The tests make their own plans on offer; the default plans stay out of the comparison.
    Plan::query()->update(['on_offer' => false, 'is_default' => false]);
});

afterEach(fn () => Fx::cleanupDisks());

/** A plan on offer granting [$features] (every other paid feature off). */
function offerPlan(string $key, int $order, array $features, array $attributes = []): Plan
{
    return Plan::query()->create(['key' => $key, 'name' => Str::headline($key), 'badge' => strtoupper($key), 'color' => '#7c3aed', 'on_offer' => true, 'sort_order' => $order,
        'features' => array_merge(array_fill_keys(Plan::FEATURES, false), array_fill_keys($features, true)), ...$attributes]);
}

function putOnPlan(User $user, Plan $plan, ?string $until = null): Workspace
{
    $workspace = Workspace::query()->findOrFail($user->current_workspace_id);
    $workspace->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => $until])->save();

    return $workspace;
}

describe('GET /api/me/entitlements', function () {
    it('answers the plan, every feature with the plan that has it, every limit with its use, the engines and the plans on offer', function () {
        $free = offerPlan('free_test', 0, [], ['max_devices' => 2, 'storage_bytes' => 1024 ** 3, 'retention_days' => 30, 'seats' => 1, 'monthly_minutes' => 0,
            'max_file_minutes' => 180, 'uploads_per_day' => 50, 'ai_credits_usd' => 0, 'engines' => ['own_groq', 'on_device'], 'color' => '#64748b']);
        offerPlan('business_test', 20, Plan::FEATURES, ['price_label' => 'Rs 9,000 a month']);
        offerPlan('pro_test', 10, ['platform_transcription', 'views', 'double_check', 'screen_moments', 'connector'], ['price_label' => 'Rs 2,000 a month', 'max_devices' => 5]);
        $person = Fx::user();
        putOnPlan($person, $free);
        $device = Fx::device($person);
        Fx::session($person, $device);

        $body = $this->api(Fx::token($device))->getJson('/api/me/entitlements')->assertOk()->json();

        expect(array_keys($body))->toBe(['plan', 'features', 'limits', 'engines', 'transcription_key', 'upgrade_request', 'plans'])
            ->and($body['plan'])->toBe(['key' => 'free_test', 'name' => 'Free Test', 'badge' => 'FREE_TEST', 'color' => '#64748b', 'expires_at' => null, 'trial_minutes_left' => 0])
            ->and(array_keys($body['features']))->toBe(Features::keys())
            ->and($body['features']['record'])->toMatchArray(['enabled' => true, 'cost' => 'free', 'lowest_plan' => null])
            ->and($body['features']['platform_transcription'])->toMatchArray(['enabled' => false, 'name' => 'Transcription on EchoLedger', 'cost' => 'paid', 'lowest_plan' => 'pro_test'])
            ->and($body['features']['live_translate'])->toMatchArray(['enabled' => false, 'lowest_plan' => 'business_test'])
            ->and($body['limits'])->toBe([
                'monthly_minutes' => ['limit' => 0, 'used' => 0], 'devices' => ['limit' => 2, 'used' => 1],
                'storage_bytes' => ['limit' => 1073741824, 'used' => 0], 'retention_days' => ['limit' => 30],
                'seats' => ['limit' => 1, 'used' => 1], 'max_file_minutes' => ['limit' => 180],
                'uploads_per_day' => ['limit' => 50, 'used' => 1], 'ai_usd_month' => ['limit' => 0, 'used' => 0],
            ])
            ->and($body['engines'])->toBe(['own_groq', 'on_device'])
            ->and($body['transcription_key'])->toBe(['provider' => 'groq', 'set' => false, 'last4' => null])
            ->and($body['upgrade_request'])->toBeNull()
            ->and(array_column($body['plans'], 'key'))->toBe(['free_test', 'pro_test', 'business_test'])
            ->and($body['plans'][1])->toMatchArray(['badge' => 'PRO_TEST', 'price' => 'Rs 2,000 a month', 'features' => ['platform_transcription', 'views', 'double_check', 'screen_moments', 'connector']])
            ->and($body['plans'][1]['limits']['devices'])->toBe(5);
    });

    it('applies a person\'s overrides to them only: a feature on or off, a limit up, down or none', function () {
        $free = offerPlan('free_test', 0, [], ['max_devices' => 2, 'storage_bytes' => 1000]);
        $owner = Fx::user();
        $team = putOnPlan($owner, $free);
        $member = Fx::user();
        app(Workspaces::class)->addMember($team, $member);
        app(Workspaces::class)->switchTo($member, $team);
        $owner->forceFill(['entitlement_overrides' => ['features' => ['views' => true, 'record' => false, 'nonsense' => true], 'limits' => ['devices' => 10, 'storage_bytes' => null, 'retention_days' => 1]]])->save();

        $mine = $this->api(Fx::token(Fx::device($owner)))->getJson('/api/me/entitlements')->assertOk()->json();
        $theirs = $this->api(Fx::token(Fx::device($member->refresh())))->getJson('/api/me/entitlements')->assertOk()->json();

        expect($mine['features']['views']['enabled'])->toBeTrue()
            // A free feature is in every plan, whatever an override says; overrides of unknown keys are ignored.
            ->and($mine['features']['record']['enabled'])->toBeTrue()
            ->and($mine['limits']['devices']['limit'])->toBe(10)
            ->and($mine['limits']['storage_bytes']['limit'])->toBeNull()
            // Retention is the workspace's: its owner's override counts, for everyone in it.
            ->and($mine['limits']['retention_days']['limit'])->toBe(1)
            ->and($theirs['limits']['retention_days']['limit'])->toBe(1)
            ->and($theirs['features']['views']['enabled'])->toBeFalse()
            ->and($theirs['limits']['devices']['limit'])->toBe(2)
            ->and($theirs['limits']['storage_bytes']['limit'])->toBe(1000);
    });

    it('falls back to the default plan once the plan\'s end date has passed', function () {
        $default = offerPlan('starter', 0, [], ['is_default' => true]);
        $pro = offerPlan('pro_test', 10, ['views']);
        $person = Fx::user();
        putOnPlan($person, $pro, now()->addDay()->toDateTimeString());
        $token = Fx::token(Fx::device($person));

        $this->api($token)->getJson('/api/me/entitlements')->assertJsonPath('plan.key', 'pro_test')->assertJsonPath('features.views.enabled', true)
            ->assertJsonPath('plan.expires_at', fn (?string $at): bool => $at !== null);
        $this->travel(2)->days();
        $this->api($token)->getJson('/api/me/entitlements')->assertJsonPath('plan.key', 'starter')->assertJsonPath('features.views.enabled', false);
        expect(Plan::defaultPlan()->is($default))->toBeTrue();
    });

    it('keeps the config\'s plan block for older apps, made from the entitlements', function () {
        $plan = offerPlan('free_test', 0, ['screen_moments'], ['storage_bytes' => 5000]);
        $person = Fx::user();
        putOnPlan($person, $plan);
        $person->forceFill(['entitlement_overrides' => ['features' => ['live_translate' => true], 'limits' => ['storage_bytes' => 9000]]])->save();

        $config = $this->api(Fx::token(Fx::device($person)))->getJson('/api/config')->assertOk()->json('plan');
        expect($config['features'])->toBe(['live_translate' => true, 'screen_moments' => true, 'ai_notes' => false, 'connector' => false])
            ->and($config['limits']['storage_bytes'])->toBe(9000);
    });
});

describe('every paid route asks', function () {
    it('names the plan that has a locked feature', function () {
        offerPlan('pro_test', 10, ['screen_moments']);
        $person = Fx::user();
        putOnPlan($person, offerPlan('free_test', 0, []));
        $session = Fx::session($person, $device = Fx::device($person));

        $this->api(Fx::token($device))->postJson("/api/sessions/{$session->id}/screens", ['at_ms' => 0])
            ->assertForbidden()->assertJson(['code' => 'feature_not_in_plan', 'feature' => 'screen_moments', 'lowest_plan' => 'pro_test']);
    });

    it('locks the transcript views and does not make them by itself without the feature', function () {
        app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
        $person = Fx::user();
        putOnPlan($person, offerPlan('free_test', 0, []));
        $session = Fx::session($person, $device = Fx::device($person), ['language' => 'ur']);
        $transcript = Fx::transcript($session, 'Aap kaise hain');

        $this->api(Fx::token($device))->postJson("/api/transcripts/{$transcript->id}/views")
            ->assertForbidden()->assertJson(['code' => 'feature_not_in_plan', 'feature' => 'views']);
        expect(app(DoubleCheck::class)::entitled($transcript))->toBeFalse();
    });

    it('needs the team feature to invite anyone', function () {
        $owner = Fx::user();
        $team = putOnPlan($owner, offerPlan('free_test', 0, [], ['seats' => 5]));

        expect(fn () => app(Invitations::class)->invite($team, $owner, 'friend@example.test', WorkspaceRole::Member))
            ->toThrow(ApiError::class, 'Team workspaces are not in your plan');
        $owner->forceFill(['entitlement_overrides' => ['features' => ['team' => true]]])->save();
        expect(app(Invitations::class)->invite($team, $owner, 'friend@example.test', WorkspaceRole::Member))->toBeString();
    });

    it('routes a recording past ElevenLabs and Gemini when the plan has no premium engines', function () {
        app(CredentialVault::class)->put('elevenlabs', 'api_key', 'el-test-key');
        app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai-test-key');
        app(SettingsRepository::class)->set('routing.final', ['ar' => ['elevenlabs', 'assemblyai']]);
        $person = Fx::user();
        putOnPlan($person, offerPlan('pro_test', 10, ['platform_transcription']));
        $session = Fx::session($person, Fx::device($person), ['language' => 'ar']);

        expect(app(LanguageRouter::class)->final('ar', SttUseCase::FinalTranscript, $session)->provider)->toBe(SttProviderKey::AssemblyAi)
            ->and(app(LanguageRouter::class)->candidates('ar', $session))->not->toContain(SttProviderKey::ElevenLabs);
        $person->forceFill(['entitlement_overrides' => ['features' => ['premium_engines' => true]]])->save();
        expect(app(LanguageRouter::class)->final('ar', SttUseCase::FinalTranscript, $session->fresh())->provider)->toBe(SttProviderKey::ElevenLabs);
    });
});

describe('limits', function () {
    it('answers limit_reached to v1.6 apps and the old code to older ones', function (string $version, string $code) {
        $person = Fx::user();
        putOnPlan($person, offerPlan('tiny', 0, Plan::FEATURES, ['storage_bytes' => 10]));
        $device = Fx::device($person, ['app_version' => $version]);

        $this->api(Fx::token($device))->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['size_bytes' => 4096])]]))
            ->assertForbidden()->assertJson(['code' => $code, 'limit' => 'storage_bytes']);
    })->with([
        'v1.5' => ['1.5.0', 'storage_limit_reached'],
        'v1.6 beta' => ['1.6.0-beta.1', 'limit_reached'],
        'v1.6' => ['1.6.0', 'limit_reached'],
        'v2' => ['2.0', 'limit_reached'],
    ]);

    it('counts recordings a day per person and refuses one past the plan\'s', function () {
        $person = Fx::user();
        putOnPlan($person, offerPlan('tiny', 0, Plan::FEATURES, ['uploads_per_day' => 2]));
        $token = Fx::token(Fx::device($person, ['app_version' => '1.6.0']));

        foreach ([1, 2] as $i) {
            $this->api($token)->postJson('/api/sessions', Fx::sessionPayload())->assertCreated();
        }
        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload())->assertForbidden()->assertJson(['code' => 'limit_reached', 'limit' => 'uploads_per_day']);
        $this->travel(25)->hours();
        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload())->assertCreated();
    });

    it('refuses a recording longer than the plan allows, when it is made and when it is finished', function () {
        $person = Fx::user();
        putOnPlan($person, offerPlan('tiny', 0, Plan::FEATURES, ['max_file_minutes' => 5]));
        $token = Fx::token(Fx::device($person, ['app_version' => '1.6.0']));

        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['duration_ms' => 6 * 60000, 'artifacts' => [Fx::artifact(['duration_ms' => 6 * 60000])]]))
            ->assertForbidden()->assertJson(['code' => 'limit_reached', 'limit' => 'max_file_minutes']);
        $open = $this->api($token)->postJson('/api/sessions', array_diff_key(Fx::sessionPayload(), ['artifacts' => 1, 'duration_ms' => 1, 'ended_at' => 1]))->assertCreated()->json('session.id');
        $this->api($token)->postJson("/api/sessions/{$open}/finish", ['ended_at' => '2026-10-24T09:10:00Z', 'duration_ms' => 6 * 60000, 'artifacts' => [Fx::artifact(['duration_ms' => 6 * 60000])]])
            ->assertForbidden()->assertJson(['code' => 'limit_reached', 'limit' => 'max_file_minutes']);
        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['duration_ms' => 4 * 60000]))->assertCreated();
    });

    it('lets a person\'s override lift a limit of the plan', function () {
        $person = Fx::user();
        putOnPlan($person, offerPlan('tiny', 0, Plan::FEATURES, ['storage_bytes' => 10]));
        $person->forceFill(['entitlement_overrides' => ['limits' => ['storage_bytes' => null]]])->save();

        $this->api(Fx::token(Fx::device($person)))->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['size_bytes' => 4096])]]))->assertCreated();
        expect(app(Entitlements::class)->limit($person->current_workspace_id, $person->id, 'storage_bytes'))->toBeNull();
    });
});

describe('the Plans page (super admin)', function () {
    beforeEach(fn () => Filament\Facades\Filament::setCurrentPanel('app'));

    it('sets a person\'s overrides and clears them again', function () {
        $admin = Fx::superAdmin();
        $person = Fx::user(['email' => 'nida@example.test']);
        $this->actingAs($admin, 'web');

        Livewire\Livewire::test(Plans::class)
            ->callTableAction('overrides', data: ['user_id' => $person->id, 'override_feature_views' => 'on', 'override_feature_team' => 'off',
                'override_limit_devices' => '7', 'override_limit_storage_bytes' => 'none', 'override_limit_monthly_minutes' => ''])
            ->assertHasNoTableActionErrors();
        expect($person->fresh()->entitlement_overrides)->toBe(['features' => ['views' => true, 'team' => false], 'limits' => ['devices' => 7, 'storage_bytes' => null]])
            ->and(AuditEvent::query()->where('event', 'entitlements_changed')->exists())->toBeTrue();

        // Choosing the person loads what they have; setting everything back to the plan's clears it.
        Livewire\Livewire::test(Plans::class)->mountTableAction('overrides')->setTableActionData(['user_id' => $person->id])
            ->assertTableActionDataSet(['override_feature_views' => 'on', 'override_limit_devices' => '7', 'override_limit_storage_bytes' => 'none'])
            ->setTableActionData(['override_feature_views' => 'plan', 'override_feature_team' => 'plan', 'override_limit_devices' => '', 'override_limit_storage_bytes' => ''])
            ->callMountedTableAction()->assertHasNoTableActionErrors();
        expect($person->fresh()->entitlement_overrides)->toBeNull();
    });

    it('puts a workspace on a plan until a date', function () {
        $admin = Fx::superAdmin();
        $person = Fx::user();
        $pro = offerPlan('pro_test', 10, ['views']);
        $this->actingAs($admin, 'web');

        Livewire\Livewire::test(Plans::class)
            ->callTableAction('assign', $pro, data: ['workspace_id' => $person->current_workspace_id, 'until' => now()->addMonth()->toDateTimeString()])
            ->assertHasNoTableActionErrors();
        $workspace = Workspace::query()->findOrFail($person->current_workspace_id);
        expect($workspace->plan_id)->toBe($pro->id)->and($workspace->plan_expires_at?->isFuture())->toBeTrue();
    });

    it('is for the super admin only', function () {
        $this->actingAs(Fx::user(), 'web');
        $this->get('/admin/plans')->assertForbidden();
    });
});

it('moves a workspace whose plan has ended back to the default plan, every hour', function () {
    $default = offerPlan('starter', 0, [], ['is_default' => true]);
    $person = Fx::user();
    $workspace = putOnPlan($person, offerPlan('pro_test', 10, ['views']), now()->addHour()->toDateTimeString());
    $other = putOnPlan(Fx::user(), $pro = Plan::query()->where('key', 'pro_test')->firstOrFail());

    $this->artisan('echoledger:end-plans')->assertSuccessful();
    expect($workspace->fresh()->plan_id)->toBe($pro->id);
    $this->travel(2)->hours();
    $this->artisan('echoledger:end-plans')->assertSuccessful();
    expect($workspace->fresh()->plan_id)->toBe($default->id)->and($workspace->fresh()->plan_expires_at)->toBeNull()
        ->and($other->fresh()->plan_id)->toBe($pro->id);
    expect(collect(app(Schedule::class)->events())->contains(fn ($e): bool => str_contains((string) $e->command, 'echoledger:end-plans')))->toBeTrue();
});
