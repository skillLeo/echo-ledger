<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Services\InterfaceFlags;
use App\Services\Library\LibraryFacets;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The v2.0 Library's rows of docs/v2/audit/parity.csv that need a world of their own (a pinned recording, one uploading,
 * one being transcribed, a call waiting, a failed upload that can be retried): Group by, List / Table and Columns, the
 * Pinned section with its count, the row's device and star, the statuses, the Failed uploads row's Retry and Delete,
 * and the "calls waiting" line. WebParityTest reads the controls of the shared world.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-17 11:42:00', 'UTC'));
    Fx::fakeRecordingsDisk();
    $this->owner = Fx::user(['name' => 'Bilal Qureshi']);
    $this->device = Fx::device($this->owner, ['name' => 'Galaxy S25']);
    $at = fn (int $hours): Carbon => Carbon::parse('2026-11-17 09:00:00')->subHours($hours);
    $this->call = Fx::session($this->owner, $this->device, ['title' => 'Payment terms for the March shipment', 'source_app' => SourceApp::WhatsApp, 'started_at' => $at(0), 'duration_ms' => 60_000]);
    Fx::transcript($this->call, 'Forty percent advance.');
    $this->pinned = Fx::session($this->owner, $this->device, ['title' => 'Rate for 12 mm rebar', 'source_app' => SourceApp::PhoneCall, 'started_at' => $at(30), 'pinned' => true]);
    Fx::transcript($this->pinned, 'Rate per ton.');
    $this->uploading = Fx::session($this->owner, $this->device, ['title' => 'Still on its way', 'status' => SessionStatus::Uploading, 'started_at' => $at(1)]);
    $this->transcribing = Fx::session($this->owner, $this->device, ['title' => 'Being written out', 'started_at' => $at(2)]);
    $this->transcribing->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing]);
    $this->waiting = Fx::session($this->owner, $this->device, ['title' => 'Call to keep or discard', 'status' => SessionStatus::Recording, 'started_at' => $at(3)]);
    $this->failed = Fx::session($this->owner, $this->device, ['title' => 'Transcript that failed', 'status' => SessionStatus::Failed, 'started_at' => $at(4), 'ended_at' => $at(4)->addMinutes(10)]);
    Fx::readyRecording($this->failed, 'audio of the failed call');
    $this->failed->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error_code' => 'engine_error']);
    Filament::setCurrentPanel('app');
    foreach (InterfaceFlags::UI_V2 as $flag) {
        app(InterfaceFlags::class)->set($flag, true, 0, [], [], 'everyone');
    }
    $this->actingAs($this->owner->fresh(), 'web');
});

/** The rows' text as the page draws it, one string per row (spaces as one). */
function libraryRows(string $html): array
{
    preg_match_all('~<div class="v2-lib-row".*?</time>~s', $html, $m);

    return array_map(fn (string $row): string => trim((string) preg_replace('/\s+/u', ' ', strip_tags($row))), $m[0]);
}

it('groups by date, source, project, language, status, device or none from Group by, and remembers it', function () {
    // Since 2.0.0-p Group by is in Sort & display.
    $page = Livewire::test(ListCaptureSessions::class);
    expect($page->html())->toContain('data-menu="display"')->toContain('data-option="group-app"')->toContain('data-option="group-none"');

    $page->call('setLibraryGroup', 'app');
    expect($page->get('tableGrouping'))->toBe('app:asc')->and($page->html())->toContain('WhatsApp')
        ->and(libraryMenuOption($page->html(), 'group-app'))->toContain('aria-checked="true"')->toContain('Source');
    expect($this->owner->fresh()->console_preferences['library']['all']['group'])->toBe('app');
    foreach (['project', 'language', 'status', 'device'] as $group) {
        expect($page->call('setLibraryGroup', $group)->get('tableGrouping'))->toBe($group.':asc');
    }
    $page->call('setLibraryGroup', 'none');
    expect($page->get('tableGrouping'))->toBeNull()->and($page->html())->not->toContain('fi-ta-group-header');
    // A choice that is not on the menu changes nothing.
    expect($page->call('setLibraryGroup', 'colour')->get('libraryGroup'))->toBe('none');
});

it('switches List / Table, and the table has Columns to choose what it shows', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    expect($page->html())->toContain('data-layout="list"')->toContain('data-layout="table"')->toContain('v2-lib-row');

    $page->call('setLibraryLayout', 'table');
    $html = $page->html();
    expect($html)->toContain('<thead')->toContain('Columns')->not->toContain('class="v2-lib-row"')
        ->and($this->owner->fresh()->console_preferences['shell']['layout'] ?? null)->toBe('table');
    foreach (['App', 'Project', 'Length', 'Date', 'Status', 'Device', 'Labels'] as $column) {
        expect($html)->toContain($column);
    }
    // The table remembers itself and the list comes back.
    expect(Livewire::test(ListCaptureSessions::class)->get('libraryLayout'))->toBe('table');
    expect(Livewire::test(ListCaptureSessions::class)->call('setLibraryLayout', 'list')->html())->toContain('class="v2-lib-row"');
});

it('heads the date groups with Pinned and shows each section\'s count; the sections fold', function () {
    $html = Livewire::test(ListCaptureSessions::class)->html();
    $flat = (string) preg_replace(['/<!--.*?-->/s', '/\s+/'], ['', ' '], $html);
    preg_match_all('~class="fi-ta-group-heading" > ([^<]+?) </h2> <p class="fi-ta-group-description" > ([^<]+?) </p>~', $flat, $m);
    // Since 2.0.0-p the call waiting for its decision is in Needs you only, so Today holds four.
    expect($m[1][0] ?? null)->toBe('Pinned')->and($m[2][0] ?? null)->toBe('1 recording')
        ->and($m[1][1] ?? null)->toBe('Today')->and($m[2][1] ?? null)->toBe('4 recordings')
        ->and($html)->toContain('fi-ta-group-header fi-collapsible')->toContain("isGroupCollapsed('Pinned')");
    // The pinned recording is in Pinned only, not in its day too.
    expect(substr_count($html, 'Rate for 12 mm rebar'))->toBe(1);
});

it('names the device on the row and stars a recording to pin it to the top, or unpin it', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    $rows = libraryRows($page->html());
    expect(collect($rows)->first(fn (string $r): bool => str_contains($r, 'Payment terms')))->toContain('WhatsApp · Galaxy S25');
    expect($page->html())->toContain('data-pin="'.$this->call->id.'"')->toContain('aria-label="Pin to the top"')->toContain('aria-label="Unpin"');

    $page->call('togglePin', $this->call->id);
    expect($this->call->fresh()->pinned)->toBeTrue();
    $page->call('togglePin', $this->call->id);
    expect($this->call->fresh()->pinned)->toBeFalse();
    // Somebody else's recording is not this Library's.
    $other = Fx::session(Fx::user(), null, ['title' => 'Not mine']);
    $page->call('togglePin', $other->id);
    expect($other->fresh()->pinned)->toBeFalse();
});

it('shows Waiting to upload, Transcribing…, Waiting and Failed · Retry on their rows, and nothing on a finished one', function () {
    // Since v2.1 the glossary's words (docs/PLAN_V2_1_POLISH.md section 1), from each recording's one Status.
    $rows = collect(libraryRows(Livewire::test(ListCaptureSessions::class)->html()));
    $row = fn (string $title): string => (string) $rows->first(fn (string $r): bool => str_contains($r, $title));
    expect($row('Still on its way'))->toContain('Waiting to upload')
        ->and($row('Being written out'))->toContain('Transcribing…')
        ->and($row('Transcript that failed'))->toContain('Failed · Retry')
        ->and($row('Payment terms'))->not->toContain('Waiting to upload')->not->toContain('Transcribing')->not->toContain('Failed');
    // Since 2.0.0-p the call waiting for its decision is in Needs you (with the failures), not among the recordings.
    expect($row('Call to keep or discard'))->toBe('');
    $needs = collect(libraryRows(Livewire::withQueryParams(['tab' => 'needs_you'])->test(ListCaptureSessions::class)->html()));
    expect((string) $needs->first(fn (string $r): bool => str_contains($r, 'Call to keep or discard')))->toContain('Waiting')
        ->and((string) $needs->first(fn (string $r): bool => str_contains($r, 'Transcript that failed')))->toContain('Failed');
});

it('offers Retry and Delete on a failed recording\'s row in Failed uploads, and Retry transcribes it again', function () {
    // Since 2.0.0-p Failed uploads is in Needs you (with the waiting call, which has neither button).
    $page = Livewire::test(ListCaptureSessions::class)->call('openPlace', 'failed');
    expect($page->get('activeTab'))->toBe('needs_you');
    $page->assertCanSeeTableRecords([$this->failed])->assertCanNotSeeTableRecords([$this->call])
        ->assertTableActionVisible('retryFailed', $this->failed)->assertTableActionVisible('deleteFailed', $this->failed)
        ->assertTableActionHidden('retryFailed', $this->waiting);
    // Retry queues a new run of the failed transcript, which waits for the engine again.
    Queue::fake();
    $failed = $this->failed->finalTranscript()->first();
    $page->callTableAction('retryFailed', $this->failed)->assertNotified('Transcription queued');
    Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job): bool => $job->transcriptId === $failed->id);
    expect($failed->fresh()->status)->toBe(TranscriptStatus::Pending);
    // It left Failed uploads while it waits; Delete on another failed one moves it to Recently deleted.
    $page->assertCanNotSeeTableRecords([$this->failed]);
    $other = Fx::session($this->owner, $this->device, ['title' => 'Another failed one', 'status' => SessionStatus::Failed]);
    $page = Livewire::test(ListCaptureSessions::class)->call('openPlace', 'failed')->callTableAction('deleteFailed', $other);
    expect(CaptureSession::query()->find($other->id))->toBeNull();
});

it('reads the group titles, the table\'s apps and the Failed uploads row\'s Retry and Delete in Urdu when the console is in Urdu', function () {
    $prefs = $this->owner->console_preferences ?? [];
    $prefs['shell'] = ['app_language' => 'ur'];
    $this->owner->forceFill(['console_preferences' => $prefs])->save();
    $this->actingAs($this->owner->fresh(), 'web');
    app()->setLocale('ur');
    $heads = function (string $html): array {
        preg_match_all('~<[^>]*class="[^"]*fi-ta-group-heading[^"]*"[^>]*>(.*?)</~s', $html, $m);

        return array_map(fn (string $t): string => trim(html_entity_decode(strip_tags($t))), $m[1]);
    };

    $page = Livewire::test(ListCaptureSessions::class);
    expect(implode(' | ', $heads($page->call('setLibraryGroup', 'app')->html())))->toContain('WhatsApp')->toContain('فون کال');
    // Since docs/PLAN_NAV_3_LEVELS.md section 0 nothing is called the Inbox: a recording without a project is "No project".
    expect(implode(' | ', $heads($page->call('setLibraryGroup', 'project')->html())))->toContain('کوئی پروجیکٹ نہیں')->not->toContain('Inbox')->not->toContain('ان باکس');
    expect(implode(' | ', $heads($page->call('setLibraryGroup', 'status')->html())))->toContain('ناکام')->not->toContain('Failed');

    // The table's App column too.
    $page->call('setLibraryGroup', 'none')->call('setLibraryLayout', 'table')
        ->assertTableColumnFormattedStateSet('source_app', 'WhatsApp', $this->call)
        ->assertTableColumnFormattedStateSet('source_app', 'فون کال', $this->pinned)
        ->assertTableColumnFormattedStateNotSet('source_app', 'Phone call', $this->pinned);

    $failed = Livewire::test(ListCaptureSessions::class)->call('openPlace', 'failed');
    $failed->assertTableActionHasLabel('retryFailed', 'دوبارہ کوشش کریں', $this->failed)
        ->assertTableActionHasLabel('deleteFailed', 'حذف کریں', $this->failed);
});

it('keeps the records grid to the page\'s width, so a long title cannot push the row\'s time off a phone screen', function () {
    $css = (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(resource_path('css/v2/console.css')));
    expect(preg_replace('/\s+/', ' ', $css))->toContain('.v2-library .fi-ta-content { grid-template-columns: minmax(0, 1fr); }');
});

/** One option of a v2 menu, as drawn: the element that carries data-option="<option>" up to its closing tag. */
function libraryMenuOption(string $html, string $option): string
{
    preg_match('~<(\w+)[^>]*data-option="'.preg_quote($option, '~').'"[^>]*>.*?</\1>~s', $html, $m);

    return $m[0] ?? '';
}

it('ticks the current choice in Sort & display (Group by, Sort, Density) and the filter panel (Source), as classic does', function () {
    // The tick is heroicon m-check (classic's), read by its path. Since 2.0.0-p the Views are the sidebar's places and
    // the Source menu the filter panel's Source, its choice a ticked box.
    $page = Livewire::test(ListCaptureSessions::class);
    $html = $page->html();
    expect(libraryMenuOption($html, 'group-date'))->toContain('aria-checked="true"')->toContain('M16.704 4.153')
        ->and(libraryMenuOption($html, 'group-app'))->toContain('aria-checked="false"')->not->toContain('M16.704 4.153')
        ->and(libraryMenuOption($html, 'sort-newest'))->toContain('aria-checked="true"')->toContain('M16.704 4.153')
        ->and(libraryMenuOption($html, 'sort-oldest'))->not->toContain('M16.704 4.153')
        ->and(libraryMenuOption($html, 'density-comfortable'))->toContain('aria-checked="true"');
    $source = $page->call('applyFilters', ['source' => ['op' => 'is', 'values' => ['whatsapp']]])->html();
    expect($source)->toMatch('~<input type="checkbox" id="lf-source-[0-9a-f]{10}" name="source" value="whatsapp" data-value\s+checked~')
        ->not->toMatch('~<input type="checkbox" id="lf-source-[0-9a-f]{10}" name="source" value="phone_call" data-value\s+checked~');

    // The tick follows the choice.
    $html = $page->call('setLibraryGroup', 'app')->call('setLibrarySort', 'oldest')->html();
    expect(libraryMenuOption($html, 'group-app'))->toContain('aria-checked="true"')->toContain('M16.704 4.153')
        ->and(libraryMenuOption($html, 'group-date'))->not->toContain('M16.704 4.153')
        ->and(libraryMenuOption($html, 'sort-oldest'))->toContain('M16.704 4.153')
        ->and(libraryMenuOption($html, 'sort-newest'))->not->toContain('M16.704 4.153');
});

it('moves Upload recording and New voice note to the rail\'s ● Record, the frame\'s one filled button', function () {
    // Since 2.0.0-p (docs/PLAN_LIBRARY_FILTERS.md section 6): Record a voice note, and Import audio files in its menu
    // (the file picker on the Library); the page itself has no header actions. Since docs/PLAN_NAV_3_LEVELS.md section 2
    // the round accent button at the top of the rail.
    Livewire::test(ListCaptureSessions::class)->assertActionDoesNotExist('upload')->assertActionDoesNotExist('voiceNote');
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toMatch('~href="[^"]*/app/upload\?record=1" class="v2-rail-rec[^"]*" data-sidebar-upload data-record~')
        ->toContain('data-record-menu')->toContain('Record a voice note')->toContain('Import audio files')
        ->toContain("\$dispatch('el-upload-pick')");
    $css = (string) file_get_contents(resource_path('css/v2/console.css'));
    expect($css)->toContain('.v2-rail-rec-dot {')->toContain('border-radius: var(--el-radius-full);')->toContain('background-color: var(--el-role-accent-default);');
});

it('lets a row\'s title take its own direction, so an English title in Urdu ends in its own "…"', function () {
    $html = Livewire::test(ListCaptureSessions::class)->html();
    expect($html)->toContain('<span class="v2-row-title" dir="auto">Payment terms for the March shipment</span>');
});

it('keeps the bell\'s count inside the bell with space before the avatar, puts the toolbar in two rows under 720, and keeps each meta separator with its item', function () {
    $css = (string) preg_replace('/\s+/', ' ', (string) preg_replace('~/\*.*?\*/~s', '', (string) file_get_contents(resource_path('css/v2/console.css'))));
    expect($css)
        ->toContain('.v2-tb .fi-topbar-database-notifications-btn .fi-icon-btn-badge-ctn { top: 0; inset-inline-start: auto; inset-inline-end: 0; translate: none; }')
        ->toContain('.v2-tb > .v2-um { margin-inline-start: var(--el-space-8); }')
        ->toContain('.v2-library .fi-ta-header-toolbar > div:last-child .fi-ta-filters-dropdown > .fi-dropdown-trigger { order: 2; }')
        ->toContain('.v2-library .fi-ta-header-toolbar > div:last-child > .v2-lib-tools { display: flex; flex: 1 0 100%; flex-wrap: nowrap; order: 3;')
        ->toContain('overflow-x: auto;')
        ->toContain('.v2-meta-item { display: inline-flex; align-items: center; gap: var(--el-space-8); white-space: nowrap; }');
    // The toolbar's two rows are for phones only.
    expect(strpos($css, '@media (max-width: 720px) { .v2-library .fi-ta-header-toolbar > div:last-child:has(> .v2-lib-tools) { min-width: 0; }'))->not->toBeFalse();
});

it('counts the calls waiting for a decision in the sidebar\'s Needs you, strong, and lists them there', function () {
    // Since 2.0.0-p "1 call waiting for a decision · Review" is the sidebar's Needs you (the waiting call, the failed one
    // and any stuck for more than 24 hours), and the list no longer carries a line above it.
    $needs = app(LibraryFacets::class)->needsYou((int) $this->owner->current_workspace_id, $this->owner->id);
    expect($needs['waiting'])->toBe(1)->and($needs['failed'])->toBe(1);
    $html = (string) $this->get('/app/sessions')->assertOk()->getContent();
    expect($html)->toMatch('~data-place="needs_you".*?<span class="v2-sb-subcount is-strong">'.$needs['count'].'</span>~s')->not->toContain('data-waiting=');
    $page = Livewire::test(ListCaptureSessions::class)->call('openPlace', 'needs_you');
    expect($page->get('activeTab'))->toBe('needs_you');
    $page->assertCanSeeTableRecords([$this->waiting, $this->failed])->assertCanNotSeeTableRecords([$this->call]);
});

it('gives every classic view its home: a sidebar place or a filter, with its count', function () {
    // Since 2.0.0-p (docs/PLAN_LIBRARY_FILTERS.md section 6): Today → Date › Today, This week → Date › Last 7 days,
    // Waiting for decision and Failed uploads → Needs you, Bookmarked → Bookmarks › Any bookmark, Pinned → Marked ›
    // Pinned, Untranscribed → Not transcribed. An old address with the view opens that home, with its rows.
    $this->call->markers()->create(['at_ms' => 1000, 'tag' => 'Decision']);
    $page = Livewire::test(ListCaptureSessions::class);
    $counts = fn (string $section): array => collect(collect($page->instance()->filterPanel())->firstWhere('key', $section)['options'])->pluck('count', 'value')->all();
    expect($counts('date')['today'])->toBe(4)->and($counts('date')['last_7_days'])->toBe(5)
        ->and($counts('marked')['pinned'])->toBe(1)->and($counts('bookmarks')['any'])->toBe(1);
    $page->call('applyFilters', ['bookmarks' => ['op' => 'is', 'values' => ['any']]])->assertCanSeeTableRecords([$this->call])->assertCanNotSeeTableRecords([$this->pinned]);
    $page->call('applyFilters', ['marked' => ['op' => 'is', 'values' => ['pinned']]])->assertCanSeeTableRecords([$this->pinned])->assertCanNotSeeTableRecords([$this->call]);
    Livewire::withQueryParams(['tab' => 'bookmarked'])->test(ListCaptureSessions::class)->assertSet('activeTab', 'all')
        ->assertSet('lf', ['bookmarks' => ['op' => 'is', 'values' => ['any']]])->assertCanSeeTableRecords([$this->call])->assertCanNotSeeTableRecords([$this->pinned]);
    Livewire::withQueryParams(['tab' => 'this_week'])->test(ListCaptureSessions::class)->assertSet('activeTab', 'all')
        ->assertSet('lf.date', ['op' => 'is', 'values' => ['custom'], 'from' => '2026-11-16', 'to' => '2026-11-22'])->assertCanSeeTableRecords([$this->call, $this->pinned]);
});
