<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Support\SettingsContext;
use App\Models\Plan;
use App\Models\SentItem;
use App\Models\UpgradeRequest;
use App\Models\UsageLedgerEntry;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * DES-08 + UX-18 on the web console: the avatar you can always see. In the rail it is "Account": a 28 px avatar in a
 * 40 px target, its name under it, the tooltip "Account, theme and settings", named "Account, <name>", a dot only for
 * an account problem (on the web: a payment that failed), one of the rail's stops. Its menu opens upward: a header
 * (avatar, name, email, plan and the month's minutes), then Add photo (no photo) or Edit profile, Theme, Zoom
 * (v21_zoom: the browser's keys), Plan & billing, Settings, Keyboard shortcuts, Help, Sign out. Settings › Profile has a
 * 96 px avatar with a camera badge; Activity shows the avatar of the person an item is about. Classic keeps every byte
 * (the goldens).
 */

beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->owner = Fx::user(['name' => 'Saif Hassam', 'email' => 'saif@example.com']);
    $this->owner->forceFill(['avatar_color' => 0])->save();
    $this->farah = Fx::user(['name' => 'Farah Khan', 'email' => 'farah@example.com']);
    $this->farah->forceFill(['avatar_color' => 4])->save();
});

/** Livewire's block markers round a component's @if, when it draws inside a Livewire component. */
const ACCOUNT_BLOCK = '(?:<!--\[if BLOCK\]><!\[endif\]-->)?';

const ACCOUNT_END = '(?:<!--\[if ENDBLOCK\]><!\[endif\]-->)?';

function accountConsole(bool $v2, string $language = 'en', bool $zoom = false): void
{
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, $v2, 0, [], [], $v2 ? 'everyone' : 'off');
    }
    app(InterfaceFlags::class)->set('v21_zoom', $zoom, 0, [], [], $zoom ? 'everyone' : 'off');
    test()->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => $language]]])->save();
    SettingsContext::forget();
    test()->actingAs(test()->owner->fresh(), 'web');
}

/** The rail's Account: its dropdown, trigger and menu (the panel is in the page; Alpine moves it to the body). */
function accountMenuOf(string $html): string
{
    return preg_match('~<div class="v2-rail-avatar">.*?</form>~s', $html, $m) === 1 ? $m[0] : '';
}

/** The menu's items in order, by their data-account-item. */
function accountItems(string $menu): array
{
    preg_match_all('~data-account-item="([a-z-]+)"~', $menu, $m);

    return $m[1];
}

it('puts Account in the rail: a 28 px avatar in a 40 px target, its name under it, the tooltip, one of the rail\'s stops', function () {
    accountConsole(true);
    $menu = accountMenuOf((string) $this->get('/app/sessions')->assertOk()->getContent());

    expect($menu)->toContain('<button type="button" class="fi-user-menu-trigger v2-um-trigger is-rail" aria-label="Account, Saif Hassam" title="Account, theme and settings" tabindex="-1">')
        // The initials are drawn, so the button's words are "Account", the start of its name.
        ->toMatch('~<span class="v2-um-hit">'.ACCOUNT_BLOCK.'<span class="v2-avatar is-rail is-coloured is-drawn" data-avatar-color="0" style="background-color: var\(--el-role-avatar-0\)" data-initials="SH" aria-hidden="true"></span>\s*'.ACCOUNT_END.'</span>~')
        ->toContain('<span class="v2-rail-label" aria-hidden="true">Account</span>')
        ->not->toContain('v2-um-dot')
        // It opens upward, from the rail's foot.
        ->toContain('x-float.placement.top-start');
});

it('names Account in Urdu too', function () {
    accountConsole(true, 'ur');
    preg_match('~class="fi-user-menu-trigger v2-um-trigger is-rail" aria-label="([^"]*)" title="([^"]*)"~', (string) $this->get('/app/sessions')->assertOk()->getContent(), $m);
    expect(str_replace(["\u{2068}", "\u{2069}"], '', $m[1] ?? ''))->toBe('اکاؤنٹ، Saif Hassam')
        ->and($m[2] ?? '')->toBe('اکاؤنٹ، تھیم اور ترتیبات');
});

it('puts Theme\'s three options in the menu as items its arrows reach (menuitemradio in a labelled group)', function () {
    accountConsole(true);
    $menu = accountMenuOf((string) $this->get('/app/sessions')->assertOk()->getContent());
    preg_match('~<span class="v2-segmented" role="group" aria-labelledby="(v2-um-theme-rail)">(.*?)</span>~s', $menu, $group);
    expect($group[1] ?? '')->toBe('v2-um-theme-rail')
        ->and($menu)->toContain('<span id="v2-um-theme-rail">Theme</span>')
        ->and(substr_count($group[2] ?? '', 'role="menuitemradio" tabindex="-1" x-bind:aria-checked='))->toBe(3)
        ->and($menu)->not->toContain('role="radio"');
    // The header describes the menu (the panel's aria-describedby, set by resources/js/a11y-core.cjs).
    expect($menu)->toContain('data-account-head id="v2-um-head-rail" data-menu-description>');
});

it('shows the dot only for an account problem: a payment staff asked for again', function () {
    $request = UpgradeRequest::query()->create(['user_id' => $this->owner->id, 'workspace_id' => $this->owner->current_workspace_id, 'plan' => 'pro', 'status' => UpgradeRequest::PENDING]);
    accountConsole(true);
    // Waiting for payment, or in review: nothing wrong yet.
    expect(accountMenuOf((string) $this->get('/app/sessions')->getContent()))->not->toContain('v2-um-dot');

    $request->forceFill(['paid_at' => now()->subHour(), 'proof_requested_at' => now()])->save();
    SettingsContext::forget();
    $menu = accountMenuOf((string) $this->get('/app/sessions')->getContent());
    expect($menu)->toContain('aria-label="Account, Saif Hassam, a payment needs your attention"')
        ->toContain('<span class="v2-um-dot" aria-hidden="true"></span>')
        // Plan & billing says it, with the same dot.
        ->toMatch('~data-account-item="plan"\s*>.*?<span class="fi-dropdown-list-item-label">\s*Plan &amp; billing<span class="v2-um-problem"><span class="v2-um-dot is-inline" aria-hidden="true"></span>A payment needs your attention</span>~s');
    // Without the rail (the top bar's button) the dot shows too, as its name says.
    app(InterfaceFlags::class)->set('ui_v2.library', false, 0, [], [], 'off');
    SettingsContext::forget();
    $top = (string) $this->get('/app/sessions')->getContent();
    expect($top)->toContain('class="fi-user-menu-trigger v2-um-trigger" aria-label="Account, Saif Hassam, a payment needs your attention"')
        ->toMatch('~<span class="v2-um-hit is-plain">'.ACCOUNT_BLOCK.'<span class="v2-avatar is-coloured is-drawn"[^>]*></span>\s*'.ACCOUNT_END.'<span class="v2-um-dot" aria-hidden="true"></span></span>~');
});

it('opens a menu with the header, then Add photo without a photo, Theme, Plan & billing, Settings, Keyboard shortcuts, Help, Sign out', function () {
    accountConsole(true);
    $menu = accountMenuOf((string) $this->get('/app/sessions')->assertOk()->getContent());

    expect(accountItems($menu))->toBe(['photo', 'plan', 'settings', 'shortcuts', 'help', 'sign-out'])
        ->and($menu)->toContain('href="'.e(EditProfile::settingsUrl('profile')).'?action=changePhoto"')
        ->toContain('href="'.e(EditProfile::settingsUrl('plan')).'"')
        ->toContain("x-on:click=\"\$dispatch('el-help')\"")
        ->toContain('<kbd class="v2-um-key" aria-hidden="true">?</kbd>')
        ->toMatch('~role="group" aria-labelledby="v2-um-theme-rail">.*System.*Light.*Dark~s')
        ->not->toContain('data-account-item="zoom"')->not->toContain('>Upgrade<');
    // The header is who it is (not a link): the 40 px avatar, the name, the email, the plan and the month's minutes.
    preg_match('~<div class="v2-um-head" data-account-head[^>]*>.*?</div>~s', $menu, $head);
    expect($head[0] ?? '')->toContain('<span class="v2-avatar is-large is-coloured" data-avatar-color="0"')
        ->toContain('<span class="v2-um-name"><bdi>Saif Hassam</bdi></span>')
        ->toContain('<span class="v2-um-email"><bdi dir="ltr">saif@example.com</bdi></span>')
        ->toMatch('~<span class="v2-um-plan">[^<]+</span>~')
        ->not->toContain('<a ');

    // With a photo: Edit profile in Add photo's place.
    $this->owner->forceFill(['avatar_version' => 1_700_000_000])->save();
    accountConsole(true);
    $menu = accountMenuOf((string) $this->get('/app/sessions')->getContent());
    expect(accountItems($menu))->toBe(['profile', 'plan', 'settings', 'shortcuts', 'help', 'sign-out'])
        ->and($menu)->toContain('href="'.e(EditProfile::settingsUrl('profile')).'"')->toMatch('~>\s*Edit profile\s*<~');
});

it('says the plan as written and the month\'s minutes in the header, in English and in Urdu', function () {
    Plan::query()->where('key', 'free')->update(['monthly_minutes' => 30]);
    Workspace::query()->whereKey($this->owner->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', 'free')->value('id'), 'plan_expires_at' => null]);
    UsageLedgerEntry::query()->create(['workspace_id' => $this->owner->current_workspace_id, 'user_id' => $this->owner->id, 'source' => 'server', 'report_id' => 'menu-minutes',
        'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => 12 * 60000, 'channels' => 1, 'billed_ms' => 12 * 60000,
        'unit_price_usd' => 0, 'cost_usd' => 0, 'billable' => true, 'occurred_at' => now()]);
    $line = fn (): string => preg_match('~<span class="v2-um-plan">([^<]+)</span>~', (string) $this->get('/app/sessions')->assertOk()->getContent(), $m) === 1
        ? str_replace(["\u{2068}", "\u{2069}"], '', html_entity_decode($m[1], ENT_QUOTES)) : '';

    accountConsole(true);
    expect($line())->toBe('Free · 12 of 30 min used this month');
    accountConsole(true, 'ur');
    expect($line())->toBe('Free · اس مہینے 30 میں سے 12 منٹ استعمال ہوئے');
});

it('adds the Zoom row only where v21_zoom is on: the browser\'s own keys, as a page can\'t set its zoom', function () {
    accountConsole(true, zoom: true);
    $menu = accountMenuOf((string) $this->get('/app/sessions')->assertOk()->getContent());
    expect(accountItems($menu))->toBe(['photo', 'zoom', 'plan', 'settings', 'shortcuts', 'help', 'sign-out'])
        ->and($menu)->toContain('<span class="v2-um-key" dir="ltr"><kbd>Ctrl +</kbd> <kbd>Ctrl −</kbd></span>');
    $mac = (string) $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 15_6) AppleWebKit/537.36')->get('/app/sessions')->getContent();
    expect(accountMenuOf($mac))->toContain('<kbd>⌘ +</kbd> <kbd>⌘ −</kbd>');

    accountConsole(true);
    expect((string) $this->get('/app/sessions')->getContent())->not->toContain('data-account-item="zoom"');
});

it('draws Settings › Profile with a 96 px avatar and a camera badge that adds the photo, or changes it', function () {
    accountConsole(true);
    $profile = (string) $this->get('/app/settings/profile')->assertOk()->getContent();
    expect($profile)->toContain('<span class="v2-avatar is-profile is-coloured" data-avatar-color="0"')
        ->toMatch('~<button type="button" class="v2-profile-camera" wire:click="mountAction\(\'changePhoto\'\)" data-photo-badge\s+aria-label="Add photo" title="Add photo">~');

    // The menu's Add photo opens the photo window there.
    $page = Livewire::withQueryParams(['action' => 'changePhoto'])->test(EditProfile::class, ['subpage' => 'profile'])->call('mountDefaultActions');
    expect(collect($page->get('mountedActions'))->pluck('name')->all())->toBe(['changePhoto']);

    $this->owner->forceFill(['avatar_version' => 1_700_000_000])->save();
    accountConsole(true);
    expect((string) $this->get('/app/settings/profile')->getContent())->toContain('aria-label="Change photo" title="Change photo"');
});

it('shows on Activity the avatar of the person an item is about, in the new console only', function () {
    app(ActivityFeed::class)->sharedWithYou($this->owner, ActivityFeed::line(':name sent you :title', ['name' => 'Farah Khan', 'title' => 'Call']), 'Open it in Shared with me.', null, $this->farah);
    // One from before UX-18 (no actor_id): its sender, as Sent to keeps them.
    $older = Fx::session($this->farah, Fx::device($this->farah));
    SentItem::query()->create(['workspace_id' => $older->workspace_id, 'from_user_id' => $this->farah->id, 'to_user_id' => $this->owner->id, 'kind' => 'recording',
        'capture_session_id' => $older->id, 'title' => 'Older call', 'sent_at' => now()]);
    app(ActivityFeed::class)->sharedWithYou($this->owner, 'Farah Khan sent you Older call', 'Open it in Shared with me.', $older->id);
    // Not about a person: its tile, as before. A warning about a person keeps its tile too: it is the only sign.
    app(ActivityFeed::class)->post($this->owner, 'budget_warn', 'Most of your minutes are used', '80% of this month.', null, 'heroicon-o-chart-bar');
    app(ActivityFeed::class)->post($this->owner, 'unusual_usage', 'Unusual use', 'Farah Khan recorded 9 hours in a day.', null, 'heroicon-o-exclamation-triangle', actor: $this->farah);

    accountConsole(true);
    $html = (string) $this->get('/app/activity')->assertOk()->getContent();
    $farah = '<span class="v2-avatar is-list is-coloured" data-avatar-color="4" style="background-color: var(--el-role-avatar-4)" aria-hidden="true">FK</span>';
    expect(substr_count($html, $farah))->toBe(2)
        ->and($html)->toContain('<span class="el-tile el-tile-amber">')
        ->and(substr_count($html, '<span class="el-tile el-tile-slate">'))->toBe(1, 'Unusual use keeps its tile');

    // Classic: every row keeps its tile, byte for byte.
    accountConsole(false);
    $classic = (string) $this->get('/app/activity')->assertOk()->getContent();
    expect($classic)->not->toContain('v2-avatar')->toContain('<span class="el-tile el-tile-slate">')
        ->toMatch('~</svg></span>\n                <span class="el-row-main">~');
});

it('shows the latest sender of a share from before UX-18, as its title names them', function () {
    $omar = Fx::user(['name' => 'Omar Malik', 'email' => 'omar@example.com']);
    $omar->forceFill(['avatar_color' => 1])->save();
    $call = Fx::session($this->farah, Fx::device($this->farah));
    // Omar's row is written first, Farah's after it; Omar sent it last.
    foreach ([[$omar, now()], [$this->farah, now()->subDay()]] as [$from, $at]) {
        SentItem::query()->create(['workspace_id' => $call->workspace_id, 'from_user_id' => $from->id, 'to_user_id' => $this->owner->id, 'kind' => 'recording',
            'capture_session_id' => $call->id, 'title' => 'Call', 'sent_at' => $at]);
    }
    app(ActivityFeed::class)->sharedWithYou($this->owner, 'Omar Malik sent you Call', 'Open it in Shared with me.', $call->id);

    accountConsole(true);
    expect((string) $this->get('/app/activity')->assertOk()->getContent())
        ->toContain('<span class="v2-avatar is-list is-coloured" data-avatar-color="1" style="background-color: var(--el-role-avatar-1)" aria-hidden="true">OM</span>')
        ->not->toContain('aria-hidden="true">FK</span>');
});

it('keeps who an item is about with the item: a share\'s sender, a customer\'s receipt for staff', function () {
    app(ActivityFeed::class)->sharedWithYou($this->owner, 'Farah Khan sent you Call', 'Open it in Shared with me.', null, $this->farah);
    app(ActivityFeed::class)->post($this->owner, 'upgrade_requested', 'Receipt for Pro', 'Farah Khan (farah@example.com)', null, 'heroicon-o-arrow-trending-up', actor: $this->farah);
    $data = $this->owner->notifications()->get()->map(fn ($n): mixed => $n->data['viewData']['actor_id'] ?? null)->all();
    expect($data)->toBe([$this->farah->id, $this->farah->id])
        // The apps' activity is as it was: no new field.
        ->and(array_keys(ActivityFeed::payload($this->owner->notifications()->first())))->not->toContain('actor_id');
});
