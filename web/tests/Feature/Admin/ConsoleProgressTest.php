<?php

declare(strict_types=1);

use App\Enums\RecordingRole;
use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Filament\Support\RecordingProgress;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\Views\TranscriptViews;
use Filament\Facades\Filament;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * Clear progress in the console (since v1.5, Phase 1C, C2–C4): Upload · Transcribing (parts, time left) · Improving (%
 * of lines) on the recording page and the Library row; the transcript readable while its view is made, with a slim bar.
 */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->admin = $this->signInAsAdmin();
    app(CredentialVault::class)->put('anthropic', 'api_key', 'sk-ant-test-1234567890');
});

function progressRecording(object $test, SessionStatus $status, array $transcript = []): CaptureSession
{
    $session = CaptureSession::factory()->create(['user_id' => $test->admin->id, 'status' => $status, 'language' => 'ur', 'duration_ms' => 300_000, 'title' => 'Laptop call']);
    if ($transcript !== []) {
        Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => 'groq', 'language' => 'ur', ...$transcript]);
    }

    return $session->fresh();
}

/** The transcript done, its Roman Urdu view being made with [$done] of 10 lines. */
function improvingRecording(object $test, int $done): CaptureSession
{
    $segments = array_map(fn (int $i): array => ['channel' => 1, 'speaker' => 'Me', 'start_ms' => $i * 1000, 'end_ms' => $i * 1000 + 900, 'text' => 'آپ کیسے ہیں'], range(0, 9));
    $session = progressRecording($test, SessionStatus::Completed, ['status' => TranscriptStatus::Completed, 'text' => 'x', 'segments' => $segments]);
    $final = $session->finalTranscript;
    $final->forceFill(['meta' => ['views' => ['status' => 'making', 'fingerprint' => TranscriptViews::fingerprint($final), 'started_at' => now()->toAtomString(), 'lines_done' => $done, 'lines_total' => 10]]])->save();

    return $session->fresh();
}

it('shows the three steps with their numbers while a recording uploads, is transcribed and improved', function () {
    $uploading = progressRecording($this, SessionStatus::Uploading);
    Recording::query()->create(['capture_session_id' => $uploading->id, 'role' => RecordingRole::Primary, 'mime' => 'audio/mp4', 'declared_size_bytes' => 4_000_000,
        'sha256' => str_repeat('a', 64), 'chunk_size_bytes' => 1_000_000, 'total_chunks' => 4, 'received_chunks' => [0, 1, 2], 'upload_status' => UploadStatus::Pending]);
    $progress = RecordingProgress::for($uploading->fresh());
    expect($progress['active'])->toBe('upload')->and($progress['summary'])->toBe('Uploading 75%')
        ->and(array_column($progress['steps'], 'state'))->toBe(['active', 'waiting', 'waiting'])
        ->and(array_column($progress['steps'], 'label'))->toBe(['Uploading', 'Transcribe', 'Improve'])
        ->and($progress['steps'][2]['detail'])->toBe('Roman Urdu');

    $parts = progressRecording($this, SessionStatus::Processing, ['status' => TranscriptStatus::Processing, 'submitted_at' => now(), 'meta' => ['progress' => ['chunks_done' => 1, 'chunks_total' => 3]]]);
    $progress = RecordingProgress::for($parts);
    expect($progress['active'])->toBe('transcribe')->and($progress['summary'])->toBe('Transcribing · part 2 of 3')
        ->and(array_column($progress['steps'], 'label'))->toBe(['Uploaded', 'Transcribing', 'Improve'])
        ->and($progress['steps'][1]['percent'])->toBe(33);

    $improving = improvingRecording($this, 6);
    expect(RecordingProgress::for($improving))->toMatchArray(['active' => 'improve', 'summary' => 'Improving Roman Urdu… 60%'])
        ->and(RecordingProgress::improvingBar($improving))->toBe(['label' => 'Improving Roman Urdu… 60%', 'percent' => 60])
        ->and(array_column(RecordingProgress::for($improving)['steps'], 'label'))->toBe(['Uploaded', 'Transcribed', 'Improving']);

    $done = progressRecording($this, SessionStatus::Completed, ['status' => TranscriptStatus::Completed, 'text' => 'x', 'segments' => []]);
    expect(RecordingProgress::for($done))->toBeNull();
});

it('gives the time left once the engine has a measure on this server', function () {
    $earlier = progressRecording($this, SessionStatus::Completed, ['status' => TranscriptStatus::Completed, 'submitted_at' => now()->subHour(), 'completed_at' => now()->subHour()->addSeconds(100)]);
    expect($earlier->duration_ms)->toBe(300_000);
    $parts = progressRecording($this, SessionStatus::Processing, ['status' => TranscriptStatus::Processing, 'submitted_at' => now(), 'meta' => ['progress' => ['chunks_done' => 1, 'chunks_total' => 3]]]);

    // 3 s of audio a second: 200 s of audio left is about 67 s.
    expect(RecordingProgress::for($parts)['summary'])->toBe('Transcribing · part 2 of 3 · about 1 min left')
        // The phone's rounding: to 5 s under a minute, then whole minutes.
        ->and(RecordingProgress::timeLeft(45))->toBe('about 45 s left')->and(RecordingProgress::timeLeft(17))->toBe('about 20 s left')
        ->and(RecordingProgress::timeLeft(2))->toBe('about 5 s left')->and(RecordingProgress::timeLeft(150))->toBe('about 3 min left')
        ->and(RecordingProgress::timeLeft(null))->toBeNull();
});

it('puts the moving step in the Library row and keeps the list polling while the view is made', function () {
    improvingRecording($this, 3);

    $html = $this->panelComponent(ListCaptureSessions::class)->html();
    expect($html)->toContain('el-steps-compact')->toContain('Improving Roman Urdu… 30%')->toContain('wire:poll.5s');
});

it('shows the steps on the recording page, then the readable transcript with a slim bar that stays in place when the view is in', function () {
    $session = progressRecording($this, SessionStatus::Processing, ['status' => TranscriptStatus::Processing, 'submitted_at' => now()]);
    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
    expect($page->html())->toContain('el-steps')->toContain('Uploaded')->toContain('Transcribing')->toContain('Improve')->toContain('aria-current="step"')
        ->toContain('Transcribing. This page updates by itself')->toContain('Notify me when ready');

    $improving = improvingRecording($this, 6);
    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $improving->id]);
    expect($page->html())->toContain('Improving Roman Urdu… 60%')->toContain('wire:poll.5s="refreshWork"')->toContain('aria-valuenow="60"');

    // The view is made: the bar says so in the same place and the player gets the view.
    $final = $improving->finalTranscript;
    $final->forceFill(['meta' => ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint($final), 'model' => 'claude-sonnet-5-5', 'made_at' => now()->toAtomString(), 'kept' => [],
        'roman' => array_fill_keys(array_map('strval', range(0, 9)), 'aap kaise hain'), 'urdu' => [], 'english' => []]]])->save();
    $page->call('refreshWork')->assertDispatched('el-transcript-updated');
    expect($page->html())->toContain('Roman Urdu is ready')->toContain('aria-valuenow="100"');
});
