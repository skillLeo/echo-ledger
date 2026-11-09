<?php

declare(strict_types=1);

use App\Exceptions\ApiError;
use App\Filament\Admin\Pages\PromoCodesPage;
use App\Filament\Admin\Pages\UpgradeRequestsPage;
use App\Filament\Auth\EditProfile;
use App\Mail\UpgradeDecidedMail;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Models\StaffRole;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\PromoCodes;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\SettingsRepository;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Promo codes (since v1.6.2, docs/API.md "Promo codes"): one plan, 1–100 % off, an end date and uses if wanted, once per
| person by default; checked in the upgrade sheet; a 100 % code switches the plan on at once. Staff with the Plans
| permission make them; customers never list them, never learn how many uses are left or who used one.
*/

uses(ApiClient::class);

beforeEach(function () {
    Mail::fake();
    app(SettingsRepository::class)->set('upgrade.payment_instructions', 'JazzCash 0300 0000000');
    Plan::query()->where('key', 'pro')->update(['price_amount' => 2999, 'currency' => 'PKR', 'price_label' => null]);
    Plan::query()->where('key', 'business')->update(['name' => 'Ultra', 'badge' => 'ULTRA', 'price_amount' => 7999, 'currency' => 'PKR', 'price_label' => null]);
    $this->person = Fx::user(['name' => 'Nida', 'email' => 'nida@example.test']);
    Workspace::query()->whereKey($this->person->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
    $this->token = Fx::token(Fx::device($this->person));
});

function promo(array $attributes = []): PromoCode
{
    return PromoCode::query()->create([...['code' => 'SAVE50', 'plan' => 'pro', 'percent' => 50, 'months' => 1, 'one_per_person' => true, 'active' => true], ...$attributes]);
}

function promoStaff(string $role): User
{
    $user = Fx::user();
    $user->forceFill(['staff_role_id' => StaffRole::query()->where('key', $role)->value('id'), 'app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();

    return $user->fresh();
}

describe('checking a code', function () {
    it('answers the plan, the price before and after, without case, and nothing about other people', function () {
        promo(['note' => 'For the clinic group']);

        $answer = $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => ' save50 ', 'plan' => 'pro'])->assertOk();
        expect($answer->json())->toBe(['code' => 'SAVE50', 'plan' => 'pro', 'plan_name' => 'Pro', 'percent' => 50,
            'price' => 'Rs 2,999 a month', 'final_price' => 'Rs 1,500 a month', 'free' => false, 'months' => 1]);
        // Without a plan it says which plan it is for.
        $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'SAVE50'])->assertOk()->assertJsonPath('plan', 'pro');
        expect((string) $answer->getContent())->not->toContain('clinic')->not->toContain('uses');
    });

    it('gives one answer for a code that is unknown, off, ended, used up, already used or malformed', function () {
        promo(['code' => 'OFFCODE', 'active' => false]);
        promo(['code' => 'ENDED1', 'ends_at' => now()->subMinute()]);
        $usedUp = promo(['code' => 'USEDUP', 'max_uses' => 1, 'one_per_person' => false]);
        PromoCodeUse::query()->create(['promo_code_id' => $usedUp->id, 'user_id' => Fx::user()->id, 'used_at' => now()]);
        $mine = promo(['code' => 'ONCEONLY']);
        PromoCodeUse::query()->create(['promo_code_id' => $mine->id, 'user_id' => $this->person->id, 'used_at' => now()]);
        Plan::query()->where('key', 'business')->update(['archived_at' => now()]);
        promo(['code' => 'ARCHIVED', 'plan' => 'business']);

        foreach (['NOPE-NOPE', 'OFFCODE', 'ENDED1', 'USEDUP', 'ONCEONLY', 'ARCHIVED', 'x', 'bad code!'] as $code) {
            $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => $code])->assertStatus(422)
                ->assertJsonPath('code', 'invalid_promo_code')->assertJsonPath('message', PromoCodes::INVALID);
        }
    });

    it('names the plan a valid code is for when another plan is asked', function () {
        promo(['code' => 'ULTRA25', 'plan' => 'business', 'percent' => 25]);

        $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'ultra25', 'plan' => 'pro'])->assertStatus(422)
            ->assertJsonPath('code', 'promo_other_plan')->assertJsonPath('plan', 'business')->assertJsonPath('message', 'This code is for Ultra.');
    });

    it('stops guessing: 10 a minute per person and 30 an hour per network', function () {
        for ($i = 0; $i < PromoCodes::PER_MINUTE; $i++) {
            $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'GUESS'.str_pad((string) $i, 3, '0', STR_PAD_LEFT)])->assertStatus(422);
        }
        $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'GUESS999'])->assertStatus(429)->assertJsonPath('code', 'rate_limited');

        // Other people on the same network share the hour's 30.
        $done = PromoCodes::PER_MINUTE;
        while ($done < PromoCodes::PER_HOUR_PER_IP) {
            $token = Fx::token(Fx::device(Fx::user()));
            for ($i = 0; $i < PromoCodes::PER_MINUTE && $done < PromoCodes::PER_HOUR_PER_IP; $i++, $done++) {
                $this->api($token)->postJson('/api/promo-codes/check', ['code' => 'NETWORK'.$done])->assertStatus(422);
            }
        }
        $this->api(Fx::token(Fx::device(Fx::user())))->postJson('/api/promo-codes/check', ['code' => 'NETWORK99'])->assertStatus(429);
    });

    it('needs a signed-in device, and codes can never be listed by a customer', function () {
        promo();
        $this->postJson('/api/promo-codes/check', ['code' => 'SAVE50'])->assertUnauthorized();
        $this->api($this->token)->getJson('/api/promo-codes')->assertNotFound();
        $this->api($this->token)->getJson('/api/promo-codes/SAVE50')->assertNotFound();

        $this->actingAs($this->person, 'web');
        $this->get('/admin/promo-codes')->assertForbidden();
        Filament::setCurrentPanel('admin');
        Livewire::test(PromoCodesPage::class)->assertForbidden();
    });
});

describe('an upgrade request with a code', function () {
    it('keeps the code, the percent and the amount due, waits for staff, and uses the code once', function () {
        promo();

        $created = $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro', 'promo_code' => 'save50'])->assertCreated()
            ->assertJsonPath('status', 'pending')->assertJsonPath('promo_code', 'SAVE50')->assertJsonPath('percent', 50)
            ->assertJsonPath('amount_due', 'Rs 1,500 a month')->assertJsonPath('payment_instructions', 'JazzCash 0300 0000000');
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertOk()
            ->assertJsonPath('promo_code', 'SAVE50')->assertJsonPath('percent', 50)->assertJsonPath('amount_due', 'Rs 1,500 a month');

        $use = PromoCodeUse::query()->sole();
        expect($use->user_id)->toBe($this->person->id)->and($use->upgrade_request_id)->toBe($created->json('id'));
        expect(AuditEvent::query()->where('event', 'promo_code_used')->where('user_id', $this->person->id)->exists())->toBeTrue();
        // Once per person: checked again it is not valid for them any more.
        $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'SAVE50'])->assertStatus(422)->assertJsonPath('code', 'invalid_promo_code');
    });

    it('refuses a code for another plan and a code that is not valid, and makes no request', function () {
        promo(['code' => 'ULTRA25', 'plan' => 'business', 'percent' => 25]);

        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro', 'promo_code' => 'ULTRA25'])->assertStatus(422)
            ->assertJsonPath('code', 'promo_other_plan')->assertJsonPath('plan', 'business');
        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro', 'promo_code' => 'WRONG-CODE'])->assertStatus(422)
            ->assertJsonPath('code', 'invalid_promo_code');
        expect(UpgradeRequest::query()->count())->toBe(0)->and(PromoCodeUse::query()->count())->toBe(0);
    });

    it('switches the plan on at once at 100 %, for the code\'s months, with nothing to pay', function () {
        $this->travelTo(now()->startOfMinute());
        promo(['code' => 'ULTRAFREE', 'plan' => 'business', 'percent' => 100, 'months' => 3]);

        $created = $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'business', 'promo_code' => 'ultrafree'])->assertCreated()
            ->assertJsonPath('status', 'approved')->assertJsonPath('approved_plan', 'business')->assertJsonPath('percent', 100)
            ->assertJsonPath('amount_due', 'Free')->assertJsonPath('payment_instructions', null)
            ->assertJsonPath('approved_until', now()->addMonths(3)->toAtomString());

        $workspace = Workspace::query()->findOrFail($this->person->current_workspace_id);
        expect($workspace->plan->key)->toBe('business')->and($workspace->plan_expires_at?->toAtomString())->toBe(now()->addMonths(3)->toAtomString());
        app(Entitlements::class)->forget();
        $this->api($this->token)->getJson('/api/me/entitlements')->assertJsonPath('plan.key', 'business')->assertJsonPath('features.live_translate.enabled', true);
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertJsonPath('status', 'approved')->assertJsonPath('payment_instructions', null);
        Mail::assertQueued(UpgradeDecidedMail::class);
        expect($created->json('id'))->toBe(UpgradeRequest::query()->sole()->id);
    });

    it('lets a person try their code again after staff decline the request', function () {
        promo();
        $request = app(UpgradeRequests::class)->create($this->person, (int) $this->person->current_workspace_id, 'pro', null, 'SAVE50');
        app(UpgradeRequests::class)->decline($request, Fx::superAdmin(), 'Payment not found');

        expect(PromoCodeUse::query()->count())->toBe(0);
        $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'SAVE50'])->assertOk();
    });

    it('takes a limited code\'s last use only once, and another person\'s use stays theirs', function () {
        promo(['code' => 'ONEONLY', 'max_uses' => 1]);
        $other = Fx::user(['email' => 'omar@example.test']);
        Workspace::query()->whereKey($other->current_workspace_id)->update(['plan_id' => Plan::free()->id]);
        app(UpgradeRequests::class)->create($other, (int) $other->current_workspace_id, 'pro', 'Private note from Omar', 'ONEONLY');

        $this->api($this->token)->postJson('/api/upgrade-requests', ['plan' => 'pro', 'promo_code' => 'ONEONLY'])->assertStatus(422)->assertJsonPath('code', 'invalid_promo_code');
        // Nothing of Omar's reaches Nida: no request of hers, and the refusal names nobody.
        $this->api($this->token)->getJson('/api/upgrade-requests/latest')->assertNotFound();
        $refusal = (string) $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'ONEONLY'])->getContent();
        expect($refusal)->not->toContain('omar')->not->toContain('Omar');

        // A code without a limit is not used up by someone else's use.
        promo(['code' => 'EVERYONE']);
        app(UpgradeRequests::class)->decline(UpgradeRequest::query()->sole(), Fx::superAdmin(), 'No');
        app(UpgradeRequests::class)->create($other, (int) $other->current_workspace_id, 'pro', null, 'EVERYONE');
        $this->api($this->token)->postJson('/api/promo-codes/check', ['code' => 'EVERYONE'])->assertOk();
    });
});

describe('the admin panel', function () {
    it('lets staff with Make and change plans create, edit and switch off codes, with their uses', function () {
        $this->actingAs($staff = promoStaff('billing'), 'web');
        Filament::setCurrentPanel('admin');
        $this->get('/admin/promo-codes')->assertOk()->assertSee('Promo codes');

        Livewire::test(PromoCodesPage::class)
            ->callAction('create', data: ['code' => 'spring-25', 'plan' => 'pro', 'percent' => 25, 'months' => 2, 'one_per_person' => true, 'active' => true, 'note' => 'Spring'])
            ->assertHasNoActionErrors();
        $promo = PromoCode::query()->sole();
        expect($promo->code)->toBe('SPRING-25')->and($promo->percent)->toBe(25)->and($promo->months)->toBe(2)->and($promo->created_by)->toBe($staff->id);

        // The same code in other letters is the same code.
        Livewire::test(PromoCodesPage::class)->callAction('create', data: ['code' => 'Spring-25', 'plan' => 'pro', 'percent' => 10, 'months' => 1])
            ->assertHasActionErrors(['code']);
        Livewire::test(PromoCodesPage::class)->callAction('create', data: ['code' => 'NEW', 'plan' => 'pro', 'percent' => 101, 'months' => 1])
            ->assertHasActionErrors(['code', 'percent']);

        Livewire::test(PromoCodesPage::class)->callTableAction('edit', $promo, data: ['plan' => 'business', 'percent' => 100, 'months' => 1, 'max_uses' => 5, 'one_per_person' => true, 'active' => true])
            ->assertHasNoTableActionErrors();
        expect($promo->fresh()->plan)->toBe('business')->and($promo->fresh()->max_uses)->toBe(5);

        app(UpgradeRequests::class)->create($this->person, (int) $this->person->current_workspace_id, 'business', null, 'spring-25');
        Livewire::test(PromoCodesPage::class)->assertCanSeeTableRecords([$promo])->assertSee('1 of 5')
            ->mountTableAction('uses', $promo)->assertMountedActionModalSee('nida@example.test');

        Livewire::test(PromoCodesPage::class)->callTableAction('toggle', $promo);
        expect($promo->fresh()->active)->toBeFalse();
        expect(AuditEvent::query()->whereIn('event', ['promo_code_created', 'promo_code_changed'])->count())->toBe(3);
    });

    it('shows codes to staff who may only see plans, without changing them, and is closed to the rest', function () {
        $promo = promo();
        $this->actingAs(promoStaff('read_only'), 'web');
        Filament::setCurrentPanel('admin');
        Livewire::test(PromoCodesPage::class)->assertCanSeeTableRecords([$promo])
            ->assertActionHidden('create')->assertTableActionHidden('edit', $promo)->assertTableActionHidden('toggle', $promo);

        $this->actingAs(promoStaff('support'), 'web');
        $this->get('/admin/promo-codes')->assertForbidden();
    });

    it('shows the code and the amount due on each request in the inbox, and offers the code\'s months on approval', function () {
        promo(['months' => 2]);
        $request = app(UpgradeRequests::class)->create($this->person, (int) $this->person->current_workspace_id, 'pro', null, 'SAVE50');
        $this->actingAs(Fx::superAdmin(), 'web');
        Filament::setCurrentPanel('admin');

        Livewire::test(UpgradeRequestsPage::class)->assertCanSeeTableRecords([$request])->assertSee('SAVE50')->assertSee('50 % off')->assertSee('Rs 1,500')
            ->mountTableAction('approve', $request)->assertTableActionDataSet(['until' => now()->addMonths(2)->format('Y-m-d H:i')]);
    });
});

describe('the console\'s upgrade sheet', function () {
    beforeEach(function () {
        $this->actingAs($this->person, 'web');
        Filament::setCurrentPanel('app');
    });

    it('applies a code and shows the new price', function () {
        promo();
        promo(['code' => 'ULTRA25', 'plan' => 'business', 'percent' => 25]);
        Livewire::test(EditProfile::class)->mountAction('upgradeFeature', ['feature' => 'views'])
            ->fillForm(['promo_code' => 'save50'])
            ->callAction(TestAction::make('applyPromo')->schemaComponent('promo_code'))
            ->assertSchemaStateSet(['promo_line' => '50 % off: Rs 1,500 a month instead of Rs 2,999 a month, for 1 month.'], 'mountedActionSchema0')
            // A code for another plan chooses that plan.
            ->fillForm(['plan' => 'pro', 'promo_code' => 'ultra25'])
            ->callAction(TestAction::make('applyPromo')->schemaComponent('promo_code'))
            ->assertSchemaStateSet(['plan' => 'business', 'promo_line' => 'This code is for Ultra. We chose it for you. 25 % off: Rs 5,999 a month instead of Rs 7,999 a month, for 1 month.'], 'mountedActionSchema0')
            // One that is not valid says so, and nothing else.
            ->fillForm(['promo_code' => 'NOT-A-CODE'])
            ->callAction(TestAction::make('applyPromo')->schemaComponent('promo_code'))
            ->assertSchemaStateSet(['promo_line' => PromoCodes::INVALID], 'mountedActionSchema0');
    });

    it('applies a code through the service the sheet uses, choosing the plan the code is for', function () {
        promo(['code' => 'ULTRA25', 'plan' => 'business', 'percent' => 25]);
        $answer = app(PromoCodes::class)->checkThrottled($this->person, 'ultra25', null, '127.0.0.1');
        expect($answer['final_price'])->toBe('Rs 5,999 a month');
        expect(fn () => app(PromoCodes::class)->checkThrottled($this->person, 'ULTRA25', 'pro', '127.0.0.1'))
            ->toThrow(fn (ApiError $e) => expect($e->apiCode())->toBe('promo_other_plan'));
    });

    it('sends a request with a code, and switches the plan on at once with a 100 % code', function () {
        promo();
        Livewire::test(EditProfile::class)->mountAction('upgradeFeature', ['feature' => 'views'])
            ->fillForm(['plan' => 'pro', 'promo_code' => 'SAVE50'])->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified('Request sent. We\'ll activate it once confirmed.');
        expect(UpgradeRequest::query()->sole()->amount_due)->toBe('Rs 1,500 a month');

        UpgradeRequest::query()->update(['status' => UpgradeRequest::DECLINED]);
        promo(['code' => 'ULTRAFREE', 'plan' => 'business', 'percent' => 100]);
        Livewire::test(EditProfile::class)->mountAction('upgradeFeature', ['feature' => 'live_translate'])
            ->fillForm(['plan' => 'business', 'promo_code' => 'ultrafree'])->callMountedAction()->assertHasNoActionErrors()
            ->assertNotified('You are on Ultra now');
        expect(Workspace::query()->findOrFail($this->person->current_workspace_id)->plan->key)->toBe('business');
    });

    it('throttles Apply in the console too', function () {
        for ($i = 0; $i < PromoCodes::PER_MINUTE; $i++) {
            try {
                app(PromoCodes::class)->checkThrottled($this->person, 'TRY-'.$i.'XYZ', null, '10.0.0.1');
            } catch (ApiError) {
            }
        }
        expect(fn () => app(PromoCodes::class)->checkThrottled($this->person, 'TRY-LAST1', null, '10.0.0.1'))
            ->toThrow(fn (ApiError $e) => expect($e->apiCode())->toBe('rate_limited'));
    });
});

it('runs its migration up, down and up again', function () {
    $default = config('database.default');
    $config = config("database.connections.{$default}");
    if ($config['driver'] !== 'sqlite') {
        $this->markTestSkipped('The SQLite cycle; MariaDB has its own run.');
    }
    $file = storage_path('framework/testing/promo-cycle-'.getmypid().'.sqlite');
    @mkdir(dirname($file), 0777, true);
    file_put_contents($file, '');
    config(['database.connections.promo_cycle' => [...$config, 'database' => $file, 'foreign_key_constraints' => true]]);
    DB::purge('promo_cycle');
    /** @var Connection $db */
    $db = DB::connection('promo_cycle');
    $run = fn (string $command, array $options = []) => expect(Artisan::call($command, ['--database' => 'promo_cycle', '--force' => true, ...$options]))->toBe(0);

    $run('migrate:fresh');
    expect(Schema::connection('promo_cycle')->hasTable('promo_codes'))->toBeTrue()
        ->and(Schema::connection('promo_cycle')->hasColumns('upgrade_requests', ['promo_code', 'percent', 'amount_due']))->toBeTrue();
    // Since v1.7 the payment receipts' migration comes after it.
    $run('migrate:rollback', ['--step' => 2]);
    expect(Schema::connection('promo_cycle')->hasTable('promo_codes'))->toBeFalse()->and(Schema::connection('promo_cycle')->hasTable('promo_code_uses'))->toBeFalse()
        ->and(Schema::connection('promo_cycle')->hasColumn('upgrade_requests', 'promo_code'))->toBeFalse()
        ->and(Schema::connection('promo_cycle')->hasColumn('upgrade_requests', 'plan'))->toBeTrue();
    $run('migrate');
    expect(Schema::connection('promo_cycle')->hasTable('promo_code_uses'))->toBeTrue();
    $db->disconnect();
    @unlink($file);
});
