<?php

declare(strict_types=1);

use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Activity;
use App\Filament\Support\SettingsContext;
use App\Livewire\Shell\Sidebar;
use App\Models\User;
use Livewire\Livewire;

/*
 * The new frame's navigation rows of docs/v2/audit/parity.csv (docs/PLAN_V2_PARITY.md section 2): Upload recording at
 * the top of the sidebar, Projects with its projects and their counts, My links and Request links, Activity with its
 * badge at the foot, the sidebar's edge that resizes it and its style, + in the top bar, the section above the title,
 * and the phone-width bar Library · Projects · Record · Live · Ask; Settings › Appearance and its menu in Urdu.
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

/** The sidebar's part of the page, spaces as one. */
function navSidebar(string $html): string
{
    $start = strpos($html, 'class="v2-sb-inner"');
    $end = strpos($html, '</nav>', strpos($html, 'class="v2-sb-foot"') ?: 0) ?: strpos($html, 'v2-sb-resize');

    return (string) preg_replace(['/<!--.*?-->/s', '/\s+/', '/> </'], ['', ' ', '><'], substr($html, (int) $start, (int) $end - (int) $start + 600));
}

it('puts ● Record ▾ at the top of the sidebar, then Home, Library, Projects, Live and Ask, and Trash, Activity and Settings at its foot', function () {
    // Since 2.0.0-p (docs/PLAN_LIBRARY_FILTERS.md): Upload recording is ● Record ▾ (Record a voice note; Import audio
    // files in its menu), and Recently deleted is Trash at the foot.
    $side = navSidebar(navPage($this, $this->owner, $this->pages['library']));
    $at = fn (string $needle): int => (int) strpos($side, $needle);

    expect($side)->toContain('data-sidebar-upload')->toContain('href="https://echoledger.test/app/upload?record=1"')->toContain('href="https://echoledger.test/app/upload"')
        ->toContain('Record a voice note')->toContain('Import audio files')
        ->and($at('data-sidebar-upload'))->toBeLessThan($at('data-module="home"'))
        ->and($at('data-module="home"'))->toBeLessThan($at('data-module="library"'))
        ->and($at('data-module="library"'))->toBeLessThan($at('data-module="projects"'))
        ->and($at('data-module="projects"'))->toBeLessThan($at('data-module="live"'))
        ->and($at('data-module="live"'))->toBeLessThan($at('data-module="ask"'))
        ->and($at('class="v2-sb-foot"'))->toBeLessThan($at('data-place="trash"'))
        ->and($at('data-place="trash"'))->toBeLessThan($at('data-module="activity"'))
        ->and($at('data-module="activity"'))->toBeLessThan($at('data-module="settings"'));
});

it('lists the projects under Projects with their counts, and My links and Request links under Library', function () {
    $side = navSidebar(navPage($this, $this->owner, $this->pages['library']));

    // Qureshi Textiles holds two of the person's recordings (the third was deleted).
    expect($side)->toMatch('~data-project="[^"]+"><span class="v2-sb-label" dir="auto"><bdi>Qureshi Textiles</bdi></span><span class="v2-sb-subcount">2</span>~')
        ->toContain('data-place="links"')->toContain('data-place="requests"')
        ->toContain('href="https://echoledger.test/app/shares"')->toContain('href="https://echoledger.test/app/request-links"');
    // On My links, that place is the one you are on.
    expect(navSidebar(navPage($this, $this->owner, $this->pages['shares'])))->toMatch('~is-on" aria-current="page" data-place="links"~');
});

it('shows Activity with what needs you as its badge', function () {
    $side = navSidebar(navPage($this, $this->owner, $this->pages['library']));
    $badge = (string) Activity::getNavigationBadge();

    expect($badge)->not->toBe('')
        ->and($side)->toMatch('~data-module="activity">.*?<span class="v2-sb-count is-alert"><span class="v2-sr">, needs you: </span>'.preg_quote($badge, '~').'</span>~');
});

it('resizes the sidebar from its edge, by dragging or with the arrow keys, and remembers the width', function () {
    $html = navPage($this, $this->owner, $this->pages['library']);
    expect($html)->toContain('class="v2-sb-resize" role="separator"')->toContain('aria-label="Resize the sidebar"')
        ->toContain('x-on:pointerdown="startResize($event)"')->toContain('x-on:keydown="resizeKey($event)"')
        ->toContain('x-data="elV2Sidebar(')->toContain('window.elV2Sidebar');

    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(Sidebar::class)->call('rememberShell', 'width', 300);
    expect($this->owner->fresh()->console_preferences['shell']['width'])->toBe(300);
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('aria-valuenow="300"')->toContain('300px');
});

it('offers Sidebar style (Light, Dark, Soft grey) in the new frame, starts on Soft grey and draws the choice', function () {
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('class="fi-sidebar fi-main-sidebar v2-sb"')->toContain('data-style="soft"');
    $page = navPage($this, $this->owner, $this->pages['settings-appearance']);
    expect($page)->toContain('data-setting="appearance.sidebar"')->toContain('Soft grey')->toContain("value: 'soft'");

    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->call('setPreference', 'style', 'dark');
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('data-style="dark"');

    // Reset to defaults gives the new frame its own default, Soft grey, back.
    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(EditProfile::class, ['subpage' => 'appearance'])->callAction('resetPage');
    expect(navPage($this, $this->owner, $this->pages['library']))->toContain('data-style="soft"');
});

it('keeps Help and shortcuts in reach, as classic\'s top bar ? does: in the sidebar\'s foot, in the avatar menu, and its dialog on the page', function () {
    $html = navPage($this, $this->owner, $this->pages['library']);
    $side = navSidebar($html);
    $foot = substr($side, (int) strpos($side, 'class="v2-sb-foot"'));
    expect($foot)->toContain("x-on:click=\"\$dispatch('el-help')\"")->toContain('Help and shortcuts')
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
