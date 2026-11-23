<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Livewire\Shell\Sidebar;
use App\Models\LabelList;
use App\Models\SavedView;
use App\Services\InterfaceFlags;
use App\Services\Library\SavedViews;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The web Library's filters and views (contract 2.0.0-p, docs/PLAN_LIBRARY_FILTERS.md; ui_v2.library): one toolbar row
 * (the view's name, Search, [Filters n], Sort & display), the filter panel with live counts, is / is not and Save as
 * view, the pills while filters are on, the filters in the address, the sidebar's Library places, My views and labels,
 * ● Record ▾, and "Needs you". The keys are decided in resources/js/library-keys-core.cjs (tests/js).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $this->owner = Fx::user(['name' => 'Bilal Qureshi']);
    $this->device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $this->acme = Fx::client($this->owner, 'Acme');
    $make = function (string $title, SourceApp $app, array $state = [], ?TranscriptStatus $final = TranscriptStatus::Completed, array $labels = []) {
        $session = Fx::session($this->owner, $this->device, ['title' => $title, 'source_app' => $app, 'source_device' => SourceDevice::Phone, ...$state]);
        if ($final !== null) {
            $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => $final, 'language' => 'ur', 'text' => "Words of {$title}"]);
        }
        foreach ($labels as $label) {
            $session->labels()->create(['label' => $label]);
        }

        return $session;
    };
    $this->whatsapp = $make('Payment terms', SourceApp::WhatsApp, ['client_id' => $this->acme->id, 'started_at' => now()->subHours(2)], labels: ['Clients']);
    $this->zoom = $make('Weekly sync', SourceApp::Zoom, ['source_device' => SourceDevice::Desktop, 'started_at' => now()->subDays(3), 'important' => true]);
    $this->old = $make('Old rate call', SourceApp::PhoneCall, ['started_at' => now()->subDays(40)]);
    $this->failed = $make('Transcript that failed', SourceApp::WhatsApp, ['started_at' => now()->subDays(1)], TranscriptStatus::Failed);
    $this->waiting = Fx::session($this->owner, $this->device, ['title' => 'Call to keep or discard', 'status' => SessionStatus::Recording, 'started_at' => now()->subMinutes(5)]);
    LabelList::query()->create(['workspace_id' => $this->owner->current_workspace_id, 'user_id' => $this->owner->id, 'label_key' => 'clients', 'name' => 'Clients', 'color' => '#e15f56', 'position' => 0]);
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

function lfIs(string $section, array $values, string $op = 'is'): array
{
    return [$section => ['op' => $op, 'values' => $values]];
}

describe('one toolbar row', function () {
    it('leads with the view\'s name, then Search, [Filters n] and Sort & display; no header, no Views chips', function () {
        $html = Livewire::test(ListCaptureSessions::class)->html();

        expect($html)->toContain('data-library-title')->toContain('data-filters-button')->toContain('data-menu="display"')
            ->not->toContain('data-menu="views"')->not->toContain('data-view="today"')->not->toContain('data-waiting=')
            ->not->toContain('fi-header-heading');
        // Sort & display holds List / Table, the six orders, the seven groups and the two densities.
        foreach (['data-layout="list"', 'data-layout="table"', 'data-option="sort-oldest"', 'data-option="sort-opened"', 'data-option="group-project"', 'data-option="group-none"', 'data-option="density-compact"'] as $option) {
            expect($html)->toContain($option);
        }
        Livewire::test(ListCaptureSessions::class)->assertActionDoesNotExist('upload')->assertActionDoesNotExist('voiceNote');
    });

    it('searches titles and transcripts', function () {
        Livewire::test(ListCaptureSessions::class)->set('tableSearch', 'Words of Weekly')
            ->assertCanSeeTableRecords([$this->zoom])->assertCanNotSeeTableRecords([$this->whatsapp, $this->old]);
        Livewire::test(ListCaptureSessions::class)->set('tableSearch', 'payment')
            ->assertCanSeeTableRecords([$this->whatsapp])->assertCanNotSeeTableRecords([$this->zoom]);
    });
});

describe('the filter panel', function () {
    it('is closed by default, 280 wide beside the list, a drawer under 1280, and lists every section with live counts', function () {
        $page = Livewire::test(ListCaptureSessions::class);
        $html = $page->html();
        expect($html)->toContain('x-show="filtersOpen"')->toContain('data-filter-panel')->toContain("x-bind:class=\"{ 'is-drawer': drawer }\"");
        // Narrower than the design token --el-breakpoint-panel (1280) the panel is a drawer over the list.
        expect((string) file_get_contents(resource_path('css/tokens.css')))->toContain('--el-breakpoint-panel: 1280px;')
            ->and((string) file_get_contents(resource_path('views/filament/v2/library-shell-script.blade.php')))->toContain("getPropertyValue('--el-breakpoint-panel')");
        $sections = collect($page->instance()->filterPanel())->keyBy('key');
        expect($sections->keys()->all())->toBe(['date', 'source', 'device', 'project', 'language', 'status', 'labels', 'marked', 'bookmarks'])
            ->and(collect($sections['source']['options'])->pluck('count', 'value')->all())->toBe(['whatsapp' => 2, 'zoom' => 1, 'phone_call' => 1])
            ->and(collect($sections['status']['options'])->pluck('count', 'value')->all())->toBe(['transcribed' => 3, 'not_transcribed' => 0, 'processing' => 0, 'failed' => 1]);
        $css = (string) file_get_contents(resource_path('css/v2/console.css'));
        expect($css)->toContain('inline-size: calc(var(--el-space-40) * 7);')->toContain('.v2-lib-shell.is-drawer .v2-lib-panel');
        // Custom dates: the two days show as soon as Custom is ticked, and nothing is sent until a day is set.
        expect($html)->toContain('x-show="custom"')->toContain('data-from')->toContain('data-to')->toContain('return waiting ? null : out;');
    });

    it('narrows the list as the panel sends its filters, counts each section without its own filter, and takes is not', function () {
        $page = Livewire::test(ListCaptureSessions::class)->call('applyFilters', [...lfIs('source', ['whatsapp']), ...lfIs('date', ['last_7_days'])]);
        $page->assertCanSeeTableRecords([$this->whatsapp, $this->failed])->assertCanNotSeeTableRecords([$this->zoom, $this->old, $this->waiting]);
        $sections = collect($page->instance()->filterPanel())->keyBy('key');
        // Source counts within the last 7 days, whatever source is ticked; Date counts the WhatsApp calls.
        expect(collect($sections['source']['options'])->pluck('count', 'value')->all())->toBe(['whatsapp' => 2, 'zoom' => 1, 'phone_call' => 0])
            ->and(collect($sections['date']['options'])->pluck('count', 'value')->all())->toBe(['any' => null, 'today' => 1, 'last_7_days' => 2, 'this_month' => 2, 'custom' => null]);

        $page->call('setFilterOp', 'source', 'is_not')->assertCanSeeTableRecords([$this->zoom])->assertCanNotSeeTableRecords([$this->whatsapp, $this->old]);
        expect($page->get('lf'))->toBe([...lfIs('date', ['last_7_days']), ...lfIs('source', ['whatsapp'], 'is_not')]);
    });

    it('says when nothing matches, with Clear filters', function () {
        $page = Livewire::test(ListCaptureSessions::class)->call('applyFilters', lfIs('source', ['discord']));
        expect($page->html())->toContain('No recordings match these filters');
        $page->callTableAction('clearSearchAndFilters')->assertSet('lf', [])->assertCanSeeTableRecords([$this->whatsapp, $this->zoom]);
    });
});

describe('the pills', function () {
    it('show only while filters are on, edit is / is not, take a value off, and clear all', function () {
        $page = Livewire::test(ListCaptureSessions::class);
        expect($page->html())->not->toContain('data-pills');

        $page->call('applyFilters', [...lfIs('source', ['whatsapp', 'zoom']), ...lfIs('labels', ['clients'])]);
        $html = $page->html();
        expect($html)->toContain('data-pills')->toContain('data-pill="source"')->toContain('WhatsApp, Zoom')->toContain('data-pill="labels"')
            ->toContain('data-clear-filters')->toContain('data-save-view')
            ->and(collect($page->instance()->filterPills())->pluck('text', 'section')->all())->toBe(['source' => 'WhatsApp, Zoom', 'labels' => 'Clients']);

        $page->call('setFilterOp', 'labels', 'is_not');
        expect($page->html())->toContain('Labels is not:');
        $page->call('toggleFilterValue', 'source', 'zoom');
        expect($page->get('lf.source.values'))->toBe(['whatsapp']);
        $page->call('removeFilter', 'labels');
        expect(array_keys($page->get('lf')))->toBe(['source']);
        $page->call('clearFilters')->assertSet('lf', []);
        expect($page->html())->not->toContain('data-pills');
    });

    it('filters by a row\'s source logo or label dot, and a second click takes it away', function () {
        $page = Livewire::test(ListCaptureSessions::class)->call('quickFilter', 'source', 'zoom');
        $page->assertCanSeeTableRecords([$this->zoom])->assertCanNotSeeTableRecords([$this->whatsapp]);
        $page->call('quickFilter', 'source', 'zoom')->assertSet('lf', []);
        expect(Livewire::test(ListCaptureSessions::class)->html())->toContain('data-quick-source="whatsapp"')->toContain('data-quick-label="clients"');
    });
});

describe('the address', function () {
    it('keeps the filters in the address, so a link and Back open the same list, and reads an old folder as its filter', function () {
        // In the address as f, v and compose, each change a step of the browser's history (the new Library's only).
        $page = Livewire::test(ListCaptureSessions::class);
        expect((fn (): array => $this->queryString())->call($page->instance()))->toMatchArray(['lf' => ['as' => 'f', 'history' => true], 'savedView' => ['as' => 'v', 'history' => true]]);

        Livewire::withQueryParams(['f' => lfIs('source', ['zoom'])])->test(ListCaptureSessions::class)
            ->assertCanSeeTableRecords([$this->zoom])->assertCanNotSeeTableRecords([$this->whatsapp]);
        Livewire::withQueryParams(['folder' => 'whatsapp'])->test(ListCaptureSessions::class)
            ->assertSet('lf', lfIs('source', ['whatsapp']))->assertSet('folder', null)->assertCanNotSeeTableRecords([$this->zoom]);
        // An address that is no filter is no filter.
        Livewire::withQueryParams(['f' => ['colour' => 'red']])->test(ListCaptureSessions::class)->assertCanSeeTableRecords([$this->zoom, $this->whatsapp]);
    });
});

describe('the places', function () {
    it('keeps a call waiting for its decision in Needs you only, with the failures', function () {
        Livewire::test(ListCaptureSessions::class)->assertCanNotSeeTableRecords([$this->waiting]);
        Livewire::withQueryParams(['tab' => 'needs_you'])->test(ListCaptureSessions::class)
            ->assertCanSeeTableRecords([$this->waiting, $this->failed])->assertCanNotSeeTableRecords([$this->whatsapp, $this->zoom]);
        Livewire::withQueryParams(['tab' => 'important'])->test(ListCaptureSessions::class)
            ->assertCanSeeTableRecords([$this->zoom])->assertCanNotSeeTableRecords([$this->whatsapp]);
        Livewire::withQueryParams(['tab' => 'unread'])->test(ListCaptureSessions::class)->assertCanSeeTableRecords([$this->zoom, $this->whatsapp]);
    });
});

describe('saved views', function () {
    it('saves the filters, the search and the order as a view, opens it from the address and saves changes to it', function () {
        $page = Livewire::test(ListCaptureSessions::class)->call('applyFilters', lfIs('source', ['whatsapp']))->call('setLibrarySort', 'oldest')
            ->callAction('saveView', data: ['name' => 'WhatsApp calls'])->assertHasNoActionErrors();
        $view = SavedView::query()->withoutGlobalScopes()->firstOrFail();
        expect($view->name)->toBe('WhatsApp calls')->and($view->filters)->toBe(lfIs('source', ['whatsapp']))->and($view->sort)->toBe('oldest')
            ->and($page->get('savedView'))->toBe($view->id);

        $opened = Livewire::withQueryParams(['v' => $view->id])->test(ListCaptureSessions::class);
        $opened->assertSet('lf', lfIs('source', ['whatsapp']))->assertSet('librarySort', 'oldest')->assertCanNotSeeTableRecords([$this->zoom]);
        expect($opened->html())->toContain('WhatsApp calls')->not->toContain('data-update-view');
        $opened->call('applyFilters', lfIs('source', ['zoom']));
        expect($opened->html())->toContain('data-update-view');
        $opened->callAction('updateView');
        expect($view->fresh()->filters)->toBe(lfIs('source', ['zoom']));
    });

    it('says so at 30 views and saves nothing more', function () {
        foreach (range(1, SavedViews::MAX) as $i) {
            app(SavedViews::class)->create((int) $this->owner->current_workspace_id, $this->owner->id, ['name' => "View {$i}"]);
        }
        Livewire::test(ListCaptureSessions::class)->callAction('saveView', data: ['name' => 'One more']);
        expect(SavedView::query()->withoutGlobalScopes()->count())->toBe(30);
    });
});

describe('the sidebar', function () {
    it('lists the Library places with counts (Needs you and Unread strong), My views, Labels and Trash at the foot, with ● Record ▾', function () {
        app(SavedViews::class)->create((int) $this->owner->current_workspace_id, $this->owner->id, ['name' => 'Zoom calls', 'filters' => lfIs('source', ['zoom'])]);
        $html = (string) $this->get('/app/sessions')->assertOk()->getContent();

        foreach (['all', 'needs_you', 'unread', 'important', 'not_transcribed', 'audio_only'] as $place) {
            expect($html)->toContain('data-place="'.$place.'"');
        }
        expect($html)->toMatch('~data-place="needs_you".*?<span class="v2-sb-subcount is-strong">2</span>~s')
            ->toContain('data-place="shared"')->toContain('data-place="links"')->toContain('data-place="requests"')
            ->toMatch('~data-view="[0-9A-Za-z]{26}".*?Zoom calls.*?<span class="v2-sb-subcount">1</span>~s')
            ->toContain('data-label="clients"')->toContain('v2-label-dot--red')
            ->toContain('data-place="trash"')->toContain('data-record-menu')->toContain('data-import')->toContain('Import audio files')
            ->toContain('data-new-view')->toContain('data-new-label');
        // The first nine places answer ⌘1…⌘9: the six Library places, then My views.
        expect($html)->toContain('data-place="all" data-shortcut="1"')->toMatch('~data-view="[0-9A-Za-z]{26}" data-shortcut="7"~');
    });

    it('renames, moves and deletes a view, and makes a label', function () {
        $ws = (int) $this->owner->current_workspace_id;
        $a = app(SavedViews::class)->create($ws, $this->owner->id, ['name' => 'A'])['view']['id'];
        $b = app(SavedViews::class)->create($ws, $this->owner->id, ['name' => 'B'])['view']['id'];
        $sidebar = Livewire::test(Sidebar::class);
        // The sidebar's modals, as its ⋯ menu opens them (mountAction with the view's id).
        $act = fn (string $name, array $arguments, array $data = []) => $sidebar->call('mountAction', $name, $arguments)
            ->set('mountedActions.0.data', [...$sidebar->get('mountedActions.0.data') ?? [], ...$data])->call('callMountedAction');
        $act('renameView', ['id' => $a], ['name' => 'Renamed']);
        $sidebar->call('moveView', $b, -1);
        expect(array_column(app(SavedViews::class)->index($ws, $this->owner->id), 'name'))->toBe(['B', 'Renamed']);
        $sidebar->call('orderViews', [$a, $b]);
        expect(array_column(app(SavedViews::class)->index($ws, $this->owner->id), 'name'))->toBe(['Renamed', 'B']);
        $act('deleteView', ['id' => $b]);
        expect(array_column(app(SavedViews::class)->index($ws, $this->owner->id), 'name'))->toBe(['Renamed']);
        $act('newLabel', [], ['name' => 'Family', 'color' => '#2d9a63']);
        expect(LabelList::query()->where('user_id', $this->owner->id)->where('label_key', 'family')->value('color'))->toBe('#2d9a63');
    });
});

describe('Urdu', function () {
    it('reads the toolbar, the panel, the pills and the sidebar in Urdu, right to left', function () {
        $this->owner->forceFill(['console_preferences' => ['shell' => ['app_language' => 'ur']]])->save();
        $this->actingAs($this->owner->fresh(), 'web');
        $html = (string) $this->get('/app/sessions?'.http_build_query(['f' => lfIs('source', ['whatsapp'])]))->assertOk()->getContent();

        expect($html)->toContain('dir="rtl"')->toContain('آپ کی توجہ چاہیے')->toContain('میرے ویوز')->toContain('ترتیب اور منظر')
            ->toContain('سب صاف کریں')->toContain('ویو کے طور پر محفوظ کریں')->toContain('آڈیو فائلیں درآمد کریں')->toContain('واٹس ایپ');
    });
});
