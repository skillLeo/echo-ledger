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
use Filament\Facades\Filament;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * The console laid out like the phone (P2): a bottom bar on narrow screens, the Library as rows with chips and a
 * detail pane on wide screens, and the Activity and Upload pages.
 */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = $this->signInAsAdmin();
});

describe('navigation', function () {
    it('has the phone\'s bottom bar with Library, Clients, Upload, Activity and More, the current page marked', function () {
        $html = $this->get(CaptureSessionResource::getUrl('index'))->assertOk()->getContent();

        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
        $nav = (new DOMXPath($dom))->query('//nav[contains(@class, "el-bottom-nav")]')->item(0);

        expect($nav)->not->toBeNull()->and($nav->getAttribute('aria-label'))->toBe('Main');
        $labels = array_map(fn (DOMNode $n): string => trim(preg_replace('/\s+/', ' ', $n->textContent)), iterator_to_array($nav->childNodes));
        expect(implode('|', array_values(array_filter($labels))))->toBe('Library|Clients|Upload|Activity|More');
        $current = (new DOMXPath($dom))->query('.//*[@aria-current="page"]', $nav);
        expect($current->length)->toBe(1)->and(trim($current->item(0)->textContent))->toBe('Library');
        expect($html)->toContain('$store.sidebar.open()');
    });

    it('shows the bottom bar only below 1024 px, where the sidebar becomes a drawer', function () {
        $css = (string) file_get_contents(resource_path('css/filament/admin/theme.css'));

        expect($css)->toMatch('/@media \(min-width: 1024px\) \{\s*\.el-bottom-nav \{\s*display: none;/')
            ->toMatch('/@media \(max-width: 1023\.98px\) \{\s*\.fi-body:has\(\.el-bottom-nav\) \.fi-main-ctn \{\s*padding-bottom:/');
        expect(Filament::getPanel('admin')->isSidebarCollapsibleOnDesktop())->toBeTrue();
    });

    it('leaves the bottom bar off the sign-in page', function () {
        auth()->logout();

        expect($this->get('/admin/login')->assertOk()->getContent())->not->toContain('el-bottom-nav');
    });

    it('marks Activity in the bar with the number of unread notifications', function () {
        CaptureSession::factory()->create(['user_id' => $this->admin->id])->transcripts()->create([
            'kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'segments' => []]);

        $html = $this->get(Activity::getUrl())->assertOk()->getContent();

        expect($html)->toContain('el-bottom-nav-badge')->toMatch('/aria-current="page"[^>]*>\s*<span class="el-bottom-nav-icon">/');
    });
});

describe('library', function () {
    it('draws the smart views as chips that switch the list', function () {
        CaptureSession::factory()->create(['user_id' => $this->admin->id, 'pinned' => true]);

        $list = $this->panelComponent(ListCaptureSessions::class);
        expect($list->html())->toContain('el-view-chips')->toContain('aria-pressed="true"')
            ->toContain("wire:click=\"\$set('activeTab', 'pinned')\"");

        $list->set('activeTab', 'pinned');
        expect($list->html())->toMatch('/aria-pressed="true"\s+wire:click="\$set\(\'activeTab\', \'pinned\'\)"/');
    });

    it('shows the chosen recording beside the list, with its transcript and Open', function () {
        $session = $this->sessionWithTranscript($this->admin, [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello there'],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => 'Hi, thanks for calling'],
        ], ['title' => 'Invoice questions']);

        $list = $this->panelComponent(ListCaptureSessions::class);
        expect($list->html())->toContain('Choose a recording');

        $html = $list->call('selectRecording', $session->id)->assertSet('selected', $session->id)->html();
        expect($html)->toContain('Invoice questions')->toContain('Hi, thanks for calling')
            ->toContain(e(CaptureSessionResource::getUrl('view', ['record' => $session])));

        expect($list->call('closeRecording')->html())->toContain('Choose a recording');
    });

    it('does not show another workspace\'s recording beside the list', function () {
        $theirs = CaptureSession::factory()->create(['user_id' => $this->makeAdmin()->id, 'title' => 'Not yours']);

        expect($this->panelComponent(ListCaptureSessions::class)->call('selectRecording', $theirs->id)->html())
            ->not->toContain('Not yours')->toContain('Choose a recording');
    });

    it('puts the waiting card above the list in the phone\'s words', function () {
        CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Recording, 'duration_ms' => 125_000]);

        $this->get(CaptureSessionResource::getUrl('index'))->assertOk()->assertSee('el-waiting', false)->assertSee('Keep or discard?')->assertSee('1 call waiting');
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
