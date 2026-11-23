<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\LiveAssist\AssistSessions;
use App\Filament\Pages\RequestLinksPage;
use App\Filament\Pages\SharedWithMe;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Clients\Pages\ViewClient;
use App\Filament\Support\NavFrame;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\Shell;
use App\Models\CaptureSession;
use App\Models\LabelList;
use App\Models\SentItem;
use App\Services\ActivityFeed;
use App\Services\InterfaceFlags;
use App\Services\Library\LibraryFilters;
use App\Services\Library\SavedViews;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * docs/PLAN_NAV_3_LEVELS.md section 8 on the web console (the desktop's layout): the rail of places, each place's panel
 * and the page's tabs. Every rail, panel and tab item opens the right page with its place chosen; the Library's tabs
 * split the list by Source (docs/API.md "The Library's tabs") with the place, the filters and the search; Activity's,
 * Projects' and Settings' panels; Live Assist's tabs; nothing called the Inbox and the old folders in the command
 * palette. The flyout under 1100, the filters beside the list from 1440, Back and Forward and the per-place memory are
 * checked in a browser by scripts/screenshots.mjs (nav-*); their decisions in Node (tests/js/frame-core.test.mjs).
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $this->owner = Fx::user(['name' => 'Bilal Qureshi']);
    $this->device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $this->acme = Fx::client($this->owner, 'Acme');
    $this->acme->forceFill(['pinned' => true])->save();
    $this->quiet = Fx::client($this->owner, 'Quiet project');
    $make = function (string $title, SourceApp $app, array $state = []) {
        $session = Fx::session($this->owner, $this->device, ['title' => $title, 'source_app' => $app, ...$state]);
        $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'language' => 'en', 'text' => "Words of {$title}"]);

        return $session;
    };
    $this->call = $make('Payment terms', SourceApp::WhatsApp, ['client_id' => $this->acme->id, 'started_at' => now()->subHours(2)]);
    $this->phone = $make('Rate call', SourceApp::PhoneCall, ['started_at' => now()->subDays(2)]);
    $this->meeting = $make('Weekly sync', SourceApp::Zoom, ['started_at' => now()->subDays(3)]);
    $this->note = $make('Shopping list', SourceApp::VoiceNote, ['started_at' => now()->subDays(4)]);
    $this->file = $make('Uploaded file', SourceApp::VoiceNote, ['started_at' => now()->subDays(5), 'origin' => CaptureSession::ORIGIN_IMPORTED]);
    $this->waiting = Fx::session($this->owner, $this->device, ['title' => 'Call to keep or discard', 'status' => SessionStatus::Recording, 'started_at' => now()->subMinutes(5)]);
    LabelList::query()->create(['workspace_id' => $this->owner->current_workspace_id, 'user_id' => $this->owner->id, 'label_key' => 'clients', 'name' => 'Clients', 'color' => '#e15f56', 'position' => 0]);
    $this->call->labels()->create(['label' => 'Clients']);
    app(SavedViews::class)->create((int) $this->owner->current_workspace_id, $this->owner->id, ['name' => 'Zoom calls', 'filters' => ['source' => ['op' => 'is', 'values' => ['zoom']]]]);
    app(ActivityFeed::class)->post($this->owner, 'transcript_failed', 'Transcription failed', 'Body', null, 'heroicon-o-exclamation-circle');
    $done = app(ActivityFeed::class)->post($this->owner, 'transcript_ready', 'Transcript ready', 'Body', null, 'heroicon-o-check');
    unset($done);
    $this->owner->notifications()->get()->first(fn ($n): bool => ActivityFeed::payload($n)['kind'] === 'transcript_ready')?->markAsRead();
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
    $this->page = function (string $url): string {
        app('auth')->forgetGuards();
        SettingsContext::forget();

        return (string) $this->actingAs($this->owner->fresh(), 'web')->get($url)->assertOk()->getContent();
    };
});

/** The rail's part of a page. */
function navRailOf(string $html): string
{
    return (string) preg_replace('~^.*?(<nav class="v2-rail".*?</nav>).*$~s', '$1', $html);
}

/** The links of one place's panel, as [href => text]. */
function navPanelLinks(string $html, string $place): array
{
    $section = (string) preg_replace('~^.*?(<section class="v2-panel" data-panel="'.$place.'".*?</section>).*$~s', '$1', $html);
    preg_match_all('~<a href="([^"]+)"[^>]*>(.*?)</a>~s', $section, $m, PREG_SET_ORDER);
    $links = [];
    foreach ($m as [$all, $href, $text]) {
        $links[html_entity_decode($href)] = trim((string) preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($text))));
    }

    return $links;
}

describe('the rail', function () {
    it('opens each place\'s page with that place chosen and its panel shown, the others\' panels waiting for the flyout', function () {
        $homes = ['library' => '/app/sessions', 'projects' => '/app/clients', 'live' => '/app/live', 'ask' => '/app/ask', 'activity' => '/app/activity', 'settings' => '/app/settings'];
        foreach ($homes as $place => $path) {
            $html = ($this->page)($path);
            $rail = navRailOf($html);
            foreach ($homes as $other => $otherPath) {
                expect($rail)->toMatch('~<a href="https://echoledger.test'.preg_quote($otherPath, '~').'"[^>]*data-module="'.$other.'"~', "{$place}: {$other}'s icon");
            }
            expect($rail)->toMatch('~class="v2-rail-item is-on"\s+aria-current="page"[^>]*data-module="'.$place.'"~', "{$place} chosen")
                ->and(substr_count($rail, 'aria-current="page"'))->toBe(1, "{$place}: one place chosen")
                ->and($html)->toMatch('~<section class="v2-panel" data-panel="'.$place.'" aria-label="[^"]+"\s+x-bind:hidden~', "{$place}'s panel shown");
            foreach (array_diff(array_keys($homes), [$place]) as $other) {
                expect($html)->toMatch('~<section class="v2-panel" data-panel="'.$other.'" aria-label="[^"]+"\s+hidden\s+x-bind:hidden~', "{$place}: {$other}'s panel waits");
            }
        }
        // Home has no place: the logo is chosen, and no panel shows beside it.
        $home = ($this->page)('/app');
        expect(navRailOf($home))->toContain('class="v2-rail-logo is-on"')->not->toContain('aria-current="page"')
            ->and($home)->toContain('data-place="home"')->not->toMatch('~<section class="v2-panel" data-panel="[a-z]+" aria-label="[^"]+"\s+x-bind:hidden~');
    });

    it('carries the needs-you count on Library, Activity\'s badge, ● Record and Setup & health, with ⌘1…⌘6 in their tooltips', function () {
        $rail = navRailOf(($this->page)('/app/sessions'));
        expect($rail)->toMatch('~data-module="library".*?<span class="v2-rail-badge"><span class="v2-sr">, needs you: </span>1</span>~s')
            ->toMatch('~data-module="activity".*?<span class="v2-rail-badge">~s')
            ->toContain('href="https://echoledger.test/app/upload?record=1"')->toContain('data-record-menu')
            ->toContain('data-module="setup"')->toContain('aria-label="Setup &amp; health')
            ->toContain('title="Library (Ctrl 1)"')->toContain('title="Projects (Ctrl 2)"')->toContain('title="Live (Ctrl 3)"')
            ->toContain('title="Ask (Ctrl 4)"')->toContain('title="Activity (Ctrl 5)"')->toContain('title="Settings (Ctrl 6)"');
        expect(NavFrame::rail()['places'])->toHaveCount(6);
    });

    it('decides the flyout, the memory and the keys as the Node test says', function () {
        $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/frame-core.test.mjs']);
        expect($result->exitCode())->toBe(0, $result->output().$result->errorOutput());
    })->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');
});

describe('the panels', function () {
    it('opens every item of every place\'s panel on its page, with that place chosen', function () {
        $places = ['library' => '/app/sessions', 'projects' => '/app/clients', 'live' => '/app/live', 'ask' => '/app/ask', 'activity' => '/app/activity', 'settings' => '/app/settings'];
        $opened = 0;
        foreach ($places as $place => $path) {
            $links = navPanelLinks(($this->page)($path), $place);
            expect($links)->not->toBe([], "{$place} has items");
            foreach (array_keys($links) as $href) {
                $url = (string) preg_replace('~#.*$~', '', $href);
                $html = ($this->page)(str_replace('https://echoledger.test', '', $url));
                // Live Assist's settings are Settings' (Live & overlays); every other item stays in its place.
                $expected = str_contains($url, '/live-assist/settings') ? 'settings' : (str_contains($url, '/app/settings') || str_contains($url, '/get-the-apps') || str_contains($url, '/app/devices') || str_contains($url, '/app/workspace') ? 'settings' : $place);
                expect(navRailOf($html))->toMatch('~class="v2-rail-item is-on"\s+aria-current="page"[^>]*data-module="'.$expected.'"~', "{$place} › {$links[$href]} ({$url})");
                $opened++;
            }
        }
        expect($opened)->toBeGreaterThan(20);
    });

    it('lists the Library\'s Views, My views, Labels and Trash; Projects\' views and projects; Live\'s, Ask\'s and Activity\'s; Settings\' groups', function () {
        $library = navPanelLinks(($this->page)('/app/sessions'), 'library');
        expect(array_values($library))->toContain('All recordings', 'Needs you 1', 'Unread 5', 'Important', 'Not transcribed', 'Audio only', 'Shared with me', 'My links', 'Request links', 'Zoom calls 1', 'Clients 1', 'Trash');
        $projects = navPanelLinks(($this->page)('/app/clients'), 'projects');
        expect(array_values($projects))->toContain('All projects', 'Pinned', 'Recent', 'New project', 'Acme 1', 'Quiet project');
        expect(array_values(navPanelLinks(($this->page)('/app/live'), 'live')))->toContain('Call recording In the apps', 'Live Translate', 'Dictation', 'Captions');
        expect(array_values(navPanelLinks(($this->page)('/app/ask'), 'ask')))->toContain('New question', 'Acme', 'Quiet project');
        // (The panel's title opens the place's own page, the same as its first item.)
        // Needs you: the failed transcript and the call waiting for its decision (as the rail's badge).
        expect(array_values(navPanelLinks(($this->page)('/app/activity'), 'activity')))->toBe(['All', 'Needs you 2', 'Done']);
        $settings = navPanelLinks(($this->page)('/app/settings'), 'settings');
        // (A transcription key is for a plan that brings its own: one page here.)
        expect(array_values($settings))->toBe(['Settings', 'Transcription & language', 'Privacy & data 2', 'Notifications', 'Appearance', 'Account & plan 4', 'Setup & health 2', 'Shortcuts', 'About & help']);
    });

    it('shows Projects\' Pinned and Recent, every level of the tree', function () {
        Livewire::withQueryParams(['show' => 'pinned'])->test(ListClients::class)->assertCanSeeTableRecords([$this->acme])->assertCanNotSeeTableRecords([$this->quiet]);
        Livewire::withQueryParams(['show' => 'recent'])->test(ListClients::class)->assertCanSeeTableRecords([$this->acme])->assertCanNotSeeTableRecords([$this->quiet]);
        Livewire::withQueryParams([])->test(ListClients::class)->assertCanSeeTableRecords([$this->acme, $this->quiet]);
        expect(($this->page)('/app/clients?show=pinned'))->toMatch('~is-on"\s+aria-current="page"\s+data-projects-view="pinned"~');
    });

    it('shows Activity\'s Needs you (what needs action, not done yet, and the waiting calls) and Done (what was read)', function () {
        $needs = ($this->page)('/app/activity?show=needs_you');
        expect($needs)->toContain('Transcription failed')->not->toContain('Transcript ready')->toContain('Keep or discard?')
            ->toMatch('~is-on"\s+aria-current="page"\s+data-activity-view="needs_you"~');
        $done = ($this->page)('/app/activity?show=done');
        expect($done)->toContain('Transcript ready')->not->toContain('Transcription failed')->not->toContain('Keep or discard?');
        expect(($this->page)('/app/activity'))->toContain('Transcript ready')->toContain('Transcription failed');
    });

    it('gives Settings\' groups of several pages an inner list on the page, keeps its own list out of sight, and searches from the panel', function () {
        $profile = ($this->page)('/app/settings/profile');
        expect($profile)->toContain('data-inner-list')->toMatch('~<nav class="el-settings-nav v2-inner-list" aria-label="Account &amp; plan"~')
            ->toMatch('~class="v2-inner-item is-on"\s+aria-current="page"\s*>Profile</a>~')->toContain('>Plan &amp; usage</a>')->toContain('>Sign-in &amp; security</a>')
            ->toMatch('~<nav class="el-settings-nav" aria-label="Settings" hidden>~')
            ->toMatch('~is-on"\s+aria-current="page"\s+data-settings-group="account"~');
        // One page: no inner list.
        expect(($this->page)('/app/settings/notifications'))->not->toContain('data-inner-list');
        // The panel's search opens Settings with its words.
        expect(($this->page)('/app/settings?q=theme'))->toContain('data-mode="search"')->toContain('value="theme"');
    });
});

describe('the Library\'s tabs', function () {
    it('splits the list by Source: All, Calls, Meetings, Voice notes, with their counts, in the address', function () {
        $page = Livewire::test(ListCaptureSessions::class);
        expect(collect($page->instance()->libraryTabs())->pluck('count', 'key')->all())->toBe(['all' => 5, 'calls' => 2, 'meetings' => 1, 'voice_notes' => 1])
            ->and((fn (): array => $this->queryString())->call($page->instance()))->toMatchArray(['kind' => ['as' => 'kind', 'history' => true]]);
        Livewire::withQueryParams(['kind' => 'calls'])->test(ListCaptureSessions::class)
            ->assertCanSeeTableRecords([$this->call, $this->phone])->assertCanNotSeeTableRecords([$this->meeting, $this->note, $this->file, $this->waiting]);
        Livewire::test(ListCaptureSessions::class)->call('setLibraryKind', 'meetings')->assertSet('kind', 'meetings')
            ->assertCanSeeTableRecords([$this->meeting])->assertCanNotSeeTableRecords([$this->call]);
        // Voice notes are the voice_note Source: a file imported with a voice-note name is under All only.
        Livewire::withQueryParams(['kind' => 'voice_notes'])->test(ListCaptureSessions::class)->assertCanSeeTableRecords([$this->note])->assertCanNotSeeTableRecords([$this->file]);
        Livewire::test(ListCaptureSessions::class)->call('setLibraryKind', 'nonsense')->assertSet('kind', null)->assertCanSeeTableRecords([$this->file, $this->call]);
        expect(LibraryFilters::KINDS)->toBe([
            'calls' => ['whatsapp', 'whatsapp_business', 'phone_call', 'telegram', 'signal', 'messenger', 'instagram', 'skype'],
            'meetings' => ['zoom', 'google_meet', 'microsoft_teams', 'slack', 'discord'],
            'voice_notes' => ['voice_note'],
        ]);
    });

    it('narrows the place, the filters and the search, and the filter panel counts within the tab', function () {
        $page = Livewire::withQueryParams(['kind' => 'calls', 'tab' => 'unread'])->test(ListCaptureSessions::class);
        $page->assertCanSeeTableRecords([$this->call, $this->phone]);
        $page->call('applyFilters', ['project' => ['op' => 'is', 'values' => [$this->acme->id]]])->assertCanSeeTableRecords([$this->call])->assertCanNotSeeTableRecords([$this->phone]);
        expect(collect($page->instance()->libraryTabs())->pluck('count', 'key')->all())->toBe(['all' => 1, 'calls' => 1, 'meetings' => 0, 'voice_notes' => 0])
            ->and($page->instance()->libraryFacets()['total'])->toBe(1);
        $search = Livewire::withQueryParams(['kind' => 'calls'])->test(ListCaptureSessions::class)->set('tableSearch', 'rate');
        $search->assertCanSeeTableRecords([$this->phone])->assertCanNotSeeTableRecords([$this->call]);
        expect(collect($search->instance()->libraryTabs())->pluck('count', 'key')->all())->toBe(['all' => 1, 'calls' => 1, 'meetings' => 0, 'voice_notes' => 0])
            ->and(collect(collect($search->instance()->filterPanel())->firstWhere('key', 'source')['options'])->pluck('count', 'value')->all())->toMatchArray(['phone_call' => 1, 'zoom' => 0]);
    });

    it('shows the tabs on the toolbar row after the breadcrumb, and the filters from the right', function () {
        $html = Livewire::test(ListCaptureSessions::class)->html();
        expect($html)->toMatch('~data-library-crumbs.*?<nav class="v2-tabs v2-lib-tabs" aria-label="Kinds of recording" data-library-tabs>~s')
            ->toMatch('~class="v2-tab is-on"\s+aria-current="page"\s+wire:click="setLibraryKind\(null\)" data-library-tab="all">\s*<span>All</span>\s*<span class="v2-tab-count">5</span>~')
            ->toContain('data-library-tab="calls"')->toContain('data-library-tab="meetings"')->toContain('data-library-tab="voice_notes"')
            ->toContain('class="v2-lib-shell is-right"');
    });
});

describe('Live Assist\'s tabs', function () {
    it('has Upcoming · Past reports · Practice under the heading, the open one marked', function () {
        app(InterfaceFlags::class)->set(InterfaceFlags::LIVE_ASSIST, true, 0, [], [], 'everyone');
        $tabs = fn (string $url): string => (string) preg_replace('~^.*?(<nav class="v2-tabs v2-page-tabs".*?</nav>).*$~s', '$1', ($this->page)($url));
        $sessions = $tabs(NavFrame::assistSessionsUrl('practice'));
        expect($sessions)->toContain('data-page-tab="upcoming"')->toContain('data-page-tab="reports"')
            ->toMatch('~class="v2-tab is-on"\s+aria-current="page"\s+data-page-tab="practice"~');
        expect($tabs('/app/live-assist/preps'))->toMatch('~class="v2-tab is-on"\s+aria-current="page"\s+data-page-tab="upcoming"~');
    })->skip(fn (): bool => ! class_exists(AssistSessions::class));
});

describe('nothing twice, nothing called the Inbox', function () {
    it('keeps Activity and the avatar in the rail only (no bell or avatar in the top bar)', function () {
        $html = ($this->page)('/app/sessions');
        $top = (string) preg_replace('~^.*?(<div [^>]*class="fi-topbar-ctn v2-tb-ctn".*?</nav>).*$~s', '$1', $html);
        expect($top)->toStartWith('<div ');
        expect($top)->not->toContain('ActivityBell')->not->toContain('v2-um-trigger')
            ->and(navRailOf($html))->toContain('v2-um-trigger')->toContain('data-module="activity"');
    });

    it('opens the old folders from the command palette: each Source and No project (the old Inbox)', function () {
        $pages = collect(Shell::pages())->keyBy('id');
        expect($pages['source-whatsapp']['label'])->toBe('WhatsApp')
            ->and(urldecode($pages['source-whatsapp']['url']))->toEndWith('/app/sessions?f[source][op]=is&f[source][values][0]=whatsapp')
            ->and($pages['place-no-project']['label'])->toBe('No project')
            ->and(urldecode($pages['place-no-project']['url']))->toEndWith('/app/sessions?f[project][op]=is&f[project][values][0]=none');
        foreach (LibraryFilters::SOURCES as $source) {
            expect($pages->has('source-'.$source))->toBeTrue($source);
        }
        Livewire::withQueryParams(['f' => ['source' => ['op' => 'is', 'values' => ['whatsapp']]]])->test(ListCaptureSessions::class)
            ->assertCanSeeTableRecords([$this->call])->assertCanNotSeeTableRecords([$this->phone]);
    });

    it('says "No project" in Request links\' picker and its list, and no "Inbox" in Save a copy, Delete project or a new request link', function () {
        // The modals as their buttons open them (mountAction), read from the open dialog itself.
        $requests = Livewire::test(RequestLinksPage::class)->mountAction('create')->assertActionMounted('create');
        $form = $requests->instance()->getSchema($requests->instance()->getMountedActionSchemaName());
        $picker = collect($form?->getFlatComponents(withHidden: true) ?? [])->first(fn ($c): bool => $c instanceof Select && $c->getName() === 'client_id');
        expect($picker?->getPlaceholder())->toBe('No project (sound and video only)')
            ->and(collect($form?->getFlatComponents(withHidden: true) ?? [])->map(fn ($c) => method_exists($c, 'getPlaceholder') ? (string) $c->getPlaceholder() : '')->implode(' '))->not->toContain('Inbox')
            ->and((string) $requests->instance()->getMountedAction()?->getModalDescription())->not->toContain('Inbox');
        expect((string) file_get_contents(app_path('Filament/Pages/RequestLinksPage.php')))->toContain("placeholder(ConsoleV2::library() ? __('No project') : __('Inbox'))");

        $project = Livewire::test(ViewClient::class, ['record' => $this->acme->getRouteKey()])->mountAction('deleteProject')->assertActionMounted('deleteProject');
        expect((string) $project->instance()->getMountedAction()?->getModalDescription())->toContain('they stay in All recordings, with no project')->not->toContain('Inbox');

        $mate = Fx::user(['name' => 'Teammate']);
        $item = SentItem::query()->forceCreate(['workspace_id' => $this->owner->current_workspace_id, 'from_user_id' => $mate->id, 'to_user_id' => $this->owner->id,
            'kind' => 'session', 'capture_session_id' => $this->call->id, 'title' => 'Payment terms', 'sent_at' => now()]);
        $shared = Livewire::test(SharedWithMe::class)->mountTableAction('save', $item);
        expect((string) $shared->instance()->getMountedAction()?->getModalDescription())->toContain('copied into this workspace\'s recordings, with no project')->not->toContain('Inbox');
    });
});
