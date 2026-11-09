<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Support\CommandPalette;
use App\Filament\Support\PlanLock;
use App\Filament\Support\Shell;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\Workspace;
use App\Services\Entitlements\Features;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.6.2: Account → Plan & usage → All features. Every feature of the registry in its order with its line, a tick
| where the plan has it, else the badge of the lowest plan that has it (in that plan's colour, with the lock), which
| opens the upgrade sheet for that feature; "Your plan has N of 15 features"; Compare plans; in Find a page.
*/

beforeEach(function () {
    // Ultra is the plan key `business`, renamed by the operator.
    Plan::query()->where('key', 'business')->update(['name' => 'Ultra', 'badge' => 'ULTRA', 'color' => '#b45309']);
    $this->person = Fx::user();
    $this->actingAs($this->person, 'web');
    Filament::setCurrentPanel('app');
});

function allFeaturesPlan(Workspace|int $workspace, string $key): void
{
    Workspace::query()->whereKey($workspace instanceof Workspace ? $workspace->id : $workspace)->update(['plan_id' => Plan::query()->where('key', $key)->value('id')]);
}

it('lists every feature in the registry\'s order, with a tick for the free ones and the lowest plan\'s badge for the rest on Free', function () {
    allFeaturesPlan((int) $this->person->current_workspace_id, 'free');

    $rows = PlanLock::features();
    expect(array_column($rows, 'key'))->toBe(Features::keys())
        ->and(count($rows))->toBe(15);
    foreach ($rows as $row) {
        expect($row['has'])->toBe(Features::isFree($row['key']));
        if (! $row['has']) {
            $lowest = in_array($row['key'], ['team', 'premium_engines', 'live_translate', 'ai_notes'], true) ? 'ULTRA' : 'PRO';
            expect($row['badge']?->toHtml())->toContain($lowest.' ✦')->toContain('<svg');
        }
    }

    $html = (string) $this->get('/app/profile?tab=plan')->assertOk()->getContent();
    expect($html)->toContain('id="all-features"')
        ->toContain('Your plan has 6 of 15 features.')
        ->toContain('data-has="no"')
        ->toContain("mountAction('upgradeFeature', { feature: 'live_translate' })")
        ->toContain("mountAction('upgradeFeature')")
        ->toContain('Compare plans')
        // The badge in the plan's own colour.
        ->toContain('color:#b45309')->toContain('color:#7c3aed');
    foreach (Features::ALL as $feature) {
        expect($html)->toContain(e($feature['name']))->toContain(e($feature['description']));
    }
    // In the registry's order on the page too.
    $positions = array_map(fn (string $key): int => (int) strpos($html, 'data-feature="'.$key.'"'), Features::keys());
    expect($positions)->toBe(array_values(collect($positions)->sort()->all()))->not->toContain(0);
});

it('ticks all 15 on Ultra, with no badge', function () {
    allFeaturesPlan((int) $this->person->current_workspace_id, 'business');

    $rows = PlanLock::features();
    expect(array_filter($rows, fn (array $r): bool => ! $r['has'] || $r['badge'] !== null))->toBe([]);

    $html = (string) $this->get('/app/profile?tab=plan')->assertOk()->getContent();
    expect($html)->toContain('Your plan has 15 of 15 features.')
        ->not->toContain('data-has="no"')
        ->not->toContain("mountAction('upgradeFeature', { feature:");
    expect(substr_count($html, 'data-has="yes"'))->toBe(15);
});

it('counts Pro\'s 11 and the person\'s own overrides', function () {
    allFeaturesPlan((int) $this->person->current_workspace_id, 'pro');
    expect(count(array_filter(PlanLock::features(), fn (array $r): bool => $r['has'])))->toBe(11);

    $this->person->forceFill(['entitlement_overrides' => ['features' => ['live_translate' => true]]])->save();
    $this->get('/app/profile?tab=plan')->assertOk()->assertSee('Your plan has 12 of 15 features.');
});

it('opens the upgrade sheet for the feature whose badge is clicked, and the plans from Compare plans', function () {
    allFeaturesPlan((int) $this->person->current_workspace_id, 'free');

    Livewire::test(EditProfile::class)
        ->mountAction('upgradeFeature', ['feature' => 'screen_moments'])
        ->assertActionMounted('upgradeFeature')
        ->assertMountedActionModalSee('Screen moments is in Pro')
        ->assertMountedActionModalSee('What was on the screen during a meeting')
        ->assertSchemaStateSet(['plan' => 'pro'], 'mountedActionSchema0');

    Livewire::test(EditProfile::class)
        ->mountAction('upgradeFeature', ['feature' => 'live_translate'])
        ->assertMountedActionModalSee('Live Translate is in Ultra')
        ->assertSchemaStateSet(['plan' => 'business'], 'mountedActionSchema0');

    Livewire::test(EditProfile::class)->mountAction('upgradeFeature')->assertActionMounted('upgradeFeature')
        ->assertMountedActionModalSee('Request upgrade');

    // A name that is no feature shows the plans, nothing else.
    Livewire::test(EditProfile::class)->mountAction('upgradeFeature', ['feature' => '<script>'])->assertActionMounted('upgradeFeature')
        ->assertMountedActionModalDontSee('<script>', false);
});

it('sends an upgrade request from a badge\'s sheet, for the person\'s own workspace only', function () {
    allFeaturesPlan((int) $this->person->current_workspace_id, 'free');

    Livewire::test(EditProfile::class)
        ->mountAction('upgradeFeature', ['feature' => 'ai_notes'])
        ->callMountedAction()
        ->assertHasNoActionErrors();

    expect(UpgradeRequest::query()->where('user_id', $this->person->id)->value('plan'))->toBe('business');
});

it('shows only the signed-in person\'s plan: another person\'s Ultra does not tick a Free person\'s list', function () {
    $other = Fx::user();
    allFeaturesPlan((int) $other->current_workspace_id, 'business');
    $other->forceFill(['entitlement_overrides' => ['features' => ['views' => true]]])->save();
    allFeaturesPlan((int) $this->person->current_workspace_id, 'free');

    expect(count(array_filter(PlanLock::features(), fn (array $r): bool => $r['has'])))->toBe(6);
    $this->get('/app/profile?tab=plan')->assertOk()->assertSee('Your plan has 6 of 15 features.')->assertDontSee($other->email);
});

it('is in Find a page and the palette as All features', function () {
    $page = collect(Shell::pages())->firstWhere('id', 'all-features');
    expect($page['label'])->toBe('All features')->and($page['url'])->toEndWith('?tab=plan#all-features');

    $results = (new CommandPalette)->getResults('all features');
    $labels = collect($results->getCategories()->get('Go to') ?? [])->map(fn ($r) => (string) $r->title)->all();
    expect($labels)->toContain('All features');
});
