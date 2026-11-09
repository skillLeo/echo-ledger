<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\CaptureSessions\Widgets\FailedUploadsWidget;
use App\Filament\Support\FailedUpload;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\User;
use App\Services\ChunkedUploadService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/* "Failed uploads (N)" explained (since v1.6, P5.3): what went wrong in plain words, with Retry or Delete. */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    $this->isolatedStorage();
    $this->admin = $this->signInAsAdmin();
});

/** A recording of [$owner] whose upload did not finish. */
function failedUpload(User $owner, array $session = [], ?string $error = null): CaptureSession
{
    $capture = CaptureSession::factory()->create(['user_id' => $owner->id, 'status' => SessionStatus::Uploading, ...$session]);
    $uploads = app(ChunkedUploadService::class);
    $recording = $uploads->createRecording($capture, ['role' => 'primary', 'mime' => 'audio/ogg', 'channels' => 1, 'size_bytes' => 4096, 'sha256' => str_repeat('a', 64)], 1024 * 1024);
    $uploads->discard($recording, $error ?? ChunkedUploadService::NOT_FINISHED);

    return $capture->fresh();
}

/** A recording of [$owner] with its audio on the server whose transcript failed with [$code]. */
function failedTranscript(object $test, User $owner, TranscriptErrorCode $code, array $session = []): CaptureSession
{
    $capture = $test->sessionWithTranscript($owner, [], ['status' => SessionStatus::Failed, ...$session], 'final', [
        'status' => TranscriptStatus::Failed, 'error' => $code->message(), 'error_code' => $code->value,
    ]);
    $test->readyRecording($capture);

    return $capture->fresh();
}

it('explains a device upload that did not finish and offers only Delete', function () {
    $device = Device::factory()->create(['user_id' => $this->admin->id, 'name' => 'Galaxy S25 Ultra']);
    $session = failedUpload($this->admin, ['device_id' => $device->id, 'source_device' => 'phone']);

    expect(FailedUpload::for($session))->toMatchArray(['title' => 'The upload did not finish', 'retry' => null]);
    $page = $this->panelComponent(ListCaptureSessions::class)->set('activeTab', 'failed')->assertCanSeeTableRecords([$session]);
    expect($page->html())->toContain('The upload did not finish.')->toContain('Galaxy S25 Ultra sends it again by itself');
    $page->assertTableActionVisible('deleteFailed', $session)
        ->assertTableActionHidden('retryFailed', $session)->assertTableActionHidden('retryUpload', $session);
});

it('offers Retry for an upload from this browser: the file picker carries on where it stopped', function () {
    $session = failedUpload($this->admin, ['source_device' => 'web'], ChunkedUploadService::VERIFICATION_FAILED);

    expect(FailedUpload::for($session))->toMatchArray(['title' => 'The file arrived damaged', 'retry' => FailedUpload::RETRY_UPLOAD]);
    $this->panelComponent(ListCaptureSessions::class)->set('activeTab', 'failed')
        ->assertTableActionVisible('retryUpload', $session)->assertTableActionHidden('retryFailed', $session);
});

it('explains a failed transcription and transcribes it again with Retry', function () {
    Queue::fake();
    $session = failedTranscript($this, $this->admin, TranscriptErrorCode::ProviderUnreachable);

    $page = $this->panelComponent(ListCaptureSessions::class)->set('activeTab', 'failed');
    expect($page->html())->toContain('The transcript could not be made.')->toContain('Your audio is safe: press Retry.')
        // Never the engine's own words.
        ->not->toContain('The speech provider could not be reached');
    $page->callTableAction('retryFailed', $session)->assertNotified('Transcription queued');

    expect($session->finalTranscript()->first()->status)->toBe(TranscriptStatus::Pending)
        ->and($session->fresh()->status)->toBe(SessionStatus::Processing);
    Queue::assertPushed(TranscribeFinalJob::class, 1);
});

it('says what to do for each reason', function (TranscriptErrorCode $code, string $title, bool $retry) {
    $session = failedTranscript($this, $this->admin, $code);

    expect(FailedUpload::for($session))->toMatchArray(['title' => $title])
        ->and(FailedUpload::for($session)['retry'] !== null)->toBe($retry);
})->with([
    'no audio' => [TranscriptErrorCode::NoAudio, 'No sound was recorded', false],
    'needs a key' => [TranscriptErrorCode::NeedsTranscriptionKey, 'Your plan transcribes with your own key', true],
    'limit' => [TranscriptErrorCode::BudgetExceeded, 'The monthly transcription limit was reached', true],
    'missing' => [TranscriptErrorCode::RecordingMissing, 'The audio is missing', false],
    'interrupted' => [TranscriptErrorCode::Interrupted, 'The transcript could not be made', true],
]);

it('deletes a failed recording into Recently deleted', function () {
    $session = failedTranscript($this, $this->admin, TranscriptErrorCode::NoAudio);

    $this->panelComponent(ListCaptureSessions::class)->set('activeTab', 'failed')->callTableAction('deleteFailed', $session);

    expect(CaptureSession::query()->find($session->id))->toBeNull()->and(CaptureSession::withTrashed()->find($session->id)->restorable())->toBeTrue();
});

it('shows "Failed uploads (N) · See why" above the Library while something failed, not inside that view', function () {
    expect(FailedUploadsWidget::canView())->toBeFalse();
    failedUpload($this->admin);
    failedTranscript($this, $this->admin, TranscriptErrorCode::ProviderTimeout);

    expect(FailedUploadsWidget::canView())->toBeTrue()->and(FailedUploadsWidget::count())->toBe(2);
    expect($this->panelComponent(FailedUploadsWidget::class, ['activeTab' => 'all'])->html())->toContain('Failed uploads (2)')->toContain('See why')->toContain('tab=failed')
        ->and($this->panelComponent(FailedUploadsWidget::class, ['activeTab' => 'failed'])->html())->not->toContain('See why');
    expect($this->panelComponent(ListCaptureSessions::class)->html())->toContain('FailedUploadsWidget');
});

it('keeps the other views uncluttered: the reason and buttons only in Failed uploads', function () {
    $session = failedTranscript($this, $this->admin, TranscriptErrorCode::ProviderUnreachable);

    $page = $this->panelComponent(ListCaptureSessions::class);
    expect($page->html())->not->toContain('data-problem');
    $page->assertTableActionHidden('retryFailed', $session)->assertTableActionHidden('deleteFailed', $session);
});

it('never lets another person retry or delete someone\'s failed recording', function () {
    Queue::fake();
    $theirs = failedTranscript($this, $this->makeAdmin(), TranscriptErrorCode::ProviderUnreachable);
    $own = failedTranscript($this, $this->admin, TranscriptErrorCode::ProviderUnreachable);

    $page = $this->panelComponent(ListCaptureSessions::class)->set('activeTab', 'failed')
        ->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$theirs]);
    foreach (['retryFailed', 'deleteFailed'] as $action) {
        try {
            $page->callTableAction($action, $theirs->id);
        } catch (Throwable) {
            // Not a record of this person's table: the action cannot be mounted on it.
        }
    }

    $theirs = unscoped(fn () => CaptureSession::query()->find($theirs->id));
    expect($theirs)->not->toBeNull()->and($theirs->status)->toBe(SessionStatus::Failed)
        ->and(unscoped(fn () => $theirs->finalTranscript()->first())->status)->toBe(TranscriptStatus::Failed);
    Queue::assertNothingPushed();
});
