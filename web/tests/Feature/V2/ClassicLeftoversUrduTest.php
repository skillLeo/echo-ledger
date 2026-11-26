<?php

declare(strict_types=1);

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Support\SettingsContext;
use App\Models\Client;
use App\Models\Plan;
use App\Models\UsageLedgerEntry;
use App\Models\Workspace;
use App\Services\InterfaceFlags;
use App\Stt\LanguageChoice;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * W-212: the last English on classic pages inside the new frame in Urdu: the recording rows' dates and "app · device"
 * line, the sidebar column's Projects words, the Projects list's column, sort and filter words, the frame's help list,
 * offline notice and search labels, the Usage month picker and validation messages. In Urdu they are Urdu (dates as
 * "17 نومبر" with the digits 0–9); in English and in classic nothing changes.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
    // W-213: a new project's page (Filament's create words, the colour picker, the folding section).
    $this->pages['project-create'] = '/app/clients/create';
});

/** A page in the new frame (or classic) in a language; `$library` false keeps the classic Library inside the frame. */
function leftoverPage(object $test, bool $v2, string $language, string $page, bool $library = true): string
{
    webParityDesign($v2);
    if ($v2 && ! $library) {
        app(InterfaceFlags::class)->set('ui_v2.library', false, 0, [], [], 'off');
    }
    $prefs = is_array($test->owner->console_preferences) ? $test->owner->console_preferences : [];
    $prefs['shell'] = ['app_language' => $language];
    $test->owner->forceFill(['console_preferences' => $prefs])->save();
    app('auth')->forgetGuards();
    SettingsContext::forget();

    return (string) $test->actingAs($test->owner->fresh(), 'web')->get($test->pages[$page])->assertOk()->getContent();
}

/**
 * An attribute's text or a rendered view without the invisible direction marks around names and numbers (B8), for
 * what leftoverWords() does not read: attribute values and views rendered on their own.
 */
function leftoverBare(string $text): string
{
    return preg_replace('/[\x{2066}-\x{2069}]/u', '', $text) ?? $text;
}

/** What the page says, without its markup and without the invisible direction marks around names and numbers (B8). */
function leftoverWords(string $html): string
{
    $words = preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? ''))) ?? '';

    return preg_replace('/[\x{2066}-\x{2069}]/u', '', $words) ?? $words;
}

/** Whether the English is on the page: in its words, or in an attribute for an `=`. */
function leftoverHas(string $html, string $english): bool
{
    return str_starts_with($english, '=')
        ? str_contains(html_entity_decode($html), $english)
        : str_contains(leftoverWords($html), $english);
}

/** The frame's own words, on every page. */
const LEFTOVER_FRAME = ['Help & shortcuts', 'Search recordings, transcripts, pages and settings, or type a command', 'Search the list on this page',
    'Fold the sidebar to icons, and back', 'Close a panel, a menu or this list', "You're offline. Changes are saved once the connection is back.",
    'Press Down arrow to browse results', '="Global search results"',
    // W-213: the skip link and the bell's name.
    'Skip to content', '="Notifications, 1 needs you"'];

/**
 * With the three levels (the new frame and the new Library) Projects is an overview of cards: each says its last
 * activity, and the project's language is in its Settings, not on the card (docs/PLAN_NAV_3_LEVELS.md section 3).
 */
const LEFTOVER_FRAMED = ['projects' => ['Automatic' => null, 'Last call Nov 17' => 'Last activity Nov 17'],
    // UX-18: the plan's page is Plan & billing in the new console, as the account menu says.
    'settings' => ['Plan & usage' => 'Plan & billing']];

/** A page's English in this world, with the three levels' own words where they differ. */
function leftoverPhrases(string $page, array $phrases, bool $framed): array
{
    if (! $framed) {
        return $phrases;
    }
    $swap = LEFTOVER_FRAMED[$page] ?? [];

    return array_values(array_filter(array_map(fn (string $english): ?string => array_key_exists($english, $swap) ? $swap[$english] : $english, $phrases)));
}

/** Per page (and whether the new Library is on): the English it shows in this world. */
const LEFTOVER_ENGLISH = [
    'library' => [false, ['Nov 16', '="Nov 15, 2026 · 5:00 PM"', 'Phone call · Galaxy S25', 'Voice note · Galaxy S25', '="Nov 17, 2026 · 9:00 AM"',
        '="Phone call · Galaxy S25"', 'Drag recordings onto the Inbox or a project to move them.', '="Library places and projects"',
        // W-213: the classic Library's day headings, places, menus, filters, rows and upload panel.
        'Sunday, Nov 15', 'Saturday, Nov 14', '="Date: Today"', '="Date: Yesterday"', 'This week', 'Voice notes', 'Shared with me',
        'All recordings', 'All folders', 'Group by', 'Sort by', 'App / Type', 'Recently opened', 'Name A–Z', '="Library view"',
        'Source device', 'Source app', 'Bookmark tag', 'Has bookmarks', 'Until', '="Filters (0 active)"',
        'Add label', 'Remove label', 'Stop keeping', 'Keep forever', 'Pin to the top', 'Send to WhatsApp…', 'No audio', '="Uploaded: done',
        'Failed uploads (1)', 'See why', '="Dismiss"', '="With the selected recordings"', '="recordings"', 'Library - EchoLedger',
        'Drop sound or video files here, or', 'choose files', 'each file becomes its own recording, titled from its name', 'Clear the list',
        'Auto (English / Urdu / Arabic)', 'Urdu (with English words)', 'Portuguese', '="More languages"', '="Files"']],
    // (A brand name such as Zoom stays Latin in every language: LEAD DECISION 26.)
    'project-recordings' => [true, ['Nov 15', '9:00 AM', '="Nov 15, 2026 · 5:00 PM"', 'Last call Nov 17',
        '="Projects"', '="New project"']],
    'projects' => [true, ['Name', 'Ascending', 'Descending', 'Automatic', 'Pin to the top', 'Loading...', '1 result', 'Last call Nov 17']],
    'usage' => [true, ['November 2026', 'October 2026', 'Nov 2026']],
    // W-213: Activity's headings and dates, a new project's form.
    'activity' => [true, ['Mark all as read', 'Needs action', 'Unread:', '="Nov 17, 2026 · 11:42 AM"']],
    'project-create' => [true, ['Create & create another', '="Color picker"', '="Collapse section"', '="Breadcrumb"', 'Urdu (with English words)', 'Portuguese']],
    // Settings' home: its heading, the profile card's Upgrade, and This month (each meter, its amount and Plan & usage).
    'settings' => [true, ['Settings', 'Upgrade', 'This month', 'Transcription', 'Resets 1 Dec', 'Storage', 'Devices', 'Plan & usage', ' min']],
];

it('shows the classic pages\' last English in Urdu in the new frame, with Urdu month names and the digits 0-9', function () {
    foreach (LEFTOVER_ENGLISH as $page => [$library, $phrases]) {
        $html = leftoverPage($this, true, 'ur', $page, $library);
        // (None of it in English: what the classic page said, and the three levels' own words.)
        foreach (array_unique([...$phrases, ...leftoverPhrases($page, $phrases, $library), ...LEFTOVER_FRAME]) as $english) {
            expect(leftoverHas($html, $english))->toBeFalse("{$page}: \"{$english}\" is still in English");
        }
    }
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'library', false)))->toContain('16 نومبر')->toContain('فون کال · Galaxy S25');
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'projects')))->toContain('آخری سرگرمی 17 نومبر')->not->toMatch('/[٠-٩۰-۹]/u');
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'project-recordings')))->toContain('پروجیکٹس')->toContain('نیا پروجیکٹ');
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'usage')))->toContain('نومبر 2026');
    // W-213: the classic Library's days as "اتوار، 15 نومبر", Activity's as "17 نومبر 2026", a new project's words.
    $library = leftoverPage($this, true, 'ur', 'library', false);
    expect(leftoverWords($library))->toContain('اتوار، 15 نومبر')->toContain('ہفتہ، 14 نومبر')->toContain('تاریخ: آج')
        ->toContain('فائلیں یہاں چھوڑیں')->not->toMatch('/[٠-٩۰-۹]/u');
    expect(leftoverBare(html_entity_decode($library)))->toContain('="اطلاعات، 1 آپ کی منتظر ہے"');
    $activity = leftoverBare(html_entity_decode(leftoverPage($this, true, 'ur', 'activity')));
    expect($activity)->toContain('="17 نومبر 2026 · ')->toContain('سب کو پڑھی ہوئی کریں')->not->toContain('نشان زد');
    // Settings' home: its words in Urdu, the minutes in Urdu units and every amount in its own order (<bdi>).
    $settings = leftoverPage($this, true, 'ur', 'settings');
    expect(leftoverWords($settings))->toContain('ترتیبات')->toContain('اپ گریڈ کریں')->toContain('اس مہینے')->toContain('ٹرانسکرپشن')
        ->toContain('1 دسمبر کو دوبارہ شروع ہوگا')->toContain('اسٹوریج')->toContain('ڈیوائسز')->toContain('پلان اور بلنگ')->toMatch('/\d+ منٹ/u')
        ->not->toMatch('/[٠-٩۰-۹]/u');
    expect(substr_count($settings, '<span class="el-set-value" style="max-width:60%"><bdi>'))->toBe(3);
    $create = leftoverWords(leftoverPage($this, true, 'ur', 'project-create'));
    expect($create)->toContain('پروجیکٹ بنائیں')->toContain('بنائیں اور ایک اور بنائیں')->toContain('منسوخ کریں')->toContain('اردو (انگریزی الفاظ کے ساتھ)')
        ->not->toContain('Create')->not->toContain('Cancel');
});

it('keeps the same words in English in the new frame and in classic', function () {
    foreach ([true, false] as $v2) {
        foreach (LEFTOVER_ENGLISH as $page => [$library, $phrases]) {
            $html = leftoverPage($this, $v2, 'en', $page, $library);
            // With the three levels (the new frame and the new Library) the bell is the rail's Activity
            // (docs/PLAN_NAV_3_LEVELS.md section 2: never the same thing at two levels), so the top bar has none.
            // Help & shortcuts (Keyboard shortcuts in the new console) lists the frame's own keys there in place of the
            // sidebar's [ line.
            $frame = $v2 && $library ? ['="Notifications, 1 needs you"', 'Fold the sidebar to icons, and back'] : [];
            // UX-18: in the new console the shortcuts' dialog is Keyboard shortcuts (Help is the Help page).
            $shown = $v2 ? array_map(fn (string $p): string => $p === 'Help & shortcuts' ? 'Keyboard shortcuts' : $p, LEFTOVER_FRAME) : LEFTOVER_FRAME;
            foreach ([...leftoverPhrases($page, $phrases, $v2 && $library), ...array_diff($shown, $frame)] as $english) {
                expect(leftoverHas($html, $english))->toBeTrue(($v2 ? 'v2' : 'classic')." {$page}: \"{$english}\" is missing");
            }
        }
    }
});

/** The usage meters of a Settings page (This month, Plan & usage), each one's markup. */
function leftoverMeters(string $html): string
{
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
    $out = '';
    foreach ((new DOMXPath($dom))->query('//div[contains(concat(" ", normalize-space(@class), " "), " el-meter ")]') ?: [] as $meter) {
        $out .= $dom->saveHTML($meter);
    }

    return $out;
}

/** Signed in, in the new frame, in Urdu, for a Livewire page tested on its own (no middleware sets the language). */
function leftoverUrdu(object $test): void
{
    webParityDesign(true);
    $test->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
    $test->actingAs($test->owner->fresh(), 'web');
    Filament::setCurrentPanel('app');
    App::setLocale('ur');
}

it('says a project form\'s errors in Urdu', function () {
    leftoverUrdu($this);
    $create = Livewire::test(CreateClient::class)->fillForm(['name' => ''])->call('create')->assertHasFormErrors(['name' => 'required']);
    expect($create->errors()->first('data.name'))->toBe('نام لازمی ہے۔');

    $page = Livewire::test(ViewClient::class, ['record' => Client::query()->firstOrFail()->getRouteKey()])
        ->set('ruleValue', '')->call('addRule')->assertHasErrors(['ruleValue' => 'required']);
    expect($page->errors()->first('ruleValue'))->toBe('قدر لازمی ہے۔');
});

it('keeps a project form\'s errors in English in English', function () {
    $this->actingAs($this->owner, 'web');
    Filament::setCurrentPanel('app');
    $create = Livewire::test(CreateClient::class)->fillForm(['name' => ''])->call('create')->assertHasFormErrors(['name' => 'required']);
    expect($create->errors()->first('data.name'))->toBe('The name field is required.');

    $page = Livewire::test(ViewClient::class, ['record' => Client::query()->firstOrFail()->getRouteKey()])
        ->set('ruleValue', '')->call('addRule')->assertHasErrors(['ruleValue' => 'required']);
    expect($page->errors()->first('ruleValue'))->toBe('The value field is required.');
});

// W-212 visual: the Projects page's title was "پروجیکٹs" (an English "s" on the translated singular).
it('titles the Projects page with its own Urdu plural, and keeps "Projects" in English', function () {
    $urdu = leftoverWords(leftoverPage($this, true, 'ur', 'projects'));
    expect($urdu)->toContain('پروجیکٹس')->not->toContain('پروجیکٹs');
    expect(leftoverWords(leftoverPage($this, true, 'en', 'projects')))->toContain('Projects');
});

// W-213: the bell's name says how many need the person, one or more, in the console's language.
it('names the bell with its count, one or more, in Urdu and in English', function () {
    Filament::setCurrentPanel('app');
    $bell = fn (int $count): string => leftoverBare(html_entity_decode(view('filament.activity-bell-trigger', ['badge' => (string) $count, 'count' => $count])->render()));

    expect($bell(1))->toContain('="Notifications, 1 needs you"')
        ->and($bell(3))->toContain('="Notifications, 3 need you"')
        ->and($bell(0))->toContain('="Notifications"');
    App::setLocale('ur');
    expect($bell(1))->toContain('="اطلاعات، 1 آپ کی منتظر ہے"')
        ->and($bell(3))->toContain('="اطلاعات، 3 آپ کی منتظر ہیں"')
        ->and($bell(0))->toContain('="اطلاعات"');
    // The bell's panel says it as the Activity page, the phone and v2 do (Filament's own Urdu: نوٹیفکیشنز, نشان لگائیں).
    expect(__('filament-notifications::database.modal.heading'))->toBe('اطلاعات')
        ->and(__('filament-notifications::database.modal.actions.mark_all_as_read.label'))->toBe(__('Mark all as read'))->toBe('سب کو پڑھی ہوئی کریں');
});

// W-213: the languages' names are shown in Urdu; the codes kept are the same.
it('shows the offered languages in Urdu and keeps their codes', function () {
    expect(LanguageChoice::offered())->toBe(LanguageChoice::OFFERED);
    App::setLocale('ur');
    expect(array_keys(LanguageChoice::offered()))->toBe(array_keys(LanguageChoice::OFFERED))
        ->and(LanguageChoice::offered()['ur'])->toBe('اردو (انگریزی الفاظ کے ساتھ)')
        ->and(LanguageChoice::offered()['fr'])->toBe('فرانسیسی')
        ->and(array_keys(LanguageChoice::selectOptions()))->toBe(['', 'en', 'ur', 'ar', 'مزید زبانیں'])
        ->and(array_keys(LanguageChoice::selectOptions()['مزید زبانیں']))->toBe(array_keys(LanguageChoice::MORE));
});

// W-213 visual: Transcribe as a stage beside Upload and Improve is ٹرانسکرپشن; as a button it is ٹرانسکرائب کریں (v2).
it('names the Transcribe stage apart from the Transcribe button in Urdu, and keeps "Transcribe" in English', function () {
    $progress = ['active' => 'upload', 'summary' => 'Uploading 40%', 'steps' => [
        ['key' => 'upload', 'label' => 'Uploading', 'state' => 'active', 'detail' => '40%', 'percent' => 40],
        ['key' => 'transcribe', 'label' => 'Transcribe', 'state' => 'waiting', 'detail' => null, 'percent' => null],
        ['key' => 'improve', 'label' => 'Improve', 'state' => 'waiting', 'detail' => null, 'percent' => null],
    ]];
    $steps = fn (bool $compact): string => leftoverBare(html_entity_decode(view('filament.library.progress', ['progress' => $progress, 'compact' => $compact])->render()));

    expect($steps(true))->toContain('Transcribe: waiting')->and($steps(false))->toContain('>Transcribe<');
    App::setLocale('ur');
    expect($steps(true))->toContain('ٹرانسکرپشن: انتظار میں')->not->toContain('Transcribe')
        ->and($steps(false))->toContain('>ٹرانسکرپشن<')
        ->and(__('Transcribe'))->toBe('ٹرانسکرائب کریں');
});

// W-217: the Upload page's title, intro and the "Record a voice note" box were still English in the Urdu frame.
it('shows the Upload page\'s intro and record box in Urdu, and keeps them in English', function () {
    $english = ['Sound or video files from your computer or phone', 'Record a voice note', "Your phone's recorder opens",
        'To record calls and voice notes, use the', 'get the apps', 'Here you can upload files.'];
    $urdu = leftoverWords(leftoverPage($this, true, 'ur', 'upload'));
    foreach ($english as $phrase) {
        expect($urdu)->not->toContain($phrase);
    }
    $words = leftoverWords(leftoverPage($this, true, 'en', 'upload'));
    foreach ($english as $phrase) {
        expect($words)->toContain($phrase);
    }
});

it('says a limited plan\'s meters in Urdu: amounts of their limits, the near, paused and full lines, Upgrade and the labels', function () {
    $workspace = (int) $this->owner->current_workspace_id;
    $plan = fn (string $key) => Workspace::query()->whereKey($workspace)->update(['plan_id' => Plan::query()->where('key', $key)->value('id'),
        'plan_expires_at' => $key === 'free' ? null : now()->addDays(30)]);
    $minutes = function (int $used) use ($workspace): void {
        UsageLedgerEntry::query()->where('report_id', 'like', 'leftover-%')->delete();
        UsageLedgerEntry::query()->create(['workspace_id' => $workspace, 'user_id' => $this->owner->id, 'source' => 'server', 'report_id' => 'leftover-'.$used,
            'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => $used * 60000, 'channels' => 1, 'billed_ms' => $used * 60000,
            'unit_price_usd' => 0, 'cost_usd' => 0, 'billable' => true, 'occurred_at' => now()]);
    };
    // Free: the storage full (2 GB stored on a 1 GB plan), so its line shows; the devices of their limit.
    $plan('free');
    $full = Fx::readyRecording(Fx::session($this->owner), 'full');
    $full->forceFill(['stored_size_bytes' => 2 * 1024 ** 3])->save();
    $english = ['Your Groq key', 'Not added.', 'Add key', ' of 1 GB', ' of 2', "New recordings can't upload", '="Storage, ', '="Devices, ', '="Transcription, '];
    foreach (['settings', 'settings-plan'] as $page) {
        $html = leftoverPage($this, true, 'ur', $page);
        foreach ($english as $phrase) {
            expect(leftoverHas(leftoverMeters($html), $phrase))->toBeFalse("Free {$page}: \"{$phrase}\" is still in English");
        }
        expect(leftoverMeters($html))->not->toBe('');
        expect(leftoverWords(leftoverMeters($html)))->toContain('1 GB میں سے')->toContain('نئی ریکارڈنگز اپ لوڈ نہیں ہو سکتیں')->toContain('آپ کی Groq کلید');
        expect(leftoverBare(html_entity_decode(leftoverMeters($html))))->toContain('aria-label="اسٹوریج، ')->toContain('aria-label="ڈیوائسز، ');
        // In English nothing changes.
        $en = leftoverMeters(leftoverPage($this, true, 'en', $page));
        foreach (['Your Groq key', ' of 1 GB', "New recordings can't upload until you free up space or upgrade.", '="Storage, ', '="Devices, '] as $phrase) {
            expect(leftoverHas($en, $phrase))->toBeTrue("Free {$page}: \"{$phrase}\" is missing in English");
        }
    }
    // Pro near its minutes (500 of 600): the near line, Upgrade and its trailing words; at 600 the paused line.
    $plan('pro');
    $minutes(500);
    $near = leftoverMeters(leftoverPage($this, true, 'ur', 'settings-plan'));
    foreach (['of 600 min', "You've used", 'or wait until', 'Upgrade', '="Transcription, '] as $phrase) {
        expect(leftoverHas($near, $phrase))->toBeFalse("Pro near: \"{$phrase}\" is still in English");
    }
    expect(leftoverWords($near))->toContain('600 میں سے 500 منٹ')->toContain('آپ اپنے 83% منٹ استعمال کر چکے ہیں۔')->toContain('اپ گریڈ کریں یا 1 دسمبر تک انتظار کریں۔')
        ->not->toMatch('/[٠-٩۰-۹]/u');
    expect(leftoverWords(leftoverPage($this, true, 'en', 'settings-plan')))->toContain('500 of 600 min')->toContain("You've used 83% of your minutes.")->toContain('Upgrade or wait until 1 Dec.');
    $minutes(600);
    $paused = leftoverMeters(leftoverPage($this, true, 'ur', 'settings-plan'));
    expect(leftoverHas($paused, 'Transcription is paused until'))->toBeFalse()
        ->and(leftoverWords($paused))->toContain('1 دسمبر تک ٹرانسکرپشن رکی ہوئی ہے۔');
    expect(leftoverWords(leftoverPage($this, true, 'en', 'settings-plan')))->toContain('Transcription is paused until 1 Dec.');
});
