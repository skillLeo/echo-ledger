<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\SourceApp;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Models\CaptureSession;
use App\Services\LibraryOrganiser;
use App\Services\Views\TranscriptViews;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(AdminTestHelpers::class);

/*
 * The recording in the console: rename it (list row and recording page), download its audio and its transcript in
 * every format and Show as view, export many as one zip, and the live status that refreshes the list and the page
 * only while something is uploading or being transcribed.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Http::preventStrayRequests();
    $this->admin = $this->signInAsAdmin();
});

/** Each view's two lines of the fixture transcript, as exported: the unsure word keeps its "[?…]" mark. */
const P1_LINES = [
    'original' => ['آپ کیسے ہیں', '[?invoice] Friday tak bhejunga'],
    'roman' => ['Aap kaise hain', 'Main [?invoice] Jumma tak bhejunga'],
    'urdu' => ['آپ کیسے ہیں', 'میں [?انوائس] فرائیڈے تک بھیجوں گا'],
    'english' => ['How are you', 'I will send the [?invoice] by Friday'],
];

/** A finished Urdu transcript with one unsure word, its Show as views made, and (optionally) its audio. */
function p1Recording(object $test, array $session = [], bool $audio = true): CaptureSession
{
    $capture = $test->sessionWithTranscript($test->admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 2_000, 'text' => 'آپ کیسے ہیں'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 3_000, 'end_ms' => 6_000, 'text' => 'invoice Friday tak bhejunga', 'words' => [
            ['text' => 'invoice', 'start_ms' => 3_000, 'end_ms' => 3_400, 'confidence' => 0.2],
            ['text' => 'Friday', 'start_ms' => 3_500, 'end_ms' => 3_900, 'confidence' => 0.97],
        ]],
    ], ['title' => 'Acme kickoff', 'status' => SessionStatus::Completed, ...$session], 'final', ['language' => 'ur']);
    $transcript = $capture->finalTranscript;
    $views = ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($transcript), 'model' => 'claude-haiku-5-5', 'made_at' => now()->toAtomString(), 'kept' => []];
    foreach (['roman', 'urdu', 'english'] as $view) {
        $views[$view] = ['0' => P1_LINES[$view][0], '1' => P1_LINES[$view][1]];
    }
    $transcript->forceFill(['meta' => ['views' => $views]])->save();
    if ($audio) {
        $test->readyRecording($capture, 'RIFF-acme-kickoff-audio');
    }

    return $capture->fresh();
}

/** The text of an export, the document's own text for Word. */
function p1Text(string $format, string $body): string
{
    if ($format !== 'docx') {
        return $body;
    }
    $path = tempnam(sys_get_temp_dir(), 'p1-docx');
    file_put_contents($path, $body);
    $zip = new ZipArchive;
    $zip->open($path);
    $xml = (string) $zip->getFromName('word/document.xml');
    $zip->close();
    unlink($path);

    return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

describe('rename', function () {
    it('renames a recording from its row in the list, and an empty title brings the automatic one back', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Old name', 'source_app' => SourceApp::Zoom, 'updated_at' => now()->subDay()]);

        $this->panelComponent(ListCaptureSessions::class)
            ->assertTableActionVisible('rename', $session)
            ->callTableAction('rename', $session, data: ['title' => '  Budget review  '])
            ->assertHasNoTableActionErrors()
            ->assertNotified('Renamed to “Budget review”');
        // Saved like PATCH /api/sessions/{id}: the devices pick it up on their next sync.
        expect($session->fresh()->title)->toBe('Budget review')
            ->and($session->fresh()->updated_at->gt(now()->subMinute()))->toBeTrue();

        $this->panelComponent(ListCaptureSessions::class)->callTableAction('rename', $session, data: ['title' => '']);
        expect($session->fresh()->title)->toBeNull()
            ->and($session->fresh()->displayTitle())->toBe('Zoom call');
    });

    it('refuses a title longer than 200 characters', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => 'Kept']);

        $this->panelComponent(ListCaptureSessions::class)
            ->callTableAction('rename', $session, data: ['title' => str_repeat('t', 201)])
            ->assertHasTableActionErrors(['title' => 'max']);

        expect($session->fresh()->title)->toBe('Kept');
    });

    it('renames from the recording page, whose title carries the Rename button, and devices see the new title', function () {
        $device = Fx::device($this->admin);
        $session = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'device_id' => $device->id, 'title' => 'Old name']);
        $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);

        expect($page->html())->toContain('Old name')->toContain('wire:click="mountAction(\'rename\')"');
        $page->mountAction('rename')->assertActionDataSet(['title' => 'Old name']);
        $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
            ->callAction('rename', data: ['title' => 'Kickoff with Acme'])->assertHasNoActionErrors();

        expect($session->fresh()->title)->toBe('Kickoff with Acme');
        $this->app['auth']->forgetGuards();
        $this->withToken(Fx::token($device))->getJson('/api/sessions/'.$session->id)->assertOk()->assertJsonPath('title', 'Kickoff with Acme');
    });

    it('escapes the title it shows with the Rename button', function () {
        $session = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'title' => '<script>alert(1)</script>']);

        expect($this->get('/admin/sessions/'.$session->id)->assertOk()->getContent())
            ->not->toContain('<script>alert(1)</script>')
            ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    });
});

describe('download', function () {
    it('offers the audio and every transcript format in each ready view', function () {
        $session = p1Recording($this);
        $transcript = $session->finalTranscript;

        $modal = $this->modalHtml($this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->mountAction('download'));

        expect($modal)->toContain(e(route('admin.recordings.stream', ['recording' => $session->primaryRecording->id, 'download' => 1])))
            ->toContain('Audio (.wav)')
            ->toContain('Show as')->toContain('Original')->toContain('Roman Urdu')->toContain('اردو')->toContain('English')
            ->toContain('PDF (print)')->toContain('target="_blank"');
        foreach (['original', 'roman', 'urdu', 'english'] as $view) {
            foreach (['txt', 'docx', 'html', 'srt', 'json'] as $format) {
                $query = array_filter(['view' => $view === 'original' ? null : $view, 'print' => $format === 'html' ? 1 : null]);
                expect($modal)->toContain(e(route('admin.transcripts.export', ['transcript' => $transcript->id, 'format' => $format, ...$query])));
            }
        }
    });

    it('offers only the original words while no view is made, and only the audio while there is no transcript', function () {
        $session = p1Recording($this);
        $session->finalTranscript->forceFill(['meta' => []])->save();
        $modal = $this->modalHtml($this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->mountAction('download'));
        expect($modal)->toContain('PDF (print)')->not->toContain('Show as')->not->toContain('view=roman');

        $session->finalTranscript->update(['status' => TranscriptStatus::Processing]);
        $modal = $this->modalHtml($this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->mountAction('download'));
        expect($modal)->toContain('Audio (.wav)')->not->toContain('PDF (print)');
    });

    it('downloads the transcript in each format and view, unsure words marked', function (string $format, string $type, string $view) {
        $transcript = p1Recording($this, audio: false)->finalTranscript;
        $url = route('admin.transcripts.export', ['transcript' => $transcript->id, 'format' => $format, ...($view === 'original' ? [] : ['view' => $view])]);

        $response = $this->get($url)->assertOk();

        expect($response->headers->get('Content-Type'))->toContain($type)
            ->and($response->headers->get('Content-Disposition'))->toStartWith($format === 'html' ? 'inline' : 'attachment');
        $text = p1Text($format, (string) $response->getContent());
        foreach (P1_LINES[$view] as $line) {
            expect($text)->toContain($line);
        }
        foreach (array_diff(array_keys(P1_LINES), [$view, $view === 'urdu' ? 'original' : '-', $view === 'original' ? 'urdu' : '-']) as $other) {
            expect($text)->not->toContain(P1_LINES[$other][1]);
        }
    })->with([
        'text' => ['txt', 'text/plain'],
        'word' => ['docx', 'wordprocessingml'],
        'pdf page' => ['html', 'text/html'],
        'subtitles' => ['srt', 'subrip'],
        'json' => ['json', 'application/json'],
    ])->with(['original', 'roman', 'urdu', 'english']);

    it('opens the PDF page with a Save as PDF button and the print dialog', function () {
        $transcript = p1Recording($this, audio: false)->finalTranscript;

        $print = $this->get(route('admin.transcripts.export', ['transcript' => $transcript->id, 'format' => 'html', 'view' => 'roman', 'print' => 1]))->assertOk();
        expect($print->headers->get('Content-Disposition'))->toBe('inline')
            ->and($print->getContent())->toContain('Print or save as PDF')->toContain('window.print()')->toContain('class="noprint"')
            ->toContain('@media print')->toContain(P1_LINES['roman'][1]);

        $plain = $this->get(route('admin.transcripts.export', ['transcript' => $transcript->id, 'format' => 'html']))->assertOk();
        expect($plain->getContent())->not->toContain('window.print()');
    });

    it('downloads the audio as a file named after the recording, while the player still streams it', function () {
        $session = p1Recording($this);
        $recording = $session->primaryRecording;

        $download = $this->get(route('admin.recordings.stream', ['recording' => $recording->id, 'download' => 1]))->assertOk();
        expect($download->headers->get('Content-Type'))->toBe('audio/wav')
            ->and($download->headers->get('Content-Disposition'))->toStartWith('attachment')
            ->toContain('Acme kickoff '.$session->started_at->format('Y-m-d').'.wav');
        expect($this->get(route('admin.recordings.stream', $recording))->assertOk()->headers->get('Content-Disposition'))->toBeNull();
    });
});

describe('bulk export', function () {
    it('exports the selected recordings as one zip in the chosen format and view', function () {
        $a = p1Recording($this, ['title' => 'First call'], audio: false);
        $b = p1Recording($this, ['title' => 'Second call'], audio: false);

        $this->panelComponent(ListCaptureSessions::class)
            ->callTableBulkAction('export', [$a, $b], data: ['format' => 'txt', 'view' => 'english'])
            ->assertHasNoTableBulkActionErrors()
            ->assertFileDownloaded();
    });

    it('puts each transcript in the zip in the view, or in its original words when the view is not made', function () {
        $made = p1Recording($this, ['title' => 'With views'], audio: false);
        $plain = p1Recording($this, ['title' => 'Without views'], audio: false);
        $plain->finalTranscript->forceFill(['meta' => []])->save();

        $file = app(LibraryOrganiser::class)->exportZip($this->admin->current_workspace_id, [$made->id, $plain->id], 'txt', 'roman');

        $path = tempnam(sys_get_temp_dir(), 'p1-zip');
        file_put_contents($path, $file['body']);
        $zip = new ZipArchive;
        $zip->open($path);
        $texts = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $texts[(string) $zip->getNameIndex($i)] = (string) $zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);

        expect($file['count'])->toBe(2)->and($texts)->toHaveCount(2);
        $withViews = collect($texts)->first(fn (string $t, string $name): bool => str_starts_with($name, 'With views'));
        $withoutViews = collect($texts)->first(fn (string $t, string $name): bool => str_starts_with($name, 'Without views'));
        expect($withViews)->toContain(P1_LINES['roman'][1])
            ->and($withoutViews)->toContain(P1_LINES['original'][1])->not->toContain(P1_LINES['roman'][1]);
    });
});

describe('live status', function () {
    it('polls the list only while it shows a recording that is uploading or being transcribed', function () {
        $done = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Completed]);
        expect($this->panelComponent(ListCaptureSessions::class)->html())->not->toContain('wire:poll');

        $uploading = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Uploading]);
        expect($this->panelComponent(ListCaptureSessions::class)->html())->toContain('wire:poll.5s');

        $uploading->update(['status' => SessionStatus::Processing]);
        expect($this->panelComponent(ListCaptureSessions::class)->html())->toContain('wire:poll.5s');

        // Only what the list shows counts: another smart view without it does not poll.
        expect($this->panelComponent(ListCaptureSessions::class)->set('activeTab', 'pinned')->html())->not->toContain('wire:poll');

        $uploading->update(['status' => SessionStatus::Completed]);
        expect($this->panelComponent(ListCaptureSessions::class)->html())->not->toContain('wire:poll');
        expect($done->fresh()->status)->toBe(SessionStatus::Completed);
    });

    it('stops polling for an upload nobody has touched for hours', function () {
        CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Uploading])
            ->forceFill(['updated_at' => now()->subHours(7)])->saveQuietly();

        expect($this->panelComponent(ListCaptureSessions::class)->html())->not->toContain('wire:poll');
    });

    it('polls a recording page while its transcript is being made, then hands the player the transcript and stops', function () {
        $session = p1Recording($this, ['status' => SessionStatus::Processing]);
        $session->finalTranscript->update(['status' => TranscriptStatus::Processing]);

        $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
        expect($page->html())->toContain('wire:poll.5s="refreshLiveStatus"')->toContain('Transcribing.');

        // Still processing: nothing is pushed.
        $page->call('refreshLiveStatus')->assertNotDispatched('el-transcript-updated');

        $session->finalTranscript->update(['status' => TranscriptStatus::Completed]);
        $session->update(['status' => SessionStatus::Completed]);
        $page->call('refreshLiveStatus')
            ->assertDispatched('el-transcript-updated')
            ->assertNotified('The transcript is ready');
        expect($page->html())->not->toContain('wire:poll');
    });

    it('says a recording page is uploading, and does not poll a finished one', function () {
        $uploading = CaptureSession::factory()->create(['user_id' => $this->admin->id, 'status' => SessionStatus::Uploading]);
        expect($this->panelComponent(ViewCaptureSession::class, ['record' => $uploading->id])->html())
            ->toContain('wire:poll.5s="refreshLiveStatus"')->toContain('Uploading. This page updates by itself.');

        $done = p1Recording($this);
        expect($this->panelComponent(ViewCaptureSession::class, ['record' => $done->id])->html())->not->toContain('wire:poll');
    });
});
