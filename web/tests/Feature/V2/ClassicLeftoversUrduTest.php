<?php

declare(strict_types=1);

use App\Filament\Resources\Clients\Pages\CreateClient;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Support\SettingsContext;
use App\Models\Client;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;

/*
 * W-212: the last English on classic pages inside the new frame in Urdu: the recording rows' dates and "app · device"
 * line, the sidebar column's Projects words, the Projects list's column, sort and filter words, the frame's help list,
 * offline notice and search labels, the Usage month picker and validation messages. In Urdu they are Urdu (dates as
 * "17 نومبر" with the digits 0–9); in English and in classic nothing changes.
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
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

/** What the page says, without its markup. */
function leftoverWords(string $html): string
{
    return preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('#<(script|style)\b.*?</\1>#is', '', $html) ?? ''))) ?? '';
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
    'Press Down arrow to browse results', '="Global search results"'];

/** Per page (and whether the new Library is on): the English it shows in this world. */
const LEFTOVER_ENGLISH = [
    'library' => [false, ['Nov 16', '="Nov 15, 2026 · 5:00 PM"', 'Phone call · Galaxy S25', 'Voice note · Galaxy S25', '="Nov 17, 2026 · 9:00 AM"',
        '="Phone call · Galaxy S25"', 'Drag recordings onto the Inbox or a project to move them.', '="Library places and projects"']],
    'project-recordings' => [true, ['Nov 15', '9:00 AM', 'Zoom · Galaxy S25', '="Nov 15, 2026 · 5:00 PM"', 'Last call Nov 17',
        '="Projects"', '="New project"']],
    'projects' => [true, ['Name', 'Ascending', 'Descending', 'Automatic', 'Pin to the top', 'Loading...', '1 result', 'Last call Nov 17']],
    'usage' => [true, ['November 2026', 'October 2026', 'Nov 2026']],
];

it('shows the classic pages\' last English in Urdu in the new frame, with Urdu month names and the digits 0-9', function () {
    foreach (LEFTOVER_ENGLISH as $page => [$library, $phrases]) {
        $html = leftoverPage($this, true, 'ur', $page, $library);
        foreach ([...$phrases, ...LEFTOVER_FRAME] as $english) {
            expect(leftoverHas($html, $english))->toBeFalse("{$page}: \"{$english}\" is still in English");
        }
    }
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'library', false)))->toContain('16 نومبر')->toContain('فون کال · Galaxy S25');
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'projects')))->toContain('آخری کال 17 نومبر')->not->toMatch('/[٠-٩۰-۹]/u');
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'project-recordings')))->toContain('پروجیکٹس')->toContain('نیا پروجیکٹ');
    expect(leftoverWords(leftoverPage($this, true, 'ur', 'usage')))->toContain('نومبر 2026');
});

it('keeps the same words in English in the new frame and in classic', function () {
    foreach ([true, false] as $v2) {
        foreach (LEFTOVER_ENGLISH as $page => [$library, $phrases]) {
            $html = leftoverPage($this, $v2, 'en', $page, $library);
            foreach ([...$phrases, ...LEFTOVER_FRAME] as $english) {
                expect(leftoverHas($html, $english))->toBeTrue(($v2 ? 'v2' : 'classic')." {$page}: \"{$english}\" is missing");
            }
        }
    }
});

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
