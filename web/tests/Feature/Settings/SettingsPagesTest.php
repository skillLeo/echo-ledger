<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Auth\EditProfile;
use App\Filament\Support\CommandPalette;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\Shell;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\EmailPreferences;
use App\Services\Accounts\SettingsRegistry;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| v1.7: Settings (the account page before) at /app/settings/{page}: the profile card on top (Upgrade filled on Free,
| outlined on Pro, none on Ultra or while a request is open), sections and rows from the registry, no ✦, no capitals,
| no plan colours; the old addresses redirect for good.
*/

beforeEach(function () {
    Plan::query()->where('key', 'business')->update(['name' => 'Ultra']);
    Filament::setCurrentPanel('app');
});

function settingsPerson(string $plan = 'free', array $attributes = []): User
{
    $user = Fx::user(['name' => 'Hassam Ali', ...$attributes]);
    Workspace::query()->whereKey($user->current_workspace_id)->update([
        'plan_id' => Plan::query()->where('key', $plan)->value('id'),
        'plan_expires_at' => $plan === 'free' ? null : now()->addDays(30),
    ]);

    return $user->fresh();
}

function settingsHtml(User $user, string $path = ''): string
{
    test()->actingAs($user, 'web');
    SettingsContext::forget();

    return (string) test()->get('/app/settings'.$path)->assertOk()->getContent();
}

it('shows who you are first: the name, the email, a plain plan tag and a filled Upgrade on Free', function () {
    $html = settingsHtml(settingsPerson('free'));
    expect($html)->toContain('data-profile-card')
        ->toContain('Hassam Ali')
        ->toContain('data-plan-chip>Free</span>')
        ->toContain('data-upgrade="primary"')
        ->toContain('Set up free transcription (2 minutes)')
        ->not->toContain('✦')->not->toContain('>FREE<')->not->toContain('color:#');
});

it('outlines Upgrade on Pro with the minutes used, and has none on Ultra, which says until when', function () {
    $pro = settingsHtml(settingsPerson('pro'));
    expect($pro)->toContain('data-upgrade="outline"')->toContain('of 600 min this month')->toContain('data-plan-chip>Pro</span>');

    $ultra = settingsHtml(settingsPerson('business'));
    expect($ultra)->not->toContain('data-upgrade=')->toContain('Paid until')->toContain('data-plan-chip>Ultra</span>');
});

it('has no Upgrade while a request is open, only a line about it', function () {
    $user = settingsPerson('free');
    UpgradeRequest::query()->create(['user_id' => $user->id, 'workspace_id' => $user->current_workspace_id, 'plan' => 'pro', 'status' => UpgradeRequest::PENDING]);
    $html = settingsHtml($user);
    expect($html)->not->toContain('data-upgrade=')
        ->toContain('Pro requested. Waiting for your payment');
});

it('lists the sections in sentence case with Heroicons and Sign out at the end', function () {
    $html = settingsHtml(settingsPerson('free'));
    foreach (['Transcription', 'App', 'Account', 'Support'] as $section) {
        expect($html)->toContain('<h2 class="el-set-head">'.$section.'</h2>');
    }
    expect($html)->toContain('data-nav="plan"')->toContain('id="setting-signout"')
        ->not->toContain('el-account-tabs')->not->toContain('fi-tabs');
});

it('opens each page with its own title, and a page that is not there is a 404', function () {
    $user = settingsPerson('free');
    foreach (['language' => 'Language', 'appearance' => 'Appearance', 'notifications' => 'Notifications', 'storage' => 'Storage', 'shortcuts' => 'Keyboard shortcuts',
        'profile' => 'Profile', 'plan' => 'Plan &amp; usage', 'security' => 'Sign-in &amp; security', 'devices' => 'Devices', 'privacy' => 'Privacy &amp; data',
        'help' => 'Help', 'transcription-key' => 'Transcription key'] as $page => $title) {
        expect(settingsHtml($user, '/'.$page))->toContain('<h1 class="el-set-h1">'.$title.'</h1>');
    }
    $this->get('/app/settings/nothing-here')->assertNotFound();
});

it('keeps the Transcription key page for Free or a saved key only', function () {
    $this->actingAs(settingsPerson('pro'), 'web');
    $this->get('/app/settings/transcription-key')->assertNotFound();
});

it('redirects the old account and security addresses for good', function () {
    $this->actingAs(settingsPerson('free'), 'web');
    foreach (['profile' => 'profile', 'sign-in' => 'security', 'devices' => 'devices', 'notifications' => 'notifications', 'preferences' => 'appearance',
        'plan' => 'plan', 'key' => 'transcription-key', 'privacy' => 'privacy'] as $tab => $page) {
        $this->get('/app/profile?tab='.$tab)->assertStatus(301)->assertRedirect('/app/settings/'.$page);
    }
    $this->get('/app/profile')->assertStatus(301)->assertRedirect('/app/settings');
    $this->get('/app/security')->assertStatus(301)->assertRedirect('/app/settings/security');
    expect(EditProfile::settingsUrl('appearance', 'appearance.theme'))->toEndWith('/app/settings/appearance?highlight=appearance.theme');
});

it('renders every registry row of a page as #setting-{id}, and only registry rows (both ways)', function (string $plan) {
    $user = settingsPerson($plan);
    $this->actingAs($user, 'web');
    SettingsContext::forget();
    $context = SettingsContext::current();
    $known = array_column($context->entries(), 'title', 'id');
    foreach (SettingsRegistry::PAGES as $page => $root) {
        if ($page === 'workspace' || ! $context->available(SettingsRegistry::byId()[$root])) {
            continue;
        }
        $html = settingsHtml($user, '/'.$page);
        preg_match_all('/id="setting-([a-z0-9._\-]+)"/i', $html, $m);
        $rendered = array_unique($m[1]);
        foreach ($rendered as $id) {
            expect(array_key_exists($id, $known))->toBeTrue("#setting-{$id} on {$page} is not in the registry");
        }
        foreach ($context->entries() as $entry) {
            if (($entry['page'] ?? null) !== $page || $entry['id'] === $root || isset($entry['anchor']) || in_array($entry['kind'], ['help'], true)
                || in_array($entry['id'], ['plan.request', 'plan.payment_details', 'plan.receipt', 'plan.upgrade', 'plan.usage.uploads'], true)) {
                continue;
            }
            expect(in_array($entry['id'], $rendered, true))->toBeTrue("{$entry['id']} is in the registry but not on {$page}");
        }
    }
})->with(['free', 'pro', 'business']);

it('searches in place: results with their breadcrumb, counts on the list, and a helpful empty state', function () {
    $user = settingsPerson('free');
    $this->actingAs($user, 'web');
    Livewire::test(EditProfile::class)
        ->set('settingsQuery', 'dark mode')
        ->assertSee('Results for “dark mode”')
        ->assertSee('Settings › Appearance › Theme')
        ->assertSeeHtml('data-result="appearance.theme"')
        ->assertSeeHtml('class="el-set-count"')
        ->set('settingsQuery', 'xyzzy')
        ->assertSee('No settings match “xyzzy”')
        ->assertSee('Check the spelling or try another word.')
        ->assertDontSee('Closest matches')
        ->call('clearSettingsSearch')
        ->assertSet('settingsQuery', '');
});

it('finds settings in the command palette too, with where they are', function () {
    $this->actingAs(settingsPerson('free'), 'web');
    $results = app(CommandPalette::class)->getResults('dark mode');
    $settings = $results->getCategories()['Settings'] ?? collect();
    expect(collect($settings)->map(fn ($r) => (string) $r->title)->implode('|'))->toContain('Theme')->toContain('Settings › Appearance › Theme');
    $upgrade = app(CommandPalette::class)->getResults('upgrade');
    expect(collect($upgrade->getCategories()['Commands'] ?? [])->map(fn ($r) => strip_tags((string) $r->title))->implode('|'))->toContain('Upgrade plan');
});

it('saves a preference at once and resets a page to its defaults', function () {
    $user = settingsPerson('free');
    $this->actingAs($user, 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])
        ->call('setPreference', 'clock', '24h')->assertReturned(true)
        ->call('setPreference', 'clock', 'nonsense')->assertReturned(false);
    expect(Shell::preferences($user->fresh())['clock'])->toBe('24h');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->callAction('resetPage');
    expect(Shell::preferences($user->fresh())['clock'])->toBe('12h');
});

it('turns an email on and off from its row', function () {
    $user = settingsPerson('free');
    $this->actingAs($user, 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'notifications'])->call('toggleEmail', 'sharing');
    expect(EmailPreferences::wants($user->fresh(), 'sharing'))->toBeFalse();
});

it('saves a Groq key only from 20 characters and says why under the box', function () {
    $user = settingsPerson('free');
    $this->actingAs($user, 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'transcription-key'])
        ->set('groqKey', 'short')->call('saveGroqKey')
        ->assertSet('groqKeyError', 'Paste the whole key. It starts with gsk_.');
});

it('highlights a row on arrival and respects reduced motion', function () {
    $html = settingsHtml(settingsPerson('free'), '/appearance?highlight=appearance.theme');
    expect($html)->toContain('id="setting-appearance.theme"')->toContain('prefers-reduced-motion')->toContain("classList.add('is-highlighted')");
});

it('shows this month plainly: the own key on Free (never "0 of 0"), amber near the limit, no plans above the top one', function () {
    $free = settingsHtml(settingsPerson('free'), '/plan');
    expect($free)->toContain('Free plan')->toContain('Your Groq key')->toContain('Not added.')->toContain('Add key')
        ->not->toContain('0 of 0')->toContain('data-plan-card="pro"')->toContain('data-plan-card="business"')
        ->toContain('Everything in Pro, plus:')->toContain('Every plan can record, organise, search, share links and export.')
        ->not->toContain('✦')->not->toContain('heroicon-o-sparkles');

    $pro = settingsPerson('pro');
    UsageLedgerEntry::query()->create(['workspace_id' => $pro->current_workspace_id, 'user_id' => $pro->id, 'source' => 'server', 'report_id' => 'near-limit',
        'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => 500 * 60000, 'channels' => 1, 'billed_ms' => 500 * 60000,
        'unit_price_usd' => 0, 'cost_usd' => 0, 'billable' => true, 'occurred_at' => now()]);
    $html = settingsHtml($pro, '/plan');
    expect($html)->toContain('500 of 600 min')->toMatch('/el-meter el-set-group is-warning/')->toContain('of your minutes.')
        ->toContain('Upgrade to Ultra')->not->toContain('data-plan-card="pro"')->toContain('Minutes reset on the 1st of each month.')->not->toContain('Free plan');

    $ultra = settingsHtml(settingsPerson('business'), '/plan');
    expect($ultra)->toContain('Ultra plan')->toContain('on our top plan.')->not->toContain('<h2 class="el-set-head">Plans</h2>')
        ->not->toContain('id="setting-plan.upgrade"');
});

it('tells a member whose plan it is, with no Upgrade anywhere', function () {
    $owner = settingsPerson('pro', ['name' => 'Sara Khan']);
    $member = withoutWorkspace(Fx::user(['name' => 'Bilal']));
    app(Workspaces::class)->addMember(Workspace::query()->findOrFail($owner->current_workspace_id), $member, WorkspaceRole::Member);
    $html = settingsHtml($member->fresh());
    expect($html)->toContain('is on Pro. Managed by Sara Khan.')->not->toContain('data-upgrade=');
    $this->get('/app/settings/plan/upgrade')->assertForbidden();
});

it('tells Free without a key in the Library to add one, never that 0 minutes are used up', function () {
    $this->actingAs(settingsPerson('free'), 'web');
    SettingsContext::forget();
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toContain('data-plan-limit="key"')->toContain('Transcripts use your own Groq key.')->toContain('Add key')
        ->not->toContain('minutes are used up');
});
