<?php

declare(strict_types=1);

use App\Filament\Support\SettingsContext;
use App\Filament\Widgets\OnboardingChecklistWidget;
use App\Filament\Widgets\V2NeedsActionWidget;
use App\Services\InterfaceFlags;
use App\Services\OnboardingChecklist;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Home in the new design (U9b part 2, docs/PLAN_V2_PARITY.md section 2): the setup checklist card under classic's own
 * condition (WEB-HOM-004), "Needs action" as one quiet line and the usage meter in Settings › Plan & usage (WEB-HOM-005).
 */

require_once __DIR__.'/Support/parity_world.php';

/** Home (or another page) as [$user] gets it in the new design (or classic). */
function homeV2Html(object $test, $user, string $path = '/app', bool $v2 = true): string
{
    webParityDesign($v2);
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $html = (string) $test->actingAs($user->fresh(), 'web')->get($path)->assertOk()->getContent();
    if ($v2) {
        expect(webParityIsV2($html))->toBeTrue('drawn by the v2.0 frame');
    }

    return $html;
}

it('WEB-HOM-004: shows the setup checklist on Home to a person who has not finished setup, as classic does, and Dismiss hides it', function () {
    [$owner] = webParityWorld();
    $admin = Fx::superAdmin(['name' => 'Server Owner'], twoFactor: false);
    $html = homeV2Html($this, $admin);
    expect($html)->toContain('wire:name="App\Filament\Widgets\OnboardingChecklistWidget"')
        ->toContain('Turn on two-step sign-in')->toContain('Add a provider key')->toContain('Sign in your first device');
    // Classic shows the same card under the same condition.
    expect(homeV2Html($this, $admin, v2: false))->toContain('wire:name="App\Filament\Widgets\OnboardingChecklistWidget"');
    // Not to a person who has nothing to set up (the parity world's person), nor once dismissed.
    expect(homeV2Html($this, $owner))->not->toContain('OnboardingChecklistWidget');
    webParityDesign(true);
    $this->actingAs($admin->fresh(), 'web');
    Livewire::test(OnboardingChecklistWidget::class)->call('dismiss');
    expect(app(OnboardingChecklist::class)->dismissed())->toBeTrue()
        ->and(homeV2Html($this, $admin))->not->toContain('OnboardingChecklistWidget');
});

it('WEB-HOM-005: says what needs action as one quiet line on Home, each a link to its Library view', function () {
    [$owner] = webParityWorld();
    $html = homeV2Html($this, $owner);
    // The world: one failed upload (Samples to the courier) and recordings kept as audio only (since v2.1 the
    // glossary's words, docs/PLAN_V2_1_POLISH.md section 1: never "not transcribed").
    expect($html)->toContain('wire:name="App\Filament\Widgets\V2NeedsActionWidget"')->toContain('data-needs-action')
        ->toContain('Needs action')->toContain('1 failed upload')->toMatch('~data-needs="untranscribed">\d+ audio only<~')
        ->not->toContain('not transcribed');
    expect(substr_count($html, 'data-needs-action'))->toBe(1);
    foreach (V2NeedsActionWidget::items() as $item) {
        expect($html)->toContain('href="'.e($item['url']).'"');
        $page = homeV2Html($this, $owner, parse_url($item['url'], PHP_URL_PATH).'?'.parse_url($item['url'], PHP_URL_QUERY));
        expect($page)->toContain('v2-lib-row');
    }
    // Classic's Home stays as it was; nothing is said when nothing needs the person.
    expect(homeV2Html($this, $owner, v2: false))->not->toContain('data-needs-action');
    $calm = Fx::user(['name' => 'Nothing Waiting']);
    expect(homeV2Html($this, $calm))->not->toContain('data-needs-action');
});

it('WEB-HOM-005: has the usage meter in Settings › Plan & usage in the new design', function () {
    [$owner] = webParityWorld();
    $html = homeV2Html($this, $owner, '/app/settings/plan');
    expect($html)->toContain('el-meter')->toContain('This month')->toContain('Transcription')->toContain('Resets 1 Dec')
        ->toContain('Minutes reset on the 1st of each month.');
});

it('WEB-HOM-005: works the line out once a page (not once to decide and again to draw), and says "not transcribed" only with the new Library', function () {
    [$owner] = webParityWorld();
    webParityDesign(true);
    $this->actingAs($owner->fresh(), 'web');
    DB::enableQueryLog();
    expect(V2NeedsActionWidget::canView())->toBeTrue();
    $first = count(DB::getQueryLog());
    $items = V2NeedsActionWidget::items();
    expect(count(DB::getQueryLog()))->toBe($first)->and($items)->not->toBe([]);

    // The new frame without the new Library (ui_v2.library off): no Untranscribed view to point at.
    app(InterfaceFlags::class)->set('ui_v2.library', false, 0, [], [], 'off');
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $html = (string) $this->actingAs($owner->fresh(), 'web')->get('/app')->assertOk()->getContent();
    expect(webParityIsV2($html))->toBeTrue()->and($html)->toContain('1 failed upload')->not->toContain('data-needs="untranscribed"');
});
