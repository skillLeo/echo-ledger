<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Plans;
use App\Models\AuditEvent;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\UpgradeRequests;
use Filament\Facades\Filament;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6 P2.3: Plans & features on the admin panel: make, change, duplicate and archive plans, the display price with its
| currency, and the features × plans matrix.
*/

beforeEach(function () {
    $this->actingAs(Fx::superAdmin(), 'web');
    Filament::setCurrentPanel('admin');
});

it('is closed to customers, the matrix included', function () {
    $this->actingAs(Fx::user(), 'web');
    $this->get('/admin/plans')->assertForbidden();
    Livewire::test(Plans::class)->assertForbidden();
    $pro = Plan::query()->where('key', 'pro')->firstOrFail();
    $before = $pro->featureMap();
    expect(fn () => (new Plans)->toggleFeature($pro->id, 'live_translate'))->toThrow(HttpException::class);
    expect($pro->fresh()->featureMap())->toBe($before);
});

it('makes a plan with a price in a currency, trial minutes, engines and the default for sign-ups', function () {
    Livewire::test(Plans::class)->callTableAction('create', null, [
        'key' => 'starter', 'name' => 'Starter', 'badge' => 'START', 'color' => '#0e7490', 'description' => 'For one person',
        'price_amount' => 2000, 'currency' => 'PKR', 'price_usd' => 7, 'sort_order' => 5, 'trial_minutes' => 30,
        'monthly_minutes' => 300, 'max_file_minutes' => 60, 'uploads_per_day' => 20, 'engines' => ['own_groq', 'groq'],
        'is_default' => true, 'on_offer' => true, 'feature_platform_transcription' => true,
    ])->assertHasNoTableActionErrors();

    $plan = Plan::query()->where('key', 'starter')->firstOrFail();
    expect($plan->displayPrice())->toBe('Rs 2,000 a month')
        ->and($plan->currency)->toBe('PKR')->and($plan->trial_minutes)->toBe(30)
        ->and($plan->engineList())->toBe(['own_groq', 'groq'])
        ->and(Plan::defaultPlan()->is($plan))->toBeTrue()
        ->and(Plan::query()->where('is_default', true)->count())->toBe(1)
        ->and(collect(app(Entitlements::class)->offered())->map(fn (Plan $p): array => Entitlements::planCard($p))->firstWhere('key', 'starter')['price'])->toBe('Rs 2,000 a month');
});

it('shows the plan\'s own price text before the amount, and Free without either', function () {
    $plan = new Plan(['name' => 'X', 'price_usd' => 0]);
    expect($plan->displayPrice())->toBe('Free');
    $plan->price_amount = '12.5';
    $plan->currency = 'USD';
    expect($plan->displayPrice())->toBe('$ 12.50 a month');
    $plan->price_label = 'Ask us';
    expect($plan->displayPrice())->toBe('Ask us');
});

it('duplicates a plan with every limit and feature, neither offered nor the default', function () {
    $pro = Plan::query()->where('key', 'pro')->firstOrFail();

    Livewire::test(Plans::class)->callTableAction('duplicate', $pro, ['key' => 'pro-yearly', 'name' => 'Pro yearly'])->assertHasNoTableActionErrors();

    $copy = Plan::query()->where('key', 'pro-yearly')->firstOrFail();
    expect($copy->name)->toBe('Pro yearly')->and($copy->featureMap())->toBe($pro->featureMap())
        ->and($copy->monthly_minutes)->toBe($pro->monthly_minutes)->and($copy->engineList())->toBe($pro->engineList())
        ->and($copy->on_offer)->toBeFalse()->and($copy->is_default)->toBeFalse()->and($copy->workspaces()->count())->toBe(0);
    Livewire::test(Plans::class)->callTableAction('duplicate', $pro, ['key' => 'pro-yearly', 'name' => 'Again'])->assertHasTableActionErrors(['key']);
});

it('archives a plan: no longer offered or asked for, its workspaces keep it, and it can come back', function () {
    $pro = Plan::query()->where('key', 'pro')->firstOrFail();
    $customer = Fx::user();
    Workspace::query()->whereKey($customer->current_workspace_id)->update(['plan_id' => $pro->id]);

    Livewire::test(Plans::class)->callTableAction('archive', $pro);
    expect($pro->fresh()->isArchived())->toBeTrue()->and($pro->fresh()->on_offer)->toBeFalse()
        ->and(collect(app(Entitlements::class)->offered())->pluck('key')->all())->not->toContain('pro')
        ->and(app(Entitlements::class)->plan((int) $customer->current_workspace_id)->key)->toBe('pro');
    expect(fn () => app(UpgradeRequests::class)->create($customer, (int) $customer->current_workspace_id, 'pro', null))->toThrow(ValidationException::class);
    Livewire::test(Plans::class)->assertCanNotSeeTableRecords([$pro])->filterTable('shown', 'archived')->assertCanSeeTableRecords([$pro])
        ->assertTableActionHidden('assign', $pro->fresh())
        ->callTableAction('archive', $pro->fresh());
    expect($pro->fresh()->isArchived())->toBeFalse()
        ->and(AuditEvent::query()->where('event', 'plan_changed')->where('summary', 'like', '%archived%')->exists())->toBeTrue();
});

it('never archives Free or the default plan', function () {
    $default = Plan::defaultPlan();
    Livewire::test(Plans::class)->assertTableActionHidden('archive', $default)->assertTableActionHidden('archive', Plan::free());
    expect(Plan::defaultPlan()->isArchived())->toBeFalse();
});

it('ticks features into plans on the matrix, at once and in the activity log', function () {
    $pro = Plan::query()->where('key', 'pro')->firstOrFail();
    $customer = Fx::user();
    Workspace::query()->whereKey($customer->current_workspace_id)->update(['plan_id' => $pro->id]);
    $was = $pro->allows('live_translate');

    $this->get('/admin/plans')->assertOk()->assertSee('Features and plans')->assertSee('data-feature="live_translate"', false);
    Livewire::test(Plans::class)->call('toggleFeature', $pro->id, 'live_translate')->assertNotified();

    expect($pro->fresh()->allows('live_translate'))->toBe(! $was)
        ->and(app(Entitlements::class)->allows((int) $customer->current_workspace_id, $customer->id, 'live_translate'))->toBe(! $was)
        ->and(AuditEvent::query()->where('event', 'plan_changed')->where('subject_id', (string) $pro->id)->exists())->toBeTrue();

    Livewire::test(Plans::class)->call('toggleFeature', $pro->id, 'record')->assertNotFound();
    $pro->forceFill(['archived_at' => now()])->save();
    Livewire::test(Plans::class)->call('toggleFeature', $pro->id, 'live_translate')->assertNotFound();
});
