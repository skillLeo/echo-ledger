<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Support\SettingsContext;
use App\Models\CaptureSession;
use App\Models\SentItem;
use App\Models\UpgradeRequest;
use App\Services\ActivityFeed;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * DES-08 + UX-18 on the web console: the avatar you can always see. In the rail it is "Account": a 28 px avatar in a
 * 40 px target, its name under it, the tooltip "Account, theme and settings", named "Account, <name>", a dot only for
 * an account problem (on the web: a payment that failed), one of the rail's stops. Its menu opens upward: a header
 * (avatar, name, email, plan and the month's minutes), then Add photo (no photo) or Edit profile, Theme, Zoom
 * (v21_zoom), Plan & billing, Settings, Keyboard shortcuts, Help, Sign out. Settings › Profile has a 96 px avatar with a
 * camera badge; Activity shows the avatar of the person an item is about. Classic keeps every byte (the goldens).
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

it('shows the dot only for an account problem: a payment staff asked for again', function () {
    $request = UpgradeRequest::query()->create(['user_id' => $this->owner->id, 'workspace_id' => $this->owner->current_workspace_id, 'plan' => 'pro', 'status' => UpgradeRequest::PENDING]);
    accountConsole(true);
    // Waiting for payment, or in review: nothing wrong yet.
    expect(accountMenuOf((string) $this->get('/app/sessions')->getContent()))->not->toContain('v2-um-dot');

    $request->forceFill(['paid_at' => now()->subHour(), 'proof_requested_at' => now()])->save();
    SettingsContext::forget();
    $menu = accountMenuOf((string) $this->get('/app/sessions')->getContent());
    expect($menu)->toContain('aria-label="Account, Saif Hassam, a payment needs your attention"')
        ->toContain('<span class="v2-um-dot" aria-hidden="true"></span>');
});

it('opens a menu with the header, then Add photo without a photo, Theme, Plan & billing, Settings, Keyboard shortcuts, Help, Sign out', function () {
    accountConsole(true);
    $menu = accountMenuOf((string) $this->get('/app/sessions')->assertOk()->getContent());

    expect(accountItems($menu))->toBe(['photo', 'plan', 'settings', 'shortcuts', 'help', 'sign-out'])
        ->and($menu)->toContain('href="'.e(EditProfile::settingsUrl('profile')).'?action=changePhoto"')
        ->toContain('href="'.e(EditProfile::settingsUrl('plan')).'"')
        ->toContain("x-on:click=\"\$dispatch('el-help')\"")
        ->toContain('<kbd class="v2-um-key" aria-hidden="true">?</kbd>')
        ->toMatch('~role="radiogroup" aria-labelledby="v2-um-theme">.*System.*Light.*Dark~s')
        ->not->toContain('data-account-item="zoom"')->not->toContain('>Upgrade<');
    // The header is who it is (not a link): the 40 px avatar, the name, the email, the plan and the month's minutes.
    preg_match('~<div class="v2-um-head" data-account-head>.*?</div>~s', $menu, $head);
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

it('says the plan and the month\'s minutes in the header', function () {
    accountConsole(true);
    $line = preg_match('~<span class="v2-um-plan">([^<]+)</span>~', (string) $this->get('/app/sessions')->getContent(), $m) === 1 ? $m[1] : '';
    $plan = SettingsContext::current()?->plan();
    expect($line)->toStartWith((string) $plan?->name)->toMatch('~^[^·]+( · \d[\d,]* of \d[\d,]* min used this month)?$~u');
});

it('adds the Zoom row and the page\'s zoom only where v21_zoom is on', function () {
    accountConsole(true, zoom: true);
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect(accountItems(accountMenuOf($html)))->toBe(['photo', 'zoom', 'plan', 'settings', 'shortcuts', 'help', 'sign-out'])
        ->and($html)->toContain('window.ElZoom = window.ElZoomCore.install(document, window);')
        ->toContain('aria-label="Zoom out"')->toContain('aria-label="Zoom in"')->toContain('x-text="level + \'%\'">100%</output>');

    accountConsole(true);
    expect((string) $this->get('/app/sessions')->getContent())->not->toContain('ElZoomCore')->not->toContain('data-account-item="zoom"');
});

it('keeps the page\'s zoom as its Node test says (Chrome\'s steps, kept in this browser, before the first paint)', function () {
    $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/zoom-core.test.mjs']);
    expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');

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
    $session = Fx::session($this->owner, Fx::device($this->owner));
    app(ActivityFeed::class)->sharedWithYou($this->owner, ActivityFeed::line(':name sent you :title', ['name' => 'Farah Khan', 'title' => 'Call']), 'Open it in Shared with me.', null, $this->farah);
    // One from before UX-18 (no actor_id): its sender, as Sent to keeps them.
    $older = Fx::session($this->farah, Fx::device($this->farah));
    SentItem::query()->create(['workspace_id' => $older->workspace_id, 'from_user_id' => $this->farah->id, 'to_user_id' => $this->owner->id, 'kind' => 'recording',
        'capture_session_id' => $older->id, 'title' => 'Older call', 'sent_at' => now()]);
    app(ActivityFeed::class)->sharedWithYou($this->owner, 'Farah Khan sent you Older call', 'Open it in Shared with me.', $older->id);
    // Not about a person: its tile, as before.
    app(ActivityFeed::class)->post($this->owner, 'budget_warn', 'Most of your minutes are used', '80% of this month.', null, 'heroicon-o-chart-bar');
    expect(CaptureSession::query()->whereKey($session->id)->exists())->toBeTrue();

    accountConsole(true);
    $html = (string) $this->get('/app/activity')->assertOk()->getContent();
    $farah = '<span class="v2-avatar is-list is-coloured" data-avatar-color="4" style="background-color: var(--el-role-avatar-4)" aria-hidden="true">FK</span>';
    expect(substr_count($html, $farah))->toBe(2)
        ->and($html)->toContain('<span class="el-tile el-tile-amber">');

    // Classic: every row keeps its tile, byte for byte.
    accountConsole(false);
    $classic = (string) $this->get('/app/activity')->assertOk()->getContent();
    expect($classic)->not->toContain('v2-avatar')->toContain('<span class="el-tile el-tile-slate">')
        ->toMatch('~</svg></span>\n                <span class="el-row-main">~');
});

it('keeps who an item is about with the item: a share\'s sender, a customer\'s receipt for staff', function () {
    app(ActivityFeed::class)->sharedWithYou($this->owner, 'Farah Khan sent you Call', 'Open it in Shared with me.', null, $this->farah);
    app(ActivityFeed::class)->post($this->owner, 'upgrade_requested', 'Receipt for Pro', 'Farah Khan (farah@example.com)', null, 'heroicon-o-arrow-trending-up', actor: $this->farah);
    $data = $this->owner->notifications()->get()->map(fn ($n): mixed => $n->data['viewData']['actor_id'] ?? null)->all();
    expect($data)->toBe([$this->farah->id, $this->farah->id])
        // The apps' activity is as it was: no new field.
        ->and(array_keys(ActivityFeed::payload($this->owner->notifications()->first())))->not->toContain('actor_id');
});
