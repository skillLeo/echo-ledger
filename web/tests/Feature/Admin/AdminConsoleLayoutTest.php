<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\Activity;
use App\Filament\Pages\Upload;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\ActivityFeed;
use Filament\Facades\Filament;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * The console laid out like the phone (P2): a bottom bar on narrow screens, the Library as rows with chips and a
 * detail pane on wide screens, and the Activity and Upload pages.
 */
beforeEach(function () {
    Filament::setCurrentPanel('app');
    $this->admin = $this->signInAsAdmin();
});

describe('navigation', function () {
    it('has the phone\'s bottom tabs Home, Library, Record, Activity and More, the current page marked (since v1.6, P4.6)', function () {
        $html = $this->get(CaptureSessionResource::getUrl('index'))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $nav = (new DOMXPath($dom))->query('//nav[contains(@class, "el-bottom-nav")]')->item(0);

        expect($nav)->not->toBeNull()->and($nav->getAttribute('aria-label'))->toBe('Main');
        $labels = array_map(fn (DOMNode $n): string => trim(preg_replace('/\s+/', ' ', $n->textContent)), iterator_to_array($nav->childNodes));
        expect(implode('|', array_values(array_filter($labels))))->toBe('Home|Library|Record|Activity|More');
        $current = (new DOMXPath($dom))->query('.//*[@aria-current="page"]', $nav);
        expect($current->length)->toBe(1)->and(trim($current->item(0)->textContent))->toBe('Library');
        expect($html)->toContain('$store.sidebar.open()');
    });

    it('shows the bottom tabs only up to 600 px, the phone\'s width (since v1.6, P4.6)', function () {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        expect($css)->toMatch('/@media \(min-width: 600\.02px\) \{\s*\.el-bottom-nav \{\s*display: none;/')
            ->toMatch('/@media \(max-width: 600px\) \{\s*\.fi-body:has\(\.el-bottom-nav\) \.fi-main-ctn \{\s*padding-bottom:/');
        expect(Filament::getPanel('app')->isSidebarCollapsibleOnDesktop())->toBeTrue();
    });

    it('leaves the bottom bar off the sign-in page', function () {
        auth()->logout();

        expect($this->get('/app/login')->assertOk()->getContent())->not->toContain('el-bottom-nav');
    });

    it('marks Activity in the bar with the number of what needs you (since v1.6 a ready transcript is not counted)', function () {
        CaptureSession::factory()->create(['user_id' => $this->admin->id])->transcripts()->create([
            'kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'segments' => []]);
        expect($this->get(Activity::getUrl())->assertOk()->getContent())->not->toContain('el-bottom-nav-badge');

        app(ActivityFeed::class)->post($this->admin, 'transcript_failed', 'Transcription failed', 'Call', null, 'heroicon-o-exclamation-triangle');
        $html = $this->get(Activity::getUrl())->assertOk()->getContent();

        expect($html)->toContain('el-bottom-nav-badge')->toMatch('/aria-current="page"[^>]*>\s*<span class="el-bottom-nav-icon">/');
    });
});

describe('library', function () {
    it('puts the smart views and folders in one compact toolbar menu instead of rows of chips', function () {
        CaptureSession::factory()->create(['user_id' => $this->admin->id, 'pinned' => true]);

        $list = $this->panelComponent(ListCaptureSessions::class);
        $html = $list->html();
        expect($html)->toContain('el-library-toolbar')->toContain('data-menu="view"')->toContain('data-menu="folder"')
            ->toContain('data-menu="group"')->toContain('data-menu="sort"')->toContain('All recordings')
            ->toContain("wire:click=\"\$set('activeTab', 'pinned')\"")
            ->not->toContain('el-view-chips')->not->toContain('el-folder-chips')
            // Exactly one toolbar row.
            ->and(substr_count($html, 'class="el-library-toolbar"'))->toBe(1);

        $list->set('activeTab', 'pinned');
        expect($list->html())->toMatch('/class="el-tool el-on" data-menu="view"/')
            ->toMatch('/data-option="view-pinned" aria-checked="true"/');
    });

    it('opens the chosen recording in the drawer on the right, with its transcript and Open (since v1.6, P4.4)', function () {
        $session = $this->sessionWithTranscript($this->admin, [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello there'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => 'Hi, thanks for calling'],
        ], ['title' => 'Invoice questions']);

        $list = $this->panelComponent(ListCaptureSessions::class);
        expect($list->html())->not->toContain('data-recording-drawer');

        $html = $list->call('selectRecording', $session->id)->assertSet('selected', $session->id)->html();
        expect($html)->toContain('data-recording-drawer="'.$session->id.'"')->toContain('Invoice questions')->toContain('Hi, thanks for calling')
            ->toContain(e(CaptureSessionResource::getUrl('view', ['record' => $session])))
            ->toContain('Copy text')->toContain('Close (Esc)')->toContain('closeRecording()');

        expect($list->call('closeRecording')->html())->not->toContain('data-recording-drawer');
    });

    it('does not show another workspace\'s recording beside the list', function () {
        $theirs = CaptureSession::factory()->create(['user_id' => $this->makeAdmin()->id, 'title' => 'Not yours']);

        expect($this->panelComponent(ListCaptureSessions::class)->call('selectRecording', $theirs->id)->html())
            ->not->toContain('Not yours')->not->toContain('data-recording-drawer');
    });

    it('puts one slim waiting line above the list (since v1.5, no card)', function () {
        CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Recording, 'duration_ms' => 125_000]);

        $this->get(CaptureSessionResource::getUrl('index'))->assertOk()->assertSee('el-waiting-chip', false)
            ->assertSeeInOrder(['1 call waiting', 'Review'])->assertDontSee('Keep or discard?');
    });
});

describe('activity', function () {
    it('groups what needs action, today and earlier, and opens a notification\'s recording', function () {
        $failed = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Call with Omar']);
        Transcript::query()->create(['capture_session_id' => $failed->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Failed, 'error' => 'Engine down']);
        $ready = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Weekly sync']);
        Transcript::query()->create(['capture_session_id' => $ready->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'segments' => []]);
        $this->admin->notifications()->where('data->title', 'Transcript ready')->update(['created_at' => now()->subDays(3)]);

        $page = $this->panelComponent(Activity::class);
        $html = $page->html();
        expect($html)->toContain('Needs action')->toContain('Transcription failed')->toContain('Earlier')->toContain('Transcript ready')
            ->and(strpos($html, 'Transcription failed'))->toBeLessThan(strpos($html, 'Transcript ready'));

        $id = $this->admin->notifications()->where('data->title', 'Transcription failed')->value('id');
        $page->call('open', $id)->assertRedirect(CaptureSessionResource::getUrl('view', ['record' => $failed]));
        expect($this->admin->notifications()->find($id)->read_at)->not->toBeNull();
    });

    it('says when there is nothing new', function () {
        $this->panelComponent(Activity::class)->assertSee('Nothing new');
    });
});

it('has an Upload page with the Library\'s chunked uploader', function () {
    $this->get(Upload::getUrl())->assertOk()
        ->assertSee('Drop sound or video files here')
        ->assertSee('elUploader(', false)
        ->assertSee('admin-upload', false);
});
