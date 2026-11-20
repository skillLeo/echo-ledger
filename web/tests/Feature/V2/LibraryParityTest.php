<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Services\InterfaceFlags;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
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
    $this->failed = Fx::session($this->owner, $this->device, ['title' => 'Transcript that failed', 'status' => SessionStatus::Failed, 'started_at' => $at(4)]);
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
    $page = Livewire::test(ListCaptureSessions::class);
    expect($page->html())->toContain('data-menu="group"')->toContain('data-option="group-app"')->toContain('data-option="group-none"');

    $page->call('setLibraryGroup', 'app');
    expect($page->get('tableGrouping'))->toBe('app:asc')->and($page->html())->toContain('WhatsApp')->toContain('Group by: Source');
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
    expect($m[1][0] ?? null)->toBe('Pinned')->and($m[2][0] ?? null)->toBe('1 recording')
        ->and($m[1][1] ?? null)->toBe('Today')->and($m[2][1] ?? null)->toBe('5 recordings')
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

it('shows Uploading, Transcribing, Waiting and Failed on their rows, and nothing on a finished one', function () {
    $rows = collect(libraryRows(Livewire::test(ListCaptureSessions::class)->html()));
    $row = fn (string $title): string => (string) $rows->first(fn (string $r): bool => str_contains($r, $title));
    expect($row('Still on its way'))->toContain('Uploading')
        ->and($row('Being written out'))->toContain('Transcribing')
        ->and($row('Call to keep or discard'))->toContain('Waiting')
        ->and($row('Transcript that failed'))->toContain('Failed')
        ->and($row('Payment terms'))->not->toContain('Uploading')->not->toContain('Transcribing')->not->toContain('Failed');
});

it('offers Retry and Delete on a failed recording\'s row in Failed uploads, and Retry transcribes it again', function () {
    $page = Livewire::test(ListCaptureSessions::class)->call('openPlace', 'failed');
    $page->assertCanSeeTableRecords([$this->failed])->assertCanNotSeeTableRecords([$this->call])
        ->assertTableActionVisible('retryFailed', $this->failed)->assertTableActionVisible('deleteFailed', $this->failed);
    $page->callTableAction('retryFailed', $this->failed)->assertNotified('Transcription queued');
    $page->callTableAction('deleteFailed', $this->failed);
    expect(CaptureSession::query()->find($this->failed->id))->toBeNull();
});

it('says how many calls wait for a decision, with Review, in one quiet line', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    expect($page->html())->toContain('1 call waiting for a decision')->toContain('data-waiting="1"');
    $page->call('openPlace', 'waiting');
    expect($page->html())->not->toContain('data-waiting=')->and($page->get('activeTab'))->toBe('waiting');
    $page->assertCanSeeTableRecords([$this->waiting])->assertCanNotSeeTableRecords([$this->call]);
});

it('lists every classic view under Views, with its count, and opens each', function () {
    $page = Livewire::test(ListCaptureSessions::class);
    $menu = collect($page->instance()->v2ViewMenu())->keyBy('key');
    expect($menu->keys()->all())->toBe(['all', 'today', 'this_week', 'waiting', 'bookmarked', 'failed', 'pinned', 'untranscribed'])
        ->and($menu['pinned']['count'])->toBe(1)->and($menu['waiting']['count'])->toBe(1)->and($menu['failed']['count'])->toBe(1);
    foreach (['this_week', 'bookmarked'] as $view) {
        expect($page->call('openPlace', $view)->get('activeTab'))->toBe($view);
    }
});
