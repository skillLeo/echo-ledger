<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\CaptureSessionResource;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * The recording page since v1.5: "Waiting for <engine> — retrying at 2:05 PM" while an Urdu or Auto transcript waits
 * for its engine, and "Backup engine — Re-transcribe?" when a lower engine wrote it.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Queue::fake();
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi'); // UTC+5
    $this->admin = $this->signInAsAdmin();
});

/** @param  array<string, mixed>  $transcript */
function consoleWaitSession(object $test, string $sessionStatus, array $transcript, ?string $language = 'ur'): CaptureSession
{
    $session = CaptureSession::factory()->create(['user_id' => $test->admin->id, 'status' => $sessionStatus, 'language' => $language, 'title' => 'Call with Omar']);
    $test->readyRecording($session);
    Transcript::query()->create([
        'capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'language' => $language,
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'میں کل invoice بھیجوں گا']],
        'text' => 'میں کل invoice بھیجوں گا', ...$transcript,
    ]);

    return $session->fresh();
}

it('says which engine a waiting transcript waits for and when it is tried again, in the console\'s time zone', function () {
    Carbon::setTestNow('2026-11-04 08:00:00');
    $session = consoleWaitSession($this, SessionStatus::Processing->value, ['provider' => 'groq', 'status' => TranscriptStatus::Pending, 'text' => null, 'segments' => [],
        'meta' => ['route' => ['used' => 'groq', 'retry_at' => '2026-11-04T09:05:00+00:00', 'retry_reason' => 'rate_limited', 'wait_started_at' => '2026-11-04T07:55:00+00:00']]]);

    $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()
        ->assertSee('Waiting for Groq Whisper large-v3 — retrying at 2:05 PM. This page updates by itself.')
        ->assertDontSee('Backup engine');

    // A retry on another day says the day too.
    Transcript::query()->where('capture_session_id', $session->id)->sole()->update(['meta' => ['route' => ['retry_at' => '2026-11-05T09:05:00+00:00', 'retry_reason' => 'unavailable']]]);
    $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()
        ->assertSee('Waiting for Groq Whisper large-v3 — retrying at Nov 5, 2:05 PM.');
});

it('says "Backup engine — Re-transcribe?" when a lower engine wrote the transcript, and Re-transcribe asks the first engine again', function () {
    app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test_key_1234567890');
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_test_key_1234567890');
    $session = consoleWaitSession($this, SessionStatus::Completed->value, ['provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'completed_at' => now(),
        'meta' => ['route' => ['used' => 'assemblyai', 'wanted' => 'groq', 'fallback' => true, 'backup' => true, 'backup_reason' => 'waited',
            'tried' => [['engine' => 'groq', 'code' => 'provider_rejected', 'transient' => 'rate_limited', 'at' => now()->toAtomString()]]]]]);

    $this->get(CaptureSessionResource::getUrl('view', ['record' => $session]))->assertOk()
        ->assertSee('Backup engine — Re-transcribe?')
        ->assertSee('Groq Whisper large-v3 stayed busy, so AssemblyAI wrote this transcript.')
        ->assertSee("mountAction('retranscribeBackup')", escape: false);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('retranscribeBackup')
        ->assertNotified('Re-transcribing with Groq Whisper large-v3');

    $run = Transcript::query()->where('capture_session_id', $session->id)->where('kind', TranscriptKind::Alternate->value)->sole();
    expect($run->provider)->toBe('groq')
        ->and($run->language)->toBe('ur')
        ->and($run->meta['promote_when_done'])->toBeTrue()
        ->and($session->fresh()->language)->toBe('ur');
    Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job): bool => $job->transcriptId === $run->id);
});

it('says why for a recording the first engine refused, and nothing for one it wrote itself', function () {
    $refused = consoleWaitSession($this, SessionStatus::Completed->value, ['provider' => 'soniox', 'status' => TranscriptStatus::Completed, 'completed_at' => now(),
        'meta' => ['route' => ['backup' => true, 'backup_reason' => 'rejected', 'tried' => [['engine' => 'groq', 'code' => 'provider_rejected']]]]]);
    $own = consoleWaitSession($this, SessionStatus::Completed->value, ['provider' => 'groq', 'status' => TranscriptStatus::Completed, 'completed_at' => now(),
        'meta' => ['route' => ['used' => 'groq', 'wanted' => 'groq', 'fallback' => false]]]);

    $this->get(CaptureSessionResource::getUrl('view', ['record' => $refused]))->assertOk()
        ->assertSee('Groq Whisper large-v3 could not take this recording, so Soniox wrote this transcript.');
    $this->get(CaptureSessionResource::getUrl('view', ['record' => $own]))->assertOk()
        ->assertDontSee('Backup engine');
});
