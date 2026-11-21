<?php

declare(strict_types=1);

use App\Enums\TranscriptStatus;
use App\Enums\WorkspaceRole;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Support\SettingsContext;
use App\Models\CaptureSession;
use App\Models\Workspace;
use App\Services\CredentialVault;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * The new recording page's last parity rows (U9b part 2, docs/PLAN_V2_PARITY.md section 4): Previous / Next recording
 * (WEB-REC-007), the moments on the player bar (WEB-REC-010), Edit text in More (WEB-REC-015) and the wrong-script fix
 * (WEB-REC-016), in the parity world (tests/Feature/V2/Support/parity_world.php).
 */

require_once __DIR__.'/Support/parity_world.php';

beforeEach(function () {
    [$this->owner, $this->pages] = webParityWorld();
    $this->recs = CaptureSession::query()->where('user_id', $this->owner->id)->orderByDesc('started_at')->get();
});

/** The page as the person gets it, in the new design or classic. */
function recordingPageHtml(object $test, CaptureSession $session, bool $v2 = true): string
{
    webParityDesign($v2);
    app('auth')->forgetGuards();
    SettingsContext::forget();
    $html = (string) $test->actingAs($test->owner->fresh(), 'web')->get('/app/sessions/'.$session->id)->assertOk()->getContent();
    if ($v2) {
        expect(webParityIsV2($html))->toBeTrue('drawn by the v2.0 frame');
    }

    return $html;
}

/** The href of a link with this aria-label or title in the page's own content, or null when it is not a link. */
function recordingNavHref(string $html, string $label): ?string
{
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $node = (new DOMXPath($doc))->query('//main//a[normalize-space(.)="'.$label.'" or @aria-label="'.$label.'" or @title="'.$label.'"]')->item(0);

    return $node instanceof DOMElement ? $node->getAttribute('href') : null;
}

it('WEB-REC-007: goes to the previous and the next recording in the Library\'s default order, newest first', function () {
    // The person's recordings, newest first: 0 Payment terms, 1 Rate for 12 mm rebar, 2 Umrah group booking, 3 Samples.
    [$newest, $second, $third, $oldest] = $this->recs->values()->all();
    $html = recordingPageHtml($this, $second);
    expect(recordingNavHref($html, 'Previous recording'))->toEndWith('/app/sessions/'.$newest->id)
        ->and(recordingNavHref($html, 'Next recording'))->toEndWith('/app/sessions/'.$third->id);

    // On the "‹ Library" line above the title, as two 44 px icon buttons (‹ ›), not among the actions.
    $doc = new DOMDocument;
    @$doc->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xp = new DOMXPath($doc);
    $line = $xp->query('//*[@data-recording-nav]')->item(0);
    expect($line)->not->toBeNull()
        ->and($xp->query('.//a[contains(@class, "v2-back")]', $line)->length)->toBe(1)
        ->and($xp->query('.//*[@data-recording-nav-link][contains(@class, "fi-size-xl")][@aria-label]', $line)->length)->toBe(2)
        ->and($xp->query('.//*[@data-recording-nav-link="previous"]//svg', $line)->length)->toBe(1)
        ->and($xp->query('//*[contains(@class, "fi-header")]//*[contains(@class, "fi-ac")]//*[@aria-label="Previous recording"]')->length)->toBe(0);
    expect($html)->toMatch('~data-recording-nav-link="previous"[^>]*>|aria-label="Previous recording"~');

    // The ends: no Previous above the newest, no Next below the oldest (the buttons stay, turned off).
    $top = recordingPageHtml($this, $newest);
    expect(recordingNavHref($top, 'Previous recording'))->toBeNull()->and(recordingNavHref($top, 'Next recording'))->toEndWith('/app/sessions/'.$second->id)
        ->and(recordingNavHref(recordingPageHtml($this, $oldest), 'Next recording'))->toBeNull();

    // A pinned recording leads the Library, so it is the first.
    $third->forceFill(['pinned' => true])->save();
    expect(recordingNavHref(recordingPageHtml($this, $newest), 'Previous recording'))->toEndWith('/app/sessions/'.$third->id);

    // Only the person's own recordings: a teammate's newer one is never a neighbour.
    $mate = \App\Models\User::query()->where('email', 'sana@qureshi-textiles.example')->firstOrFail();
    Fx::session($mate, null, ['title' => 'Sana newest', 'workspace_id' => $this->owner->current_workspace_id, 'started_at' => now()]);
    $third->forceFill(['pinned' => false])->save();
    expect(recordingNavHref(recordingPageHtml($this, $newest), 'Previous recording'))->toBeNull();
    // Classic has no Previous / Next.
    expect(recordingNavHref(recordingPageHtml($this, $second, false), 'Previous recording'))->toBeNull();
});

it('WEB-REC-007: works Previous / Next out once when the page opens, not on every poll, and again when Pin moves it', function () {
    [$newest, $second] = $this->recs->values()->all();
    webParityDesign(true);
    $this->actingAs($this->owner->fresh(), 'web');
    $walks = fn (): int => collect(\Illuminate\Support\Facades\DB::getQueryLog())->filter(fn (array $q): bool => str_contains($q['query'], '"pinned" >') || str_contains($q['query'], '`pinned` >'))->count();
    \Illuminate\Support\Facades\DB::enableQueryLog();
    $page = Livewire::test(ViewCaptureSession::class, ['record' => $second->id]);
    expect($page->get('v2Neighbours')['previous'])->toEndWith('/app/sessions/'.$newest->id)
        ->and($walks())->toBe(1);

    \Illuminate\Support\Facades\DB::flushQueryLog();
    $page->call('refreshWork');
    expect($walks())->toBe(0);

    // Pinned, it leads the Library: nothing above it any more.
    $page->call('togglePin');
    expect($page->get('v2Neighbours')['previous'])->toBeNull();
});

it('WEB-REC-007: mirrors ‹ › right to left, in Urdu', function () {
    [, $second] = $this->recs->values()->all();
    $prefs = is_array($this->owner->console_preferences) ? $this->owner->console_preferences : [];
    $prefs['shell'] = array_merge(is_array($prefs['shell'] ?? null) ? $prefs['shell'] : [], ['app_language' => 'ur']);
    $this->owner->forceFill(['console_preferences' => $prefs])->save();
    $ur = recordingPageHtml($this, $second);
    $en = (function () use ($second) {
        $this->owner->forceFill(['console_preferences' => []])->save();

        return recordingPageHtml($this, $second);
    })();
    $glyph = fn (string $html, string $key): string => (string) preg_replace('/\s+/', ' ', (string) ((new DOMXPath((function () use ($html) { $d = new DOMDocument; @$d->loadHTML('<?xml encoding="utf-8"?>'.$html); return $d; })()))
        ->query('//*[@data-recording-nav-link="'.$key.'"]//svg')->item(0)?->C14N()));
    expect($ur)->toContain('aria-label="پچھلی ریکارڈنگ"')->toContain('aria-label="اگلی ریکارڈنگ"')
        // Previous points to the line's start: left in English, right in Urdu.
        ->and($glyph($ur, 'previous'))->toBe($glyph($en, 'next'))
        ->and($glyph($ur, 'next'))->toBe($glyph($en, 'previous'))
        ->and($glyph($en, 'previous'))->not->toBe($glyph($en, 'next'));
});

it('WEB-REC-007: gives a read-only console Previous / Next too (they only open pages)', function () {
    $workspace = Workspace::query()->findOrFail($this->owner->current_workspace_id);
    $viewer = Fx::user(['name' => 'Omar Viewer']);
    app(Workspaces::class)->addMember($workspace, $viewer, WorkspaceRole::Member);
    $viewer->forceFill(['current_workspace_id' => $workspace->id])->save();
    $first = Fx::session($viewer, null, ['title' => 'Omar one', 'workspace_id' => $workspace->id, 'started_at' => now()->subHour()]);
    $second = Fx::session($viewer, null, ['title' => 'Omar two', 'workspace_id' => $workspace->id, 'started_at' => now()->subHours(2)]);
    webParityDesign(true);
    $html = (string) $this->actingAs($viewer->fresh(), 'web')->get('/app/sessions/'.$first->id)->assertOk()->getContent();
    expect(recordingNavHref($html, 'Next recording'))->toEndWith('/app/sessions/'.$second->id);
});

it('WEB-REC-010: puts the bookmarks and pauses on the player bar, a click on one plays from it, and keeps the list', function () {
    $rec = $this->recs->first();
    $rec->markers()->create(['at_ms' => 65_000, 'tag' => 'Decision', 'note' => 'Forty percent advance']);
    $rec->markers()->create(['at_ms' => 300_000]);
    $rec->forceFill(['pauses' => [['at_ms' => 120_000, 'paused_ms' => 30_000]]])->save();
    $html = recordingPageHtml($this, $rec);

    // The player's data has them (the bar draws a tick for each), the list under the tools is still there.
    expect($html)->toContain('aria-label="Bookmarks and pauses"')->toContain('\\u0022at_ms\\u0022:65000')->toContain('\\u0022paused_ms\\u0022:30000')
        ->toContain('class="el-tick"')->toContain('class="el-tick el-tick-pause"')
        // The ticks play from their moment (the new design's script), with what each is on hover.
        ->toContain('window.elV2RecordingMarkers = true')->toContain('found.player.seekTo(found.item.ms)');
    expect(recordingPageHtml($this, $rec, false))->not->toContain('elV2RecordingMarkers')->toContain('aria-label="Bookmarks and pauses"');
});

it('WEB-REC-010: decides which moment a tick is and what it says as the Node test says', function () {
    $result = Process::path(base_path())->timeout(60)->run(['node', '--test', 'tests/js/recording-markers-core.test.mjs']);
    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->skip(fn (): bool => trim((string) shell_exec('command -v node')) === '', 'Node is not installed here');

it('WEB-REC-015: edits the transcript text from More › Edit text, with Done editing, through classic\'s own save', function () {
    $rec = $this->recs->first();
    $html = recordingPageHtml($this, $rec);
    expect(webParityControls($html))->toHaveKey('menuitem|more › edit text')
        ->and($html)->toContain('window.elV2EditText()')->toContain('window.elV2EditText = window.elV2EditText ||')
        // The player's own Done editing, as classic.
        ->and($html)->toContain("editing ? 'Done editing' : 'Edit text'");

    // The save is classic's: the player's saveSegment on the page.
    webParityDesign(true);
    $this->actingAs($this->owner->fresh(), 'web');
    $state = Livewire::test(ViewCaptureSession::class, ['record' => $rec->id])->call('saveSegment', 0, 'Assalam o alaikum Bilal sahib, the pro forma is ready.')->effects['returns'][0] ?? [];
    expect($state['edited'] ?? null)->toBeTrue()
        ->and($rec->finalTranscript()->firstOrFail()->segments[0]['text'])->toBe('Assalam o alaikum Bilal sahib, the pro forma is ready.');
});

it('WEB-REC-015: gives a read-only console no Edit text, and its save refuses', function () {
    $workspace = Workspace::query()->findOrFail($this->owner->current_workspace_id);
    $viewer = Fx::user(['name' => 'Omar Viewer']);
    app(Workspaces::class)->addMember($workspace, $viewer, WorkspaceRole::Member);
    $viewer->forceFill(['current_workspace_id' => $workspace->id])->save();
    $theirs = Fx::session($viewer, null, ['title' => 'Omar call', 'workspace_id' => $workspace->id]);
    Fx::readyRecording($theirs, 'audio of Omar call');
    Fx::transcript($theirs, 'Their words.');
    webParityDesign(true);
    $html = (string) $this->actingAs($viewer->fresh(), 'web')->get('/app/sessions/'.$theirs->id)->assertOk()->getContent();
    expect(webParityControls($html))->not->toHaveKey('menuitem|more › edit text');

    Livewire::test(ViewCaptureSession::class, ['record' => $theirs->id])->call('saveSegment', 0, 'Changed')->assertForbidden();
    expect($theirs->finalTranscript()->firstOrFail()->segments[0]['text'])->toBe('Their words.');
});

it('WEB-REC-016: shows "Wrong script detected · Transcribe with the next engine" in More for a transcript in the wrong script, and it works as classic\'s', function () {
    Queue::fake();
    $rec = $this->recs->first();
    $rec->finalTranscript()->firstOrFail()->update(['language' => 'ur', 'meta' => ['script_warning' => 'devanagari']]);
    app(CredentialVault::class)->put('soniox', 'api_key', 'soniox-test-key');

    $html = recordingPageHtml($this, $rec);
    expect(webParityControls($html))->toHaveKey('menuitem|more › wrong script detected · transcribe with the next engine');
    // Not shown for a transcript in the right script.
    $other = $this->recs->get(1);
    Fx::transcript($other, 'آپ کیسے ہیں');
    expect(webParityControls(recordingPageHtml($this, $other)))->not->toHaveKey('menuitem|more › wrong script detected · transcribe with the next engine');

    // The same action as classic's: it queues a second version from the next engine, and says so.
    webParityDesign(true);
    $this->actingAs($this->owner->fresh(), 'web');
    Livewire::test(ViewCaptureSession::class, ['record' => $rec->id])
        ->assertActionVisible('wrong_script')
        ->assertActionHasLabel('wrong_script', 'Wrong script detected · Transcribe with the next engine')
        ->callAction('wrong_script')->assertNotified('Second version queued');
    expect($rec->transcripts()->where('kind', 'alternate')->sole()->status)->toBe(TranscriptStatus::Pending);
});
