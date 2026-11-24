<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Activity;
use App\Filament\Support\SettingsContext;
use App\Livewire\Shell\Sidebar;
use App\Models\User;
use App\Services\InterfaceFlags;
use Livewire\Livewire;

/*
 * The new frame's navigation rows of docs/v2/audit/parity.csv (docs/PLAN_V2_PARITY.md section 2), since
 * docs/PLAN_NAV_3_LEVELS.md in the rail and the panels: ● Record and the places in the rail, Projects with its projects
 * and their counts, My links and Request links, Activity with its badge, the panel that folds and its style, + in the
 * top bar, the section above the title, and the phone-width bar Library · Projects · Record · Live · Ask; Settings ›
 * Appearance and its menu in Urdu. The earlier v2 sidebar (the new shell without the three levels) keeps its resize edge.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
    webParityDesign(true);
});

/** One page as the person sees it, read fresh. */
function navPage(object $test, User $owner, string $url): string
{
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($owner->fresh(), 'web')->get($url)->assertOk()->getContent();
}

/** The frame's part of the page (the rail and every place's panel), spaces as one. */
function navSidebar(string $html): string
{
    $start = strpos($html, 'class="v2-frame-inner"');
    $end = strpos($html, 'v2-tb-ctn', (int) $start) ?: strlen($html);

    return (string) preg_replace(['/<!--.*?-->/s', '/\s+/', '/> </'], ['', ' ', '><'], substr($html, (int) $start, (int) $end - (int) $start));
}

/** The rail alone. */
function navRail(string $html): string
{
    $frame = navSidebar($html);

    return substr($frame, 0, (int) (strpos($frame, 'id="v2-panels"') ?: strlen($frame)));
}

it('puts the logo (Home) and ● Record at the top of the rail, then Library, Projects, Live, Ask and Activity, and Setup, Help, Settings and the avatar at its foot', function () {
    // docs/PLAN_NAV_3_LEVELS.md section 2: the rail replaces the sidebar; Trash is the Library panel's foot.
    $rail = navRail(navPage($this, $this->owner, $this->pages['library']));
    $at = fn (string $needle): int => (int) strpos($rail, $needle);

    expect($rail)->toContain('data-sidebar-upload')->toContain('href="https://echoledger.test/app/upload?record=1"')->toContain('href="https://echoledger.test/app/upload"')
        ->toContain('Record a voice note')->toContain('Import audio files')->toContain('<span class="v2-sr">Home</span>')
        ->and($at('data-module="home"'))->toBeLessThan($at('data-sidebar-upload'))
        ->and($at('data-sidebar-upload'))->toBeLessThan($at('data-module="library"'))
        ->and($at('data-module="library"'))->toBeLessThan($at('data-module="projects"'))
        ->and($at('data-module="projects"'))->toBeLessThan($at('data-module="live"'))
        ->and($at('data-module="live"'))->toBeLessThan($at('data-module="ask"'))
        ->and($at('data-module="ask"'))->toBeLessThan($at('data-module="activity"'))
        ->and($at('data-module="activity"'))->toBeLessThan($at('class="v2-rail-foot"'))
        ->and($at('class="v2-rail-foot"'))->toBeLessThan($at('data-module="help"'))
        ->and($at('data-module="help"'))->toBeLessThan($at('data-module="settings"'))
        ->and($at('data-module="settings"'))->toBeLessThan($at('class="v2-rail-avatar"'));
    // Each place's shortcut in its tooltip, ⌘1…⌘6 (Ctrl off a Mac): Settings, at the foot, is the sixth.
    expect($rail)->toContain('title="Library (Ctrl 1)"')->toContain('title="Activity (Ctrl 5)"')->toContain('data-rail-shortcut="6"')
        ->and(navSidebar(navPage($this, $this->owner, $this->pages['library'])))->toMatch('~class="v2-panel-foot"><a href="[^"]*/app/recently-deleted"[^>]*data-place="trash">~');
});

it('lists the projects in the Projects panel with their counts, and My links and Request links in the Library panel', function () {
    $projects = navSidebar(navPage($this, $this->owner, $this->pages['projects']));
    // Qureshi Textiles holds two of the person's recordings (the third was deleted).
    expect($projects)->toMatch('~data-project="[^"]+"><span class="v2-sb-label" dir="auto"><bdi>Qureshi Textiles</bdi></span><span class="v2-sb-subcount">2</span>~')
        ->toMatch('~<section class="v2-panel" data-panel="projects" aria-label="Projects" x-bind:hidden~');
    $side = navSidebar(navPage($this, $this->owner, $this->pages['library']));
    expect($side)->toContain('data-place="links"')->toContain('data-place="requests"')
        ->toContain('href="https://echoledger.test/app/shares"')->toContain('href="https://echoledger.test/app/request-links"');
    // On My links, that place is the one you are on, in the Library's panel.
    expect(navSidebar(navPage($this, $this->owner, $this->pages['shares'])))->toMatch('~is-on" aria-current="page" data-place="links"~');
});

it('shows Activity in the rail with what needs you as its badge, and the Library with its needs-you count', function () {
    $rail = navRail(navPage($this, $this->owner, $this->pages['library']));
    $badge = (string) Activity::getNavigationBadge();

    expect($badge)->not->toBe('')
        ->and($rail)->toMatch('~data-module="activity".*?<span class="v2-rail-label">Activity</span><span class="v2-rail-badge"><span class="v2-sr">, needs you: </span>'.preg_quote($badge, '~').'</span>~')
        ->toMatch('~data-module="library".*?<span class="v2-rail-label">Library</span><span class="v2-rail-badge"><span class="v2-sr">, needs you: </span>\d+</span>~');
});

it('folds the place\'s panel with ⊟ or ⌘\\ and remembers it for each place; under the flyout width a rail icon opens it over the page', function () {
    $html = navPage($this, $this->owner, $this->pages['library']);
    expect($html)->toContain('x-on:click="togglePanel()" data-panel-toggle')->toContain('aria-label="Hide the panel"')
        ->toContain('x-data="elV2Frame(')->toContain("x-bind:class=\"{ 'is-flyout': flyout !== null, 'is-narrow': narrow }\"")
        ->toContain("x-on:click=\"railClick(\$event, 'library')\"")->toContain("store.get(this.memory('panel', this.place))")
        ->toContain("token('--el-breakpoint-flyout', '--el-breakpoint-medium')");
    // Every place's panel is drawn, the one you are in shown, the others for the flyout.
    $side = navSidebar($html);
    expect($side)->toMatch('~<section class="v2-panel" data-panel="library" aria-label="Library" x-bind:hidden~')
        ->toMatch('~<section class="v2-panel" data-panel="ask" aria-label="Ask" hidden x-bind:hidden~');
    // The rail is 72 wide and the panel 260 (tokens only), the panel folded leaves the rail.
    $css = (string) file_get_contents(resource_path('css/v2/console.css'));
    expect($css)->toContain('--sidebar-width: calc(var(--el-space-24) * 3 + var(--el-space-20) * 13);')->toContain('--collapsed-sidebar-width: calc(var(--el-space-24) * 3);')
        ->toContain('.v2-frame:not(.fi-sidebar-open) .v2-panels');
});

it('resizes the sidebar from its edge, by dragging or with the arrow keys, and remembers the width (the new shell without the three levels)', function () {
    // The new shell alone (ui_v2.shell without ui_v2.library) still ships the earlier v2 sidebar, with its resize edge.
    app(InterfaceFlags::class)->set('ui_v2.library', false, 0, [], [], 'off');
    $html = navPage($this, $this->owner, $this->pages['library']);
    expect($html)->not->toContain('class="v2-frame-inner"')
        ->toContain('class="v2-sb-resize" role="separator"')->toContain('aria-label="Resize the sidebar"')
        ->toContain('x-on:pointerdown="startResize($event)"')->toContain('x-on:keydown="resizeKey($event)"')
        ->toContain('x-data="elV2Sidebar(')->toContain('window.elV2Sidebar');

    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(Sidebar::class)->call('rememberShell', 'width', 300);
    expect($this->owner->fresh()->console_preferences['shell']['width'])->toBe(300);
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('aria-valuenow="300"')->toContain('300px');
});

it('offers Sidebar style (Light, Dark, Soft grey) for the rail and its panels, starts on Soft grey and draws the choice', function () {
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('class="fi-sidebar fi-main-sidebar v2-frame"')->toContain('data-style="soft"');
    $page = navPage($this, $this->owner, $this->pages['settings-appearance']);
    expect($page)->toContain('data-setting="appearance.sidebar"')->toContain('Soft grey')->toContain("value: 'soft'");

    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->call('setPreference', 'style', 'dark');
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('data-style="dark"')
        ->and((string) file_get_contents(resource_path('css/v2/console.css')))->toContain(":root[data-theme='light'] .v2-frame[data-style='dark'] .v2-rail");

    // Reset to defaults gives the frame its own default, Soft grey, back.
    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->callAction('resetPage');
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('data-style="soft"');
});

it('keeps Help and shortcuts in reach, as classic\'s top bar ? does: Help at the rail\'s foot, in the avatar menu, and its dialog on the page', function () {
    $html = navPage($this, $this->owner, $this->pages['library']);
    $rail = navRail($html);
    $foot = substr($rail, (int) strpos($rail, 'class="v2-rail-foot"'));
    expect($foot)->toContain("x-on:click=\"\$dispatch('el-help')\"")->toContain('title="Help and shortcuts"')
        ->and(collect(array_keys(webParityControls($html, 'usermenu')))->filter(fn (string $k): bool => str_starts_with($k, 'menuitem|') && str_ends_with($k, '› help and shortcuts'))->count())->toBe(1)
        ->and($html)->toContain('x-on:el-help.window="open = true"');
});

it('has + in the top bar with Upload recording, New project and Request link', function () {
    $html = navPage($this, $this->owner, $this->pages['search']);
    expect(webParityControls($html, 'topbar'))->toHaveKeys(['button|create', 'menuitem|create › upload recording', 'menuitem|create › new project', 'menuitem|create › request link'])
        ->and($html)->toContain('href="https://echoledger.test/app/clients/create"')->toContain('href="https://echoledger.test/app/request-links?action=create"');
});

it('shows the section above the title (Library › Search, Shared › My links), and none on a section\'s own page', function () {
    $crumbs = fn (string $html): string => trim((string) preg_replace(['/<!--.*?-->/s', '/<[^>]+>/', '/\s+/'], ['', ' ', ' '], (string) (preg_match('~<nav class="v2-crumbs".*?</nav>~s', $html, $m) ? $m[0] : '')));
    expect($crumbs(navPage($this, $this->owner, $this->pages['search'])))->toBe('Library Search')
        ->and($crumbs(navPage($this, $this->owner, $this->pages['shares'])))->toBe('Shared My links')
        ->and(navPage($this, $this->owner, $this->pages['library']))->not->toContain('class="v2-crumbs"');
});

it('has the phone-width bar Library · Projects · Record · Live · Ask, Record in the middle', function () {
    $html = navPage($this, $this->owner, $this->pages['library']);
    preg_match_all('~data-bottom="([a-z]+)"~', $html, $m);
    expect($m[1])->toBe(['library', 'projects', 'record', 'live', 'ask'])
        ->and($html)->toContain('href="https://echoledger.test/app/upload?record=1" class="v2-bn-item v2-bn-record"');
});

it('reads Settings › Appearance and the Settings menu in Urdu', function () {
    $prefs = $this->owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $this->owner->forceFill(['console_preferences' => $prefs])->save();
    $html = navPage($this, $this->owner, $this->pages['settings-appearance']);

    foreach (['تھیم', 'سسٹم', 'لائٹ', 'ڈارک', 'سائیڈ بار کا انداز', 'ہلکا سرمئی', 'کثافت', 'کھلا', 'گھنا', 'وقت', '12 گھنٹے', '24 گھنٹے', 'لائبریری کا منظر', 'فہرست', 'جدول',
        'ظاہری شکل', 'اطلاعات', 'کی بورڈ شارٹ کٹس', 'پروفائل', 'پلان اور استعمال', 'سائن اِن اور سکیورٹی', 'پرائیویسی اور ڈیٹا', 'ترتیبات میں تلاش', 'سائن آؤٹ'] as $words) {
        expect($html)->toContain($words);
    }
    foreach (['>Theme<', '>System<', '>Sidebar style<', '>Density<', '>Times<', '>12-hour<', '>Library view<', '>Appearance<', '>Notifications<', 'placeholder="Search settings"', '>Sign out<'] as $english) {
        expect($html)->not->toContain($english);
    }
});
