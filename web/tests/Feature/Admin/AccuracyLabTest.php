<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Filament\Pages\AccuracyLab as LabPage;
use App\Filament\Resources\CaptureSessions\Pages\ViewCaptureSession;
use App\Jobs\ScoreAccuracyJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\Accuracy\AccuracyLab;
use App\Services\Accuracy\ErrorRates;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Data\NormalizedTranscript;
use App\Stt\Data\Segment;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

/*
 * Accuracy Lab (v1.2, owner only): engines scored against the owner's reference transcripts; "Use the winners" puts
 * the best engine first for each language.
 */

function labRun(CaptureSession $session, string $provider, string $text, int $seconds): Transcript
{
    $run = $session->allTranscripts()->create(['kind' => TranscriptKind::Trial, 'provider' => $provider, 'status' => TranscriptStatus::Completed,
        'language' => 'ur', 'text' => $text, 'meta' => ['comparison' => true],
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => $text]]]);
    $run->forceFill(['created_at' => now()->subSeconds($seconds), 'completed_at' => now()])->save();

    return $run;
}

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('counts the words and letters an engine got wrong, after folding case, punctuation and Urdu spelling', function () {
    expect(ErrorRates::wer('one two three four', 'one too three four'))->toBe(0.25)
        ->and(ErrorRates::wer('one two three four', 'one two four'))->toBe(0.25)
        ->and(ErrorRates::wer('one two', 'one two extra words'))->toBe(1.0)
        ->and(ErrorRates::wer('Kal ki MEETING, theek?', 'kal ki meeting theek'))->toBe(0.0)
        ->and(ErrorRates::wer('ميں کل آؤں گا', 'میں کل آؤں گا'))->toBe(0.0)
        ->and(ErrorRates::cer('abcd', 'abed'))->toBe(0.25)
        ->and(ErrorRates::wer('', ''))->toBe(0.0)
        ->and(ErrorRates::words(str_repeat('word ', ErrorRates::MAX_WORDS + 50)))->toHaveCount(ErrorRates::MAX_WORDS);
});

it('scores each comparison run against the reference and names the best engine per language', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'میں کل invoice بھیجوں گا']],
        ['status' => 'completed', 'language' => 'ur', 'duration_ms' => 600_000], transcript: ['language' => 'ur']);
    app(AccuracyLab::class)->setReference($session, 'میں کل invoice بھیجوں گا');
    Queue::assertPushed(ScoreAccuracyJob::class);
    labRun($session, 'groq', 'میں کل invoice بھیجوں گا', 60);
    labRun($session, 'assemblyai', 'میں کل انوائس بھیجوں', 120);

    (new ScoreAccuracyJob($session->id))->handle(app(AccuracyLab::class));

    $report = app(AccuracyLab::class)->report($admin->current_workspace_id);
    $urdu = collect($report['languages'])->firstWhere('language', 'ur');
    expect($urdu['winner'])->toBe('groq')
        ->and($urdu['engines'][0])->toMatchArray(['provider' => 'groq', 'recordings' => 1, 'wer' => 0.0, 'seconds_per_audio_minute' => 6.0])
        ->and($urdu['engines'][1]['wer'])->toBe(0.4)
        ->and($report['recordings'][0]['scores'])->toHaveKeys(['groq', 'assemblyai']);

    $changed = app(AccuracyLab::class)->useWinners($admin->current_workspace_id);
    expect($changed[0]['language'])->toBe('Urdu')
        ->and(app(SettingsRepository::class)->get('routing.final')['ur'][0])->toBe('groq');
});

it('scores a comparison run of a reference recording as soon as it is done', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello there']], ['status' => 'completed']);
    $session->forceFill(['reference_text' => 'Hello there', 'reference_set_at' => now()])->save();
    $run = $session->allTranscripts()->create(['kind' => TranscriptKind::Trial, 'provider' => 'groq', 'status' => TranscriptStatus::Processing, 'language' => 'en']);

    app(TranscriptStore::class)->complete($run, new NormalizedTranscript('Me: Hello there', [new Segment(1, 'Me', 0, 1000, 'Hello there')], 'en'));

    Queue::assertPushed(ScoreAccuracyJob::class, fn (ScoreAccuracyJob $job): bool => $job->sessionId === $session->id);
});

it('lets the owner set the reference on the recording page and shows the Lab', function () {
    Queue::fake();
    $admin = $this->signInAsAdmin();
    $session = $this->sessionWithTranscript($admin, [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Helo thare']], ['status' => 'completed']);

    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])
        ->callAction('accuracy_reference', data: ['reference' => 'Hello there'])
        ->assertNotified('Reference saved. Run "Compare engines" to score the engines on this recording.');
    expect($session->fresh()->reference_text)->toBe('Hello there');

    $this->get(LabPage::getUrl())->assertOk()->assertSee('How to test the engines')->assertSee($session->displayTitle());
    $this->panelComponent(ViewCaptureSession::class, ['record' => $session->id])->callAction('accuracy_reference_clear');
    expect($session->fresh()->reference_text)->toBeNull();
});

it('is for the owner only', function () {
    $this->actingAs($this->makeRegularUser(), 'web');

    $this->get('/admin/accuracy-lab')->assertForbidden();
});
