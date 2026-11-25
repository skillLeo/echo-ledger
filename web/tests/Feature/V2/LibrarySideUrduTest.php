<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\RecentlyDeleted;
use App\Filament\Pages\Search;
use App\Filament\Support\LibraryFilterBar;
use App\Filament\Support\SettingsContext;
use App\Livewire\Shell\Sidebar;
use App\Livewire\Shell\Topbar;
use App\Models\CaptureSession;
use App\Models\Plan;
use App\Models\Screen;
use App\Models\Workspace;
use App\Services\SettingsRepository;
use Filament\Actions\Action;
use Filament\Actions\Testing\TestAction;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Urdu package 2: the Library's side pages (Search, Recently deleted) and the shell's menus (the avatar menu, the
 * sidebar's words, the top bar) in the console's language. Under the new frame in Urdu their words, filters, columns,
 * actions, windows and notices read in Urdu; in English, and in classic, they read exactly as before: the English pages
 * are compared with the HTML saved in tests/Feature/V2/golden/ before the words were wrapped (UPDATE_GOLDEN=1 saves them
 * again on purpose). Classic draws its own sidebar, top bar and avatar menu, always in English (ConsoleLanguage); their
 * words go through the translator too, checked here by drawing those parts with the locale set by hand.
 */

require_once __DIR__.'/Support/parity_world.php';
require_once __DIR__.'/Support/golden.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
});

afterEach(function () {
    app()->setLocale('en');
});

/** Signs the person in with the console in this language, in the new frame or in classic, as staff or not. */
function librarySideAs(object $test, string $language, bool $v2 = true, bool $staff = false): void
{
    webParityDesign($v2);
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs, 'is_super_admin' => $staff])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $test->actingAs($test->owner->fresh(), 'web');
    app()->setLocale($language);
}

/** Compares [$html] with the saved copy [$name] (UPDATE_GOLDEN=1 saves it). */
function librarySideGolden(string $name, string $html): void
{
    $file = __DIR__."/golden/{$name}.html";
    if (getenv('UPDATE_GOLDEN') === '1') {
        file_put_contents($file, $html);
    }
    expect(file_exists($file))->toBeTrue("no saved copy of {$name}: run with UPDATE_GOLDEN=1 once")
        ->and($html)->toBe(file_get_contents($file), "the English {$name} changed");
}

/** HTML as read: its entities decoded and without the invisible direction marks around names and numbers (B8). */
function librarySideRead(string $html): string
{
    return html_entity_decode(preg_replace('/[\x{2066}-\x{2069}]/u', '', $html) ?? $html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/** A page as drawn, read as above. */
function librarySidePage(object $test, string $url): string
{
    SettingsContext::forget();

    return librarySideRead((string) $test->get($url)->assertOk()->getContent());
}

/**
 * Twenty-one more recordings, each with five lines that say "freight": a first page of twenty with Older and "5
 * matches" on every result, and a second page with Newer.
 */
function librarySideManyResults(object $test): void
{
    $device = Fx::device($test->owner, ['name' => 'Galaxy A55']);
    foreach (range(1, 21) as $n) {
        $started = Carbon::parse('2026-11-10 08:00:00', 'UTC')->subHours($n);
        $session = Fx::session($test->owner, $device, ['client_ref' => sprintf('00000000-0000-4000-9000-%012d', $n), 'title' => 'Freight quote '.$n,
            'source_app' => SourceApp::PhoneCall, 'source_device' => SourceDevice::Phone, 'started_at' => $started,
            'ended_at' => $started->copy()->addMinutes(5), 'duration_ms' => 300_000, 'status' => SessionStatus::Completed]);
        $lines = array_map(fn (int $i): array => ['channel' => 1, 'speaker' => 'Me', 'start_ms' => $i * 1000, 'end_ms' => $i * 1000 + 900,
            'text' => 'The freight for load '.($i + 1), 'words' => []], range(0, 4));
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed,
            'language' => 'en', 'text' => implode(' ', array_column($lines, 'text')), 'segments' => $lines, 'completed_at' => now()]);
    }
}

/** Recently deleted with nothing in it: the deleted recording of the world is erased for good. */
function librarySideEmptyBin(): void
{
    CaptureSession::onlyTrashed()->get()->each(fn (CaptureSession $s) => $s->forceDelete());
}

dataset('library side pages in English', [
    'search, new frame' => ['library-side-search-en', '/app/search', true],
    'search with results, new frame' => ['library-side-search-results-en', '/app/search?q=proforma', true],
    'search without results, new frame' => ['library-side-search-none-en', '/app/search?q=zebra+crossing', true],
    'recently deleted, new frame' => ['library-side-deleted-en', '/app/recently-deleted', true],
    'search with results, classic' => ['library-side-classic-search-en', '/app/search?q=proforma', false],
    'search without results, classic' => ['library-side-classic-search-none-en', '/app/search?q=zebra+crossing', false],
    'recently deleted, classic' => ['library-side-classic-deleted-en', '/app/recently-deleted', false],
    // On the top plan the avatar menu has Plan and usage (Plan & usage in classic) in place of Upgrade.
    'search on the top plan, new frame' => ['library-side-search-top-en', '/app/search?q=proforma', true, 'business'],
    'search on the top plan, classic' => ['library-side-classic-search-top-en', '/app/search?q=proforma', false, 'business'],
    // Older and "5 matches" on every result; Recently deleted with nothing in it.
    'search with many results, new frame' => ['library-side-search-many-en', '/app/search?q=freight', true, null, 'many'],
    'search with many results, classic' => ['library-side-classic-search-many-en', '/app/search?q=freight', false, null, 'many'],
    'recently deleted empty, new frame' => ['library-side-deleted-empty-en', '/app/recently-deleted', true, null, 'empty'],
    'recently deleted empty, classic' => ['library-side-classic-deleted-empty-en', '/app/recently-deleted', false, null, 'empty'],
]);

// Each page is the first request of its test: Livewire adds its styles to the first page a process serves.
it('renders the English pages and the shell around them byte for byte as before', function (string $name, string $url, bool $v2, ?string $plan = null, ?string $world = null) {
    expect(config('app.debug'))->toBeFalse('the saved pages need debug off: run the suite with APP_DEBUG unset');
    if ($plan !== null) {
        Workspace::query()->whereKey($this->owner->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', $plan)->value('id'), 'plan_expires_at' => null]);
    }
    match ($world) {
        'many' => librarySideManyResults($this),
        'empty' => librarySideEmptyBin(),
        default => null,
    };
    librarySideAs($this, 'en', $v2, staff: true);
    $html = normaliseClassic((string) $this->get($url)->assertOk()->getContent(), (string) session()->token());
    librarySideGolden($name, $html);
})->with('library side pages in English');

it('renders Search\'s second page (Newer) in English byte for byte as before', function () {
    librarySideManyResults($this);
    librarySideAs($this, 'en');
    $page = Livewire::test(Search::class)->set('filters.q', 'freight')->call('nextPage');
    librarySideGolden('library-side-search-page2-en', normaliseClassic($page->html()));
});

/*
 * Search: what each state of the page says in Urdu and in English, the same places. Each check is the Urdu words
 * followed by the English ones.
 */
dataset('search states', [
    'empty' => ['/app/search', null, [
        'تلاش</h1>', 'Search</h1>',
        'کم از کم دو حروف لکھیں۔ ہر لفظ کا ملنا ضروری ہے؛ فلٹرز نتائج کو محدود کرتے ہیں۔', 'Type at least two characters. Every word must match; filters narrow the results.',
        'placeholder="عنوانات اور ٹرانسکرپٹس میں تلاش کریں — "واوین میں لکھے الفاظ" ایک ساتھ رہتے ہیں"', 'placeholder="Search titles and transcripts — "quoted words" stay together"',
    ]],
    'filters' => ['/app/search', null, [
        // Each filter's label and its "any" choice, the date range's ends, the toggle, and the enums' and tags' choices.
        '>پروجیکٹ<', '>Project<', '>کوئی بھی پروجیکٹ<', '>Any project<',
        '>ایپ<', '>App<', '>کوئی بھی ایپ<', '>Any app<', '>فون کال<', '>Phone call<', '>وائس نوٹ<', '>Voice note<',
        '>ڈیوائس<', '>Device<', '>کوئی بھی ڈیوائس<', '>Any device<', '>کمپیوٹر<', '>Desktop<',
        '>حالت<', '>Status<', '>کوئی بھی حالت<', '>Any status<', '>ناکام<', '>Failed<', '>صرف آڈیو<', '>Audio only<',
        '>تاریخ سے<', '>From<', '>تاریخ تک<', '>To<',
        '>بُک مارک ٹیگ<', '>Bookmark tag<', '>کوئی بھی ٹیگ<', '>Any tag<', '>آخری تاریخ<', '>Deadline<',
        '>صرف بُک مارک شدہ<', '>Bookmarked only<',
    ]],
    'with results' => ['/app/search?q=proforma', null, [
        // The date in Urdu reads day, month, year (ConsoleDates); the app in the console's language.
        '17 نومبر 2026 · 9:00 صبح · واٹس ایپ', 'Nov 17, 2026 · 9:00 AM · WhatsApp',
        'aria-label="Payment terms for the March shipment کو 0:00 سے چلائیں"', 'aria-label="Play Payment terms for the March shipment from 0:00"',
    ]],
    'without results' => ['/app/search?q=zebra+crossing', null, [
        '“zebra crossing” کے لیے کچھ نہیں ملا۔ کم الفاظ یا دوسرے فلٹرز آزمائیں۔', 'Nothing found for “zebra crossing”. Try fewer words or other filters.',
    ]],
    'many results' => ['/app/search?q=freight', 'many', [
        '· 5 نتائج', '· 5 matches',
        'wire:click="nextPage">پرانے نتائج</button>', 'wire:click="nextPage">Older</button>',
    ]],
]);

/**
 * The checks of one state in one language: the Urdu words followed by the English ones.
 *
 * @param  list<string>  $checks
 */
function librarySideExpect(string $html, array $checks, string $language): void
{
    foreach (array_chunk($checks, 2) as [$urdu, $english]) {
        $language === 'ur' ? expect($html)->toContain($urdu)->not->toContain($english) : expect($html)->toContain($english)->not->toContain($urdu);
    }
}

/** The page's HTML with the spaces around its tags gone, so each label reads as >label<. */
function librarySideTight(string $html): string
{
    return (string) preg_replace(['/>\s+/u', '/\s+</u'], ['>', '<'], $html);
}

it('words each state of Search in Urdu in the new frame', function (string $url, ?string $world, array $checks) {
    if ($world === 'many') {
        librarySideManyResults($this);
    }
    librarySideAs($this, 'ur');
    $html = librarySideTight(librarySidePage($this, $url));
    librarySideExpect($html, $checks, 'ur');
    expect($html)->toMatch('~<title>تلاش\s+-~u')->not->toMatch('~<title>Search\s+-~');
})->with('search states');

it('keeps each state of Search in English exactly as it was', function (string $url, ?string $world, array $checks) {
    if ($world === 'many') {
        librarySideManyResults($this);
    }
    foreach ([true, false] as $v2) {
        librarySideAs($this, 'en', $v2);
        $html = librarySideTight(librarySidePage($this, $url));
        librarySideExpect($html, $checks, 'en');
        expect($html)->toMatch('~<title>Search\s+-~');
    }
})->with('search states');

it('words Search\'s second page and its Newer in Urdu, and keeps their English', function () {
    librarySideManyResults($this);
    $second = function (string $language): string {
        librarySideAs($this, $language);

        return librarySideRead(Livewire::test(Search::class)->set('filters.q', 'freight')->call('nextPage')->html());
    };

    expect($second('ur'))->toContain('wire:click="previousPage">نئے نتائج</button>')->not->toContain('>Newer<')->not->toContain('پرانے نتائج')
        ->and($second('en'))->toContain('wire:click="previousPage">Newer</button>')->not->toContain('نئے نتائج');
});

it('keeps the search filters working with their choices named in Urdu', function () {
    librarySideAs($this, 'ur');
    $page = Livewire::test(Search::class)->set('filters.q', 'payment');
    expect($page->instance()->results()['items']->pluck('title')->all())->toContain('Payment terms for the March shipment');
    $page->set('filters.source_app', SourceApp::Zoom->value);
    expect($page->instance()->results()['items']->all())->toBe([]);
    $page->set('filters.source_app', SourceApp::WhatsApp->value);
    expect($page->instance()->results()['items']->pluck('title')->all())->toBe(['Payment terms for the March shipment']);
});

/* Recently deleted: its page, its line with the bin's hours, its columns and actions, its empty state. */
dataset('recently deleted states', [
    'with a recording' => [null, [
        'حال ہی میں حذف شدہ</h1>', 'Recently deleted</h1>',
        'حذف شدہ ریکارڈنگز یہاں 48 گھنٹے رہتی ہیں، پھر ان کی آڈیو اور ٹرانسکرپٹس ہمیشہ کے لیے مٹا دیے جاتے ہیں۔',
        'Deleted recordings stay here for 48 hours, then their audio and transcripts are erased for good.',
        '>عنوان<', '>Title<', '>ریکارڈ ہوئی<', '>Recorded<', '>حذف ہوئی<', '>Deleted<', '>مٹ جائے گی<', '>Erased<',
        // The day before the month in Urdu; "2 days from now" and its like in the console's language (Carbon's own words).
        '>14 نومبر 2026 · 1:00 صبح<', '>Nov 14, 2026 · 1:00 AM<',
        '>2 دن بعد<', '>2 days from now<',
        '>واپس لائیں<', '>Restore<', '>ابھی حذف کریں<', '>Delete now<',
    ]],
    'empty' => ['empty', [
        'پچھلے 48 گھنٹوں میں کچھ حذف نہیں ہوا', 'Nothing deleted in the last 48 hours',
    ]],
]);

it('words Recently deleted in Urdu in the new frame', function (?string $world, array $checks) {
    if ($world === 'empty') {
        librarySideEmptyBin();
    }
    librarySideAs($this, 'ur');
    $html = librarySideTight(librarySidePage($this, '/app/recently-deleted'));
    librarySideExpect($html, $checks, 'ur');
    expect($html)->toMatch('~<title>حال ہی میں حذف شدہ\s+-~u')->not->toMatch('~<title>Recently deleted\s+-~');
})->with('recently deleted states');

it('keeps Recently deleted in English exactly as it was', function (?string $world, array $checks) {
    if ($world === 'empty') {
        librarySideEmptyBin();
    }
    foreach ([true, false] as $v2) {
        librarySideAs($this, 'en', $v2);
        $html = librarySideTight(librarySidePage($this, '/app/recently-deleted'));
        librarySideExpect($html, $checks, 'en');
        expect($html)->toMatch('~<title>Recently deleted\s+-~');
    }
})->with('recently deleted states');

it('words the restore and delete-now actions, the window and their notices in Urdu, and keeps their English', function () {
    // The delete-now window (its label, heading and line), then Restore's notice, then Delete now's (on the recording
    // deleted again). Each notice is checked as soon as it is sent: Filament reads them once.
    $check = function (string $language, array $window, string $restored, string $erased): void {
        librarySideAs($this, $language);
        $record = CaptureSession::onlyTrashed()->firstOrFail();
        $erase = TestAction::make('eraseNow')->table($record);
        Livewire::test(RecentlyDeleted::class)->assertActionExists($erase, fn (Action $a): bool => [$a->getLabel(), $a->getModalHeading(), $a->getModalDescription()] === $window);
        Livewire::test(RecentlyDeleted::class)->callAction(TestAction::make('restore')->table($record))->assertNotified($restored);
        expect($record->refresh()->trashed())->toBeFalse();
        $record->delete();
        Livewire::test(RecentlyDeleted::class)->callAction($erase)->assertNotified($erased);
        expect(CaptureSession::onlyTrashed()->whereKey($record->getKey())->whereNull('media_erased_at')->exists())->toBeFalse();
    };

    $check('ur', ['ابھی حذف کریں', 'ابھی حذف کریں', 'آڈیو اور ٹرانسکرپٹ ابھی مٹا دیے جائیں گے۔ اسے واپس نہیں لایا جا سکتا۔'], 'ریکارڈنگ واپس آ گئی', 'ریکارڈنگ مٹا دی گئی');

    // Another recording in the bin (the first one is erased now), in English.
    CaptureSession::query()->where('title', 'Rate for 12 mm rebar')->firstOrFail()->delete();
    $check('en', ['Delete now', 'Delete now', 'The audio and transcript are erased now. This cannot be undone.'], 'Recording restored', 'Recording erased');
});

/* The new frame's avatar menu: Upgrade (or Plan and usage on the top plan), Settings, the theme, Admin panel, Sign out. */
it('words the new frame\'s avatar menu in Urdu, and keeps its English', function () {
    $menu = function (string $language, ?string $plan = null): string {
        if ($plan !== null) {
            Workspace::query()->whereKey($this->owner->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', $plan)->value('id'), 'plan_expires_at' => null]);
        }
        librarySideAs($this, $language, staff: true);
        $html = librarySideTight(librarySidePage($this, '/app/search'));
        $start = (int) strpos($html, 'fi-user-menu v2-um');

        return substr($html, $start, (int) strpos($html, '</form>', $start) - $start);
    };

    $ur = $menu('ur');
    foreach (['>اپ گریڈ کریں<', '>ترتیبات<', '>تھیم<', '>سسٹم<', '>لائٹ<', '>ڈارک<', '>ایڈمن پینل<', '>سائن آؤٹ<', '<span class="v2-chip">Personal</span>'] as $words) {
        expect($ur)->toContain($words);
    }
    expect($ur)->not->toContain('>Upgrade<')->not->toContain('>Admin panel<')->not->toContain('>Sign out<');

    $en = $menu('en');
    foreach (['>Upgrade<', '>Settings<', '>Theme<', '>System<', '>Light<', '>Dark<', '>Admin panel<', '>Sign out<', '>Personal<'] as $words) {
        expect($en)->toContain($words);
    }
    expect($en)->not->toContain('اپ گریڈ');

    expect($menu('ur', 'business'))->toContain('>پلان اور استعمال<')->not->toContain('>اپ گریڈ کریں<')
        ->and($menu('en', 'business'))->toContain('>Plan and usage<')->not->toContain('>Upgrade<');
});

/*
 * Classic's frame: its sidebar (Find a page and its "No page matches.", the groups, the badge's ", needs you:", the
 * foot) and its top bar with the avatar menu. Classic is always English on a page; drawn with the locale set by hand
 * they read in Urdu, and in English as on the page.
 */
it('words classic\'s sidebar in Urdu with the locale set, and keeps its English', function () {
    $sidebar = function (string $language): string {
        librarySideAs($this, $language, v2: false);

        return librarySideTight(librarySideRead(Livewire::test(Sidebar::class)->html()));
    };

    $ur = $sidebar('ur');
    foreach ([
        '>کوئی صفحہ نہیں ملا۔<', 'placeholder="صفحہ تلاش کریں"', 'aria-label="صفحات"', 'aria-label="مرکزی"', 'aria-label="EchoLedger ہوم"',
        'aria-label="مینو بند کریں"', '>ہوم<', '>لائبریری<', '>پروجیکٹس<', '>سرگرمی<', '>اپ لوڈ کریں<', 'aria-label="سمارٹ فولڈرز"',
        "'وائس نوٹس، آڈیو یا ویڈیو فائلیں۔ آپ انہیں لائبریری پر بھی چھوڑ سکتے ہیں۔'", '<span class="el-sr">، آپ کی توجہ:</span>',
        '>مدد اور شارٹ کٹس<', '>ترتیبات<', "rail() ? 'ترتیبات' : null", '>سائیڈ بار چھوٹا کریں<',
        "rail() ? 'سائیڈ بار بڑا کریں' : 'سائیڈ بار چھوٹا کریں'", "'سائیڈ بار بڑا کریں ([)'",
        'aria-label="سائیڈ بار کا سائز بدلیں"', 'title="سائز بدلنے کے لیے کھینچیں۔ معمول کی چوڑائی کے لیے دو بار کلک کریں۔"',
    ] as $words) {
        expect($ur)->toContain($words);
    }
    foreach (['>No page matches.<', 'Find a page', '>Library<', ', needs you:', '>Collapse sidebar<', "'Settings'"] as $english) {
        expect($ur)->not->toContain($english);
    }

    $en = $sidebar('en');
    foreach (['>No page matches.<', 'placeholder="Find a page"', 'aria-label="Pages"', 'aria-label="EchoLedger home"', '>Library<',
        '<span class="el-sr">, needs you:</span>', '>Help & shortcuts<', "rail() ? 'Settings' : null", '>Collapse sidebar<'] as $words) {
        expect($en)->toContain($words);
    }
    expect($en)->not->toContain('صفحہ');
});

it('words classic\'s top bar and avatar menu in Urdu with the locale set, and keeps their English', function () {
    $topbar = function (string $language, ?string $plan = null): string {
        if ($plan !== null) {
            Workspace::query()->whereKey($this->owner->current_workspace_id)->update(['plan_id' => Plan::query()->where('key', $plan)->value('id'), 'plan_expires_at' => null]);
        }
        librarySideAs($this, $language, v2: false, staff: true);

        return librarySideTight(librarySideRead(Livewire::test(Topbar::class)->html()));
    };

    $ur = $topbar('ur');
    foreach (['aria-label="اوپر کی پٹی"', 'aria-label="مینو کھولیں"', 'aria-label="Bilal Qureshi کا مینو کھولیں"',
        'aria-label="Bilal Qureshi، bilal@qureshi-textiles.example، Personal پلان۔ ترتیبات کھولتا ہے۔"', '<span class="el-tag">Personal</span>',
        '>اپ گریڈ کریں<', '>ترتیبات<', '>ظاہری شکل<', '>سسٹم<', '>لائٹ<', '>ڈارک<', '>ایڈمن پینل<', '>سائن آؤٹ<'] as $words) {
        expect($ur)->toContain($words);
    }
    foreach (['Top bar', '>Upgrade<', '>Appearance<', '>Admin panel<', '>Sign out<', 'Opens Settings.'] as $english) {
        expect($ur)->not->toContain($english);
    }

    $en = $topbar('en');
    foreach (['aria-label="Top bar"', 'aria-label="Open the menu for Bilal Qureshi"', 'aria-label="Bilal Qureshi, bilal@qureshi-textiles.example, Personal plan. Opens Settings."',
        '>Upgrade<', '>Settings<', '>Appearance<', '>System<', '>Admin panel<', '>Sign out<'] as $words) {
        expect($en)->toContain($words);
    }
    expect($en)->not->toContain('ظاہری');

    expect($topbar('ur', 'business'))->toContain('>پلان اور استعمال<')->not->toContain('>اپ گریڈ کریں<')
        ->and($topbar('en', 'business'))->toContain('>Plan & usage<')->not->toContain('>Upgrade<');
});

it('keeps classic in English on a page even when the person chose Urdu', function () {
    librarySideAs($this, 'ur', v2: false);
    $html = librarySideTight(librarySidePage($this, '/app/recently-deleted'));
    expect($html)->toContain('>No page matches.<')->toContain('>Restore<')->toContain('Recently deleted</h1>')
        ->not->toContain('کوئی صفحہ نہیں ملا')->not->toContain('واپس لائیں');
});

it('reads the short context words from lang/*/library_context.php, the general ones staying in ur.json', function () {
    $keys = ['search_title', 'search_from', 'search_to', 'search_newer', 'search_older', 'col_recorded', 'col_deleted', 'col_erased'];

    app()->setLocale('en');
    expect(array_map(fn (string $k): string => __('library_context.'.$k), $keys))
        ->toBe(['Search', 'From', 'To', 'Newer', 'Older', 'Recorded', 'Deleted', 'Erased'])
        ->and(__('From'))->toBe('From')->and(__('Search'))->toBe('Search');

    app()->setLocale('ur');
    expect(array_map(fn (string $k): string => __('library_context.'.$k), $keys))
        ->toBe(['تلاش', 'تاریخ سے', 'تاریخ تک', 'نئے نتائج', 'پرانے نتائج', 'ریکارڈ ہوئی', 'حذف ہوئی', 'مٹ جائے گی'])
        ->and(__('From'))->toBe('ماخذ')->and(__('Search'))->toBe('تلاش کریں');
});

it('shows an admin\'s own bookmark tags as typed and only the default ones in Urdu, so no tag can break the page', function () {
    // "validation" and "library_context" are lang group names: through __() they would come back as arrays.
    app(SettingsRepository::class)->set('markers.tags', ['Budget', 'validation', 'library_context', 'Site visit']);

    librarySideAs($this, 'ur');
    expect(librarySidePage($this, '/app/search'))->toContain('بجٹ')->toContain('validation')->toContain('library_context')->toContain('Site visit');
    librarySidePage($this, '/app/sessions');
    librarySideAs($this, 'ur', v2: false);
    librarySidePage($this, '/app/sessions');

    librarySideAs($this, 'en');
    expect(librarySidePage($this, '/app/search'))->toContain('Budget')->toContain('validation')->not->toContain('بجٹ');
});

it('shows the search\'s chosen days with the Urdu month in Urdu, and Filament\'s own format in English', function () {
    $format = function (string $language): array {
        librarySideAs($this, $language);
        $form = Livewire::test(Search::class)->instance()->form;

        return array_map(fn (string $name): string => collect($form->getFlatComponents())
            ->first(fn ($c): bool => method_exists($c, 'getName') && $c->getName() === $name)->getDisplayFormat(), ['from', 'to']);
    };

    expect($format('ur'))->toBe(['j F Y', 'j F Y'])
        ->and($format('en'))->each->not->toBe('j F Y');
});

it('names a matching screen "Screen n" in each language, the search API keeping its English', function () {
    $session = CaptureSession::query()->where('user_id', $this->owner->id)->orderBy('started_at')->firstOrFail();
    foreach ([1000, 2000] as $at) {
        Screen::query()->create(['workspace_id' => $session->workspace_id, 'capture_session_id' => $session->id, 'at_ms' => $at, 'width' => 1, 'height' => 1,
            'trigger' => 'manual', 'sha256' => hash('sha256', (string) $at), 'mime' => 'image/png', 'bytes' => 1, 'image_path' => "screens/{$at}.png", 'thumb_path' => "screens/{$at}-thumb.png",
            'ocr_text' => 'Quarterly kestrel invoice', 'search_text' => 'quarterly kestrel invoice']);
    }

    librarySideAs($this, 'ur');
    expect(librarySideTight(librarySidePage($this, '/app/search?q=kestrel')))->toContain('<strong>اسکرین 1:</strong>')->toContain('<strong>اسکرین 2:</strong>')
        ->not->toContain('<strong>Screen 1:</strong>');
    librarySideAs($this, 'en');
    expect(librarySidePage($this, '/app/search?q=kestrel'))->toContain('<strong>Screen 1:</strong>')->toContain('<strong>Screen 2:</strong>');
});

it('says the erase window in the future tense in Urdu', function () {
    app()->setLocale('ur');
    expect(__('The audio and transcript are erased now. This cannot be undone.'))->toBe('آڈیو اور ٹرانسکرپٹ ابھی مٹا دیے جائیں گے۔ اسے واپس نہیں لایا جا سکتا۔');
});

it('names a bookmark-tag filter in the Library by the default tag\'s Urdu, and an admin\'s own tag as typed (validation)', function () {
    app(SettingsRepository::class)->set('markers.tags', ['Budget', 'validation']);
    $pill = fn (): string => LibraryFilterBar::pills(['bookmarks' => ['op' => 'any', 'values' => ['budget', 'validation']]], ['sections' => []])[0]['text'];

    app()->setLocale('ur');
    expect($pill())->toBe('بجٹ, validation');
    app()->setLocale('en');
    expect($pill())->toBe('Budget, validation');
});
