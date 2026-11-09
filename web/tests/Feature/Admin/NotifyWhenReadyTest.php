<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Mail\TranscriptFailedMail;
use App\Mail\TranscriptReadyMail;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Accounts\EmailPreferences;
use App\Services\Library\ReadyNotices;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/* "Notify me when ready" on the console's recording page (since v1.6, P5.4): one email for that recording. */

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
    $this->admin = $this->signInAsAdmin();
    EmailPreferences::set($this->admin, EmailPreferences::TRANSCRIPTION, false);
});

/** A recording of [$owner] being transcribed, and its final transcript. */
function transcribing(User $owner): array
{
    $session = CaptureSession::factory()->create(['user_id' => $owner->id, 'status' => SessionStatus::Processing, 'title' => 'Laptop call']);
    $final = Transcript::query()->create(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => 'groq', 'language' => 'ur',
        'status' => TranscriptStatus::Processing, 'submitted_at' => now()]);

    return [$session->fresh(), $final];
}

it('offers Notify me when ready while the recording is on its way, and says so once asked', function () {
    [$session] = transcribing($this->admin);

    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
    expect($page->html())->toContain('Notify me when ready')->not->toContain('data-notify-asked');

    $page->call('notifyWhenReady')->assertNotified('You will get an email when it is ready');
    expect($page->html())->toContain('You will get an email when it is ready.')->toContain('data-notify-asked')->not->toContain('data-notify-ready')
        ->and(app(ReadyNotices::class)->asked($this->admin, $session))->toBeTrue();
    // A new visit remembers it.
    expect($this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->html())->toContain('data-notify-asked');
});

it('sends one email when the transcript is ready, even with transcript emails off, and only once', function () {
    [$session, $final] = transcribing($this->admin);
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->call('notifyWhenReady');

    $final->update(['status' => TranscriptStatus::Completed, 'text' => 'x', 'segments' => []]);
    $final->update(['status' => TranscriptStatus::Processing]);
    $final->update(['status' => TranscriptStatus::Completed]);

    Mail::assertQueued(TranscriptReadyMail::class, fn (TranscriptReadyMail $mail): bool => $mail->hasTo($this->admin->email) && $mail->asked && $mail->title === 'Laptop call'
        && str_ends_with($mail->url, '/admin/sessions/'.$session->id));
    Mail::assertQueuedCount(1);
    expect(app(ReadyNotices::class)->asked($this->admin, $session))->toBeFalse();
});

it('says in that one email when the transcript could not be made', function () {
    [$session, $final] = transcribing($this->admin);
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->call('notifyWhenReady');

    $final->update(['status' => TranscriptStatus::Failed, 'error' => 'No audio was captured in this recording.', 'error_code' => TranscriptErrorCode::NoAudio->value]);

    Mail::assertQueued(TranscriptFailedMail::class, fn (TranscriptFailedMail $mail): bool => $mail->hasTo($this->admin->email));
    Mail::assertNotQueued(TranscriptReadyMail::class);
});

it('does not send a second email to someone who gets transcript emails anyway', function () {
    EmailPreferences::set($this->admin, EmailPreferences::TRANSCRIPTION, true);
    [$session, $final] = transcribing($this->admin);
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->call('notifyWhenReady');

    $final->update(['status' => TranscriptStatus::Completed, 'text' => 'x', 'segments' => []]);

    Mail::assertQueuedCount(1);
    Mail::assertQueued(TranscriptReadyMail::class, fn (TranscriptReadyMail $mail): bool => ! $mail->asked);
});

it('sends nothing for a recording nobody asked about', function () {
    [, $final] = transcribing($this->admin);

    $final->update(['status' => TranscriptStatus::Completed, 'text' => 'x', 'segments' => []]);

    Mail::assertNothingQueued();
});

it('says a ready recording is ready instead of asking', function () {
    $session = $this->sessionWithTranscript($this->admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello']]);

    $page = $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id]);
    expect($page->html())->not->toContain('Notify me when ready');
    $page->call('notifyWhenReady')->assertNotified('It is ready already');
    expect(app(ReadyNotices::class)->asked($this->admin, $session))->toBeFalse();
});

it('never lets another person ask about someone\'s recording', function () {
    [$theirs, $final] = transcribing($this->makeAdmin());

    // The recording page is not theirs to open: 404 before any action can run.
    $this->get(ViewCaptureSession::getUrl(['record' => $theirs->id]))->assertNotFound();
    expect(fn () => $this->panelComponent(ViewCaptureSession::class, ['record' => $theirs->id])->call('notifyWhenReady'))->toThrow(Exception::class);
    expect(Cache::has('ready-notice:'.$theirs->id))->toBeFalse();
    // Nor through the service: only the owner can ask.
    expect(app(ReadyNotices::class)->ask($this->admin, $theirs))->toBeFalse();

    $final->update(['status' => TranscriptStatus::Completed, 'text' => 'x', 'segments' => []]);
    Mail::assertNotQueued(TranscriptReadyMail::class, fn (TranscriptReadyMail $mail): bool => $mail->hasTo($this->admin->email));
});

it('limits how often one person can ask', function () {
    [$session] = transcribing($this->admin);
    RateLimiter::increment('notify-ready:'.$this->admin->id, 60, 20);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->call('notifyWhenReady')
        ->assertNotified('Too many requests. Try again in a minute.');
    expect(app(ReadyNotices::class)->asked($this->admin, $session))->toBeFalse();
});
