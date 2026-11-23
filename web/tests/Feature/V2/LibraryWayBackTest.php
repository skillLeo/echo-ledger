<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Auth\Settings\ManagesAppPages;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Support\CommandPalette;
use App\Filament\Support\Shell;
use App\Models\LabelList;
use App\Services\InterfaceFlags;
use App\Services\Library\SavedViews;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * docs/PLAN_NAV_3_LEVELS.md section 0, the way back to all recordings: "All recordings" first in the Library and chosen
 * by default, nothing called the Inbox (a recording without a project is Project › No project), the sidebar's Library
 * and the breadcrumb "Library / <view>" back to All recordings with no filter, search or view, "Clear all" whenever a
 * filter is on, and Back and Forward (the address keeps the place, the filters and the view; ⌘[ / ⌘] in
 * resources/js/library-keys-core.cjs, tests/js).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $this->owner = Fx::user(['name' => 'Bilal Qureshi']);
    $this->device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $this->acme = Fx::client($this->owner, 'Acme');
    $make = function (string $title, SourceApp $app, array $state = [], array $labels = []) {
        $session = Fx::session($this->owner, $this->device, ['title' => $title, 'source_app' => $app, 'source_device' => SourceDevice::Phone, ...$state]);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'language' => 'ur', 'text' => "Words of {$title}"]);
        foreach ($labels as $label) {
            $session->labels()->create(['label' => $label]);
        }

        return $session;
    };
    $this->filed = $make('Payment terms', SourceApp::WhatsApp, ['client_id' => $this->acme->id, 'started_at' => now()->subHours(2)], ['Clients']);
    $this->loose = $make('Weekly sync', SourceApp::Zoom, ['started_at' => now()->subDays(3), 'important' => true]);
    $this->waiting = Fx::session($this->owner, $this->device, ['title' => 'Call to keep or discard', 'status' => SessionStatus::Recording, 'started_at' => now()->subMinutes(5)]);
    LabelList::query()->create(['workspace_id' => $this->owner->current_workspace_id, 'user_id' => $this->owner->id, 'label_key' => 'clients', 'name' => 'Clients', 'color' => '#e15f56', 'position' => 0]);
    $this->view = app(SavedViews::class)->create((int) $this->owner->current_workspace_id, $this->owner->id, ['name' => 'Zoom calls', 'filters' => ['source' => ['op' => 'is', 'values' => ['zoom']]]])['view']['id'];
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
    $this->library = CaptureSessionResource::getUrl('index');
    $this->page = function (string $query = ''): string {
        app('auth')->forgetGuards();

        return (string) $this->actingAs($this->owner->fresh(), 'web')->get($this->library.$query)->assertOk()->getContent();
    };
});

/** The href of the first element carrying [$attribute] (an attribute with no value, or attribute="value"). */
function wayBackHref(string $html, string $attribute): ?string
{
    return preg_match('~<a\s[^>]*?href="([^"]*)"[^>]*?'.preg_quote($attribute, '~').'~s', $html, $m) === 1 ? html_entity_decode($m[1]) : null;
}

it('lists All recordings first in the Library, chosen by default, and calls nothing the Inbox', function () {
    $html = ($this->page)();

    expect($html)->toMatch('~data-place="all" data-shortcut="1">.*?<span class="v2-sb-label">All recordings</span>~s')
        ->toMatch('~is-on"\s+aria-current="page"\s+data-place="all"~')
        ->toMatch('~<h1 class="v2-lib-title" data-library-title><bdi>All recordings</bdi></h1>~')
        ->and(strpos($html, 'data-place="all"'))->toBeLessThan(strpos($html, 'data-place="needs_you"'));
    // Nowhere in the new console: the Library as a list and as a table grouped by project (a recording without one is
    // "No project"), Shared with me, Request links and the command palette.
    expect($html)->not->toContain('Inbox');
    $table = Livewire::test(ListCaptureSessions::class)->call('setLibraryLayout', 'table')->call('setLibraryGroup', 'project')->html();
    expect($table)->not->toContain('Inbox')->toContain('No project');
    foreach ([SharedWithMe::getUrl(), RequestLinksPage::getUrl()] as $url) {
        app('auth')->forgetGuards();
        expect((string) $this->actingAs($this->owner->fresh(), 'web')->get($url)->assertOk()->getContent())->not->toContain('Inbox');
    }
    expect(collect(Shell::pages())->pluck('label')->all())->not->toContain('Inbox')->toContain('All recordings', 'Needs you', 'Unread')
        ->and(collect(Shell::pages())->pluck('id')->all())->not->toContain('place-waiting', 'place-folder:inbox')
        ->and((new CommandPalette)->getResults('inbox')->getCategories()->flatten(1)->map(fn ($r) => strip_tags((string) $r->title))->all())->not->toContain('Inbox');
});

it('goes back to All recordings from every view, through the sidebar\'s Library, All recordings and the breadcrumb', function () {
    $starts = [
        'a place' => '?tab=unread',
        'Needs you' => '?tab=needs_you',
        'a saved view' => '?v='.$this->view,
        'a label' => '?'.http_build_query(['f' => ['labels' => ['op' => 'is', 'values' => ['clients']]]]),
        'filters' => '?'.http_build_query(['f' => ['source' => ['op' => 'is', 'values' => ['zoom']], 'date' => ['op' => 'is', 'values' => ['today']]]]),
        'a search' => '?search=payment',
        'a place with filters' => '?'.http_build_query(['tab' => 'important', 'f' => ['source' => ['op' => 'is_not', 'values' => ['whatsapp']]]]),
    ];
    foreach ($starts as $start => $query) {
        $html = ($this->page)($query);
        foreach (['data-module="library"', 'data-place="all"', 'data-crumb-library'] as $way) {
            expect(wayBackHref($html, $way))->toBe($this->library, "{$start}: {$way}");
        }
        // Not chosen there: All recordings is chosen only with no filter, search or view.
        expect($html)->not->toMatch('~is-on"\s+aria-current="page"\s+data-place="all"~');
    }
    // What that address opens: All recordings, every recording but the call still waiting, no filter, search or view.
    Livewire::withQueryParams([])->test(ListCaptureSessions::class)
        ->assertSet('lf', [])->assertSet('savedView', null)->assertSet('tableSearch', '')->assertSet('activeTab', 'all')
        ->assertCanSeeTableRecords([$this->filed, $this->loose])->assertCanNotSeeTableRecords([$this->waiting]);
});

it('names the view in the breadcrumb: Library / Unread, a saved view, a label', function () {
    $heading = fn (string $query): string => (string) preg_replace('~^.*?<h1 class="v2-lib-title" data-library-title><bdi>(.*?)</bdi></h1>.*$~s', '$1', ($this->page)($query));

    expect($heading('?tab=unread'))->toBe('Unread')
        ->and($heading('?tab=needs_you'))->toBe('Needs you')
        ->and($heading('?v='.$this->view))->toBe('Zoom calls')
        ->and($heading('?'.http_build_query(['f' => ['labels' => ['op' => 'is', 'values' => ['clients']]]])))->toBe('Clients')
        ->and($heading('?'.http_build_query(['f' => ['source' => ['op' => 'is', 'values' => ['zoom']]]])))->toBe('All recordings');
    expect(($this->page)('?tab=unread'))->toMatch('~<nav aria-label="Breadcrumbs">\s*<a href="'.preg_quote($this->library, '~').'" class="v2-lib-crumb" data-crumb-library>Library</a>~');
});

it('offers Clear all whenever a filter is on, and Clear all keeps the place', function () {
    $sets = [
        'date' => ['op' => 'is', 'values' => ['today']],
        'source' => ['op' => 'is', 'values' => ['zoom']],
        'device' => ['op' => 'is_not', 'values' => ['desktop']],
        'project' => ['op' => 'is', 'values' => ['none']],
        'language' => ['op' => 'is', 'values' => ['ur']],
        'status' => ['op' => 'is', 'values' => ['transcribed']],
        'labels' => ['op' => 'is', 'values' => ['clients']],
        'marked' => ['op' => 'is', 'values' => ['important']],
        'bookmarks' => ['op' => 'is', 'values' => ['any']],
    ];
    expect(Livewire::test(ListCaptureSessions::class)->html())->not->toContain('data-clear-filters');
    foreach ($sets as $section => $filter) {
        expect(Livewire::test(ListCaptureSessions::class)->call('applyFilters', [$section => $filter])->html())->toContain('data-clear-filters', $section);
    }
    // A row's source mark and label dot filter the list in place: the row's link does not open the recording too.
    $rows = Livewire::test(ListCaptureSessions::class)->html();
    expect($rows)->toContain('x-on:click.prevent.stop="$wire.quickFilter(\'source\'')->toContain('x-on:click.prevent.stop="$wire.quickFilter(\'labels\'')
        ->not->toContain('x-on:click.stop="$wire.quickFilter(');
    // A saved view's filters show as pills too, with Clear all.
    expect(Livewire::withQueryParams(['v' => $this->view])->test(ListCaptureSessions::class)->html())->toContain('data-clear-filters');
    Livewire::withQueryParams(['tab' => 'important'])->test(ListCaptureSessions::class)->call('applyFilters', ['source' => ['op' => 'is', 'values' => ['whatsapp']]])
        ->assertCanNotSeeTableRecords([$this->loose])->call('clearFilters')->assertSet('lf', [])->assertSet('activeTab', 'important')
        ->assertCanSeeTableRecords([$this->loose])->assertCanNotSeeTableRecords([$this->filed]);
});

it('keeps Back and Forward: the place, the filters and the view in the address, and ⌘[ / ⌘] on every page', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    expect((fn (): array => $this->queryString())->call($page->instance()))->toMatchArray(['lf' => ['as' => 'f', 'history' => true], 'savedView' => ['as' => 'v', 'history' => true]]);
    // The places are links of their own, so each is a step of the browser's history.
    expect(($this->page)())->toContain('href="'.$this->library.'?tab=unread"');
    $script = (string) file_get_contents(resource_path('views/filament/v2/library-shell-script.blade.php'));
    expect($script)->toContain('core.historyKey(')->toContain('window.history.back()')->toContain('window.history.forward()')
        // In a form field or over a dialog the key is taken from the browser (whose own ⌘[ would leave the page).
        ->toContain("e.preventDefault();\n            if (way === 'stay') return;");
    expect(collect(ManagesAppPages::shortcuts()['Everywhere'])->pluck(1)->all())->toContain('Back and forward');
});
