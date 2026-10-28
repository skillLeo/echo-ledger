<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Services\CredentialVault;
use App\Services\TranscriptStore;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\Support\AdminTestHelpers;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class, AdminTestHelpers::class);

/*
 * "Re-transcribe as <language>" (v1.2): the current transcript stays until the new one is ready, then the new one
 * is shown (newest is the default) and the old one is kept as the other version.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    foreach (['assemblyai', 'elevenlabs'] as $p) {
        app(CredentialVault::class)->put($p, 'api_key', $p.'_test_key_1234567890');
    }
});

function finishedRecording(object $test, string $status = 'completed'): CaptureSession
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => 'en', 'status' => 'completed']);
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::from($status),
        'language' => 'en', 'text' => 'Me: I will send the invoice', 'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'I will send the invoice']]]);

    return $session;
}

it('starts the engine for the new language and keeps the current transcript until the new one is ready', function () {
    Queue::fake();
    $this->asToken($this->token);
    $session = finishedRecording($this);

    $response = $this->postJson("/api/sessions/{$session->id}/retranscribe", ['language' => 'ur'])->assertStatus(202);

    expect($response->json('transcript.kind'))->toBe('alternate')
        ->and($response->json('transcript.provider'))->toBe('elevenlabs') // Urdu: Groq first, without its key ElevenLabs
        ->and($response->json('transcript.language'))->toBe('ur')
        ->and($session->fresh()->language)->toBe('ur')
        ->and($session->finalTranscript->fresh()->text)->toBe('Me: I will send the invoice');
    Queue::assertPushed(TranscribeFinalJob::class, 1);
    $this->postJson("/api/sessions/{$session->id}/retranscribe", [])->assertStatus(422);
});

it('shows the new version as the transcript when it is done and keeps the old one as the other version', function () {
    Queue::fake();
    $this->asToken($this->token);
    $session = finishedRecording($this);
    $oldId = $session->finalTranscript->id;
    $this->postJson("/api/sessions/{$session->id}/retranscribe", ['language' => 'ur'])->assertStatus(202);
    $run = $session->transcripts()->where('kind', 'alternate')->sole();
    $run->update(['status' => TranscriptStatus::Processing]);

    app(TranscriptStore::class)->complete($run, new NormalizedTranscript('Me: میں invoice بھیج دوں گا', [new Segment(1, 'Me', 0, 1000, 'میں invoice بھیج دوں گا')], 'ur'));

    $session->refresh();
    expect($session->finalTranscript->id)->toBe($run->id)
        ->and($session->finalTranscript->language)->toBe('ur')
        ->and($session->transcripts()->where('kind', 'alternate')->sole()->id)->toBe($oldId)
        ->and($session->final_provider)->toBe('elevenlabs');
    $versions = collect($this->getJson("/api/sessions/{$session->id}")->assertOk()->json('transcripts'))->pluck('kind', 'id')->all();
    expect($versions[$run->id])->toBe('final')->and($versions[$oldId])->toBe('alternate')->and($versions)->toHaveCount(2);
});

it('runs a failed transcript again in the new language instead', function () {
    Queue::fake();
    $this->asToken($this->token);
    $session = finishedRecording($this, 'failed');

    $this->postJson("/api/sessions/{$session->id}/retranscribe", ['language' => 'ar'])->assertStatus(202)->assertJsonPath('transcript.kind', 'final');

    expect($session->finalTranscript->fresh()->status)->toBe(TranscriptStatus::Pending)
        ->and($session->finalTranscript->fresh()->language)->toBe('ar')
        ->and($session->transcripts()->where('kind', 'alternate')->count())->toBe(0);
});

it('offers Re-transcribe as on the recording page', function () {
    Queue::fake();
    Filament::setCurrentPanel('admin');
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello']], ['status' => 'completed']);
    $this->readyRecording($session);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('retranscribe_as', data: ['language' => 'ur'])
        ->assertNotified('Re-transcribing in Urdu with ElevenLabs Scribe');
    expect($session->transcripts()->where('kind', 'alternate')->sole()->meta['promote_when_done'])->toBeTrue();
});
