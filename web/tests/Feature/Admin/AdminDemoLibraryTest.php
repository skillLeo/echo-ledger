<?php

declare(strict_types=1);

use App\Enums\SourceApp;
use App\Enums\SourceDevice;
use App\Filament\Infolists\TranscriptPlayerEntry;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Clients\Pages\ListClients;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\CaptureSession;
use App\Models\Recording;
use Database\Seeders\DemoSeeder;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/** The seeded demo library is the product's own end-to-end sample: phone, desktop and web items with audio and transcripts. */
beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->isolatedStorage();
    $this->admin = $this->makeAdmin(['email' => 'owner@echoledger.test']);
    $provider = AppAuthentication::make();
    $provider->saveSecret($this->admin, $provider->generateSecret());
    $this->seed(DemoSeeder::class);
    $this->actingAs($this->admin, 'web');
});

it('seeds a library that mixes phone, desktop and web sessions', function () {
    $sessions = CaptureSession::query()->get();

    expect($sessions)->toHaveCount(5)
        ->and($sessions->where('source_device', SourceDevice::Phone))->toHaveCount(2)
        ->and($sessions->where('source_device', SourceDevice::Desktop))->toHaveCount(2)
        ->and($sessions->where('source_device', SourceDevice::Web))->toHaveCount(1)
        ->and(Recording::query()->count())->toBe(5);
});

it('shows every demo session in the list with its source and app label', function () {
    $list = $this->panelComponent(ListCaptureSessions::class)
        ->assertCountTableRecords(5)
        ->assertSee(['Retainer check-in', 'Telehealth rollout', 'Weekly sync', 'Voice note from Ali', 'Phone', 'Desktop', 'Web', 'WhatsApp', 'WhatsApp Business', 'Zoom', 'Google Meet', 'Voice note']);

    // The WhatsApp Business session has no title, so it is shown by its app.
    $list->assertSee('WhatsApp Business call');
});

it('opens every demo session with its transcript, speakers and audio player', function () {
    foreach (CaptureSession::query()->with('transcripts')->get() as $session) {
        $html = $this->get('/admin/sessions/'.$session->id)->assertOk()->getContent();
        $segments = $session->finalTranscript->segments;

        expect($html)->toContain('el-player')
            ->toContain('<audio')
            ->toContain('Copy text')
            ->toContain('Copy with timestamps')
            ->toContain('aria-label="Playback speed"');
        $player = TranscriptPlayerEntry::payload($session);
        expect($player['segments'])->toHaveCount(count($segments))
            ->and($player['segments'][0]['text'])->toBe($segments[0]['text']);
    }
});

it('labels the speakers Me and Client for calls and a single speaker for the voice note', function () {
    $call = CaptureSession::query()->where('title', 'Retainer check-in')->firstOrFail();
    $note = CaptureSession::query()->where('source_app', SourceApp::VoiceNote)->firstOrFail();

    $this->get('/admin/sessions/'.$call->id)->assertOk();
    $callSpeakers = array_unique(array_column(TranscriptPlayerEntry::payload($call)['segments'], 'speaker'));
    $noteSpeakers = array_unique(array_column(TranscriptPlayerEntry::payload($note)['segments'], 'speaker'));

    expect($callSpeakers)->toContain('Me')->toContain('Client');
    expect($noteSpeakers)->not->toContain('Client');
});

it('streams every demo recording back as a decrypted WAV', function () {
    foreach (Recording::query()->get() as $recording) {
        $response = $this->get(route('admin.recordings.stream', $recording))->assertOk();
        $bytes = $response->streamedContent();

        expect(substr($bytes, 0, 4))->toBe('RIFF')
            ->and(substr($bytes, 8, 4))->toBe('WAVE')
            ->and(strlen($bytes))->toBeGreaterThan(1000);
    }
});

it('lists the demo clients with their keyterm and session counts', function () {
    $list = $this->panelComponent(ListClients::class)->assertSee(['Acme Holdings', 'Nova Clinics']);

    $rows = $list->instance()->getTableRecords()->keyBy('name');
    expect($rows['Acme Holdings']->keyterms_count)->toBe(5)
        ->and($rows['Acme Holdings']->capture_sessions_count)->toBe(2)
        ->and($rows['Nova Clinics']->keyterms_count)->toBe(3)
        ->and($rows['Nova Clinics']->capture_sessions_count)->toBe(1);
});

it('lists the demo devices as a phone and a desktop', function () {
    $this->panelComponent(ListDevices::class)
        ->assertSee(['Galaxy S25 Ultra', 'Work laptop', 'Phone', 'Desktop', 'Android', 'Windows']);
});

it('renders every console page with the demo data in place', function () {
    foreach (['/admin', '/admin/sessions', '/admin/clients', '/admin/clients/create', '/admin/devices', '/admin/settings', '/admin/api-credentials', '/admin/profile'] as $url) {
        $this->get($url)->assertOk();
    }
});
