<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\EngineFallbackJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\Transcript;
use App\Services\ActivityFeed;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\EngineWait;
use App\Services\FinalTranscriptCoordinator;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Audio\AudioPrep;
use App\Stt\LanguageRouter;
use App\Stt\SttManager;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Since v1.5: Urdu or mixed speech never falls back to a weaker engine. A final transcript of an Urdu or Auto recording
 * whose engine is rate limited or down waits for that engine (EngineWait) and goes to the next one only after the
 * wait, marked as written by a backup engine; an engine that refuses the recording hands it on at once. English keeps
 * the immediate fallback.
 */

beforeEach(function () {
    Queue::fake();
    [$this->device, $this->token] = $this->enroll(platform: 'android');
    $vault = app(CredentialVault::class);
    $vault->put('groq', 'api_key', 'gsk_test_key_1234567890');
    $vault->put('assemblyai', 'api_key', 'aai_test_key_1234567890');
    app(SettingsRepository::class)->set('routing.final', [
        'ur' => ['groq', 'assemblyai'], 'auto' => ['groq', 'assemblyai'], 'en' => ['groq', 'assemblyai'],
    ]);
    // No ffmpeg: Groq gets the whole file in one request.
    app()->instance(AudioPrep::class, new class(app(SettingsRepository::class)) extends AudioPrep
    {
        public function available(): bool
        {
            return false;
        }
    });
    $this->groqCalls = 0;
    $this->assemblyCalls = 0;
    $this->groq = [];
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), 'api.groq.com')) {
            $this->groqCalls++;

            return array_shift($this->groq) ?? Http::response(['error' => ['message' => 'no answer queued']], 500);
        }
        $this->assemblyCalls++;

        return Http::response(['error' => 'Unsupported audio'], 400);
    });
});

/** Groq's answer when the organisation is over its limits. */
function engineWaitRateLimited(?int $retryAfter = null): mixed
{
    return Http::response(
        ['error' => ['message' => 'Rate limit reached for model `whisper-large-v3` on audio seconds per hour (ASPH): Limit 7200, Used 7190. Please try again in 3m12s.', 'type' => 'audio_seconds', 'code' => 'rate_limit_exceeded']],
        429,
        $retryAfter !== null ? ['retry-after' => (string) $retryAfter] : [],
    );
}

function engineWaitHeard(string $text = 'آپ کل کی meeting reschedule کر دیں'): mixed
{
    return Http::response(['task' => 'transcribe', 'language' => 'urdu', 'duration' => 30.0, 'text' => $text,
        'segments' => [['id' => 0, 'start' => 1.0, 'end' => 3.5, 'text' => $text, 'avg_logprob' => -0.2, 'compression_ratio' => 1.3, 'no_speech_prob' => 0.01]], 'words' => []], 200);
}

function engineWaitTranscript(object $test, ?string $language = 'ur'): Transcript
{
    $session = $test->makeReadySession($test->device, Fixtures::audio(4000), ['language' => $language, 'final_provider' => 'groq']);

    return $session->transcripts()->create([
        'kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Pending, 'language' => $language,
        'meta' => ['route' => ['used' => 'groq', 'wanted' => 'groq', 'language' => $language, 'fallback' => false]],
    ]);
}

/** Runs the final job as the queue worker does, as the run scheduled for [$token] when given. */
function engineWaitRun(Transcript $transcript, ?string $token = null): Transcript
{
    (new TranscribeFinalJob($transcript->id, $token))->handle(app(SttManager::class), app(ChunkedUploadService::class), app(TranscriptStore::class));

    return $transcript->refresh();
}

/** The run EngineWait queued last for [$transcript]. */
function engineWaitScheduled(Transcript $transcript): ?TranscribeFinalJob
{
    return Queue::pushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job): bool => $job->transcriptId === $transcript->id && $job->retryToken !== null)->last();
}

/** Runs the queued engine fallback, as the worker would. */
function engineWaitFallback(Transcript $transcript): Transcript
{
    Queue::assertPushed(EngineFallbackJob::class, fn (EngineFallbackJob $job): bool => $job->transcriptId === $transcript->id);
    (new EngineFallbackJob($transcript->id))->handle(app(LanguageRouter::class));

    return $transcript->refresh();
}

function engineWaitFailureNotices(Transcript $transcript): int
{
    return $transcript->captureSession->user->notifications()->get()
        ->filter(fn ($n): bool => ActivityFeed::kindOf($n) === 'transcript_failed')->count();
}

describe('waiting for the engine', function () {
    it('keeps an Urdu recording on Groq when Groq is rate limited, and tries Groq again later, never AssemblyAI within the wait', function () {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $transcript = engineWaitTranscript($this);
        $this->groq = [engineWaitRateLimited(), engineWaitRateLimited()];

        engineWaitRun($transcript);

        expect($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->provider)->toBe('groq')
            ->and($transcript->error)->toBeNull()
            ->and($transcript->meta['route']['retry_at'])->toBe('2026-11-04T10:02:00+00:00')
            ->and($transcript->meta['route']['retry_reason'])->toBe('rate_limited')
            ->and($transcript->meta['route']['wait_started_at'])->toBe('2026-11-04T10:00:00+00:00')
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Processing);
        Queue::assertNotPushed(EngineFallbackJob::class);
        $scheduled = engineWaitScheduled($transcript);
        expect($scheduled?->retryToken)->toBe('2026-11-04T10:02:00+00:00')
            ->and(Carbon::instance($scheduled->delay)->utc()->toAtomString())->toBe('2026-11-04T10:02:00+00:00');

        // The scheduled run tries Groq again; the next wait doubles.
        $this->travelTo(Carbon::parse('2026-11-04 10:02:00'));
        engineWaitRun($transcript, $scheduled->retryToken);

        expect($this->groqCalls)->toBe(2)
            ->and($this->assemblyCalls)->toBe(0)
            ->and($transcript->provider)->toBe('groq')
            ->and($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->meta['route']['retry_at'])->toBe('2026-11-04T10:06:00+00:00');
        Queue::assertNotPushed(EngineFallbackJob::class);
    });

    it('waits as long as the provider asks when it sends Retry-After', function () {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $transcript = engineWaitTranscript($this);
        $this->groq = [engineWaitRateLimited(192)];

        engineWaitRun($transcript);

        expect($transcript->meta['route']['retry_at'])->toBe('2026-11-04T10:03:12+00:00');
    });

    it('treats an Auto recording like an Urdu one', function () {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $transcript = engineWaitTranscript($this, null);
        $this->groq = [Http::response(['error' => ['message' => 'Service Unavailable']], 503)];

        engineWaitRun($transcript);

        expect($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->provider)->toBe('groq')
            ->and($transcript->meta['route']['retry_reason'])->toBe('unavailable');
        Queue::assertNotPushed(EngineFallbackJob::class);
    });

    it('waits when the engine cannot be reached, timed out or the run was interrupted', function () {
        $transcript = engineWaitTranscript($this);
        foreach ([TranscriptErrorCode::ProviderUnreachable, TranscriptErrorCode::ProviderTimeout, TranscriptErrorCode::Interrupted] as $n => $code) {
            $transcript->update(['status' => TranscriptStatus::Processing]);
            app(TranscriptStore::class)->failWith($transcript, $code);
            expect($transcript->refresh()->status)->toBe(TranscriptStatus::Pending, $code->value)
                ->and($transcript->meta['route']['retry_reason'])->toBe('unavailable')
                ->and($transcript->meta['route']['waits'])->toBe($n + 1);
        }

        // A crashed run (the queue gave up on it) waits too.
        $transcript->update(['status' => TranscriptStatus::Processing]);
        (new TranscribeFinalJob($transcript->id))->failed(new RuntimeException('worker died'));
        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->meta['route']['waits'])->toBe(4)
            ->and(engineWaitFailureNotices($transcript))->toBe(0);
        Queue::assertNotPushed(EngineFallbackJob::class);
    });

    it('hands the recording to the next engine as a backup once the wait is over', function () {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $transcript = engineWaitTranscript($this);
        $this->groq = array_fill(0, 40, engineWaitRateLimited());

        engineWaitRun($transcript);
        $runs = 1;
        while ($transcript->status === TranscriptStatus::Pending && ($job = engineWaitScheduled($transcript)) !== null && $job->retryToken === ($transcript->meta['route']['retry_at'] ?? null)) {
            $this->travelTo(Carbon::parse($job->retryToken));
            engineWaitRun($transcript, $job->retryToken);
            $runs++;
        }

        // 2, 4, 8 and 16 minutes, then every 30 minutes until six hours after the first failure: Groq only.
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and(now()->toAtomString())->toBe('2026-11-04T16:00:00+00:00')
            ->and($runs)->toBe(16)
            ->and($this->groqCalls)->toBe(16)
            ->and($this->assemblyCalls)->toBe(0)
            ->and(engineWaitFailureNotices($transcript))->toBe(0);

        engineWaitFallback($transcript);

        expect($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->provider)->toBe('assemblyai')
            ->and($transcript->meta['route']['backup'])->toBeTrue()
            ->and($transcript->meta['route']['backup_reason'])->toBe('waited')
            ->and($transcript->meta['route']['fallback'])->toBeTrue()
            ->and($transcript->meta['route']['tried'][0]['engine'])->toBe('groq')
            ->and($transcript->meta['route']['tried'][0]['transient'])->toBe('rate_limited')
            ->and($transcript->meta['route'])->not->toHaveKeys(['retry_at', 'retry_reason', 'transient'])
            ->and(EngineWait::fields($transcript))->toBe(['backup_engine' => true, 'backup_reason' => 'waited', 'retry_at' => null, 'retry_reason' => null]);
    });

    it('lets the console choose how long Urdu waits, and 0 hands it on at once', function () {
        app(SettingsRepository::class)->set('routing.urdu_wait_hours', 0);
        $transcript = engineWaitTranscript($this);
        $this->groq = [engineWaitRateLimited()];

        engineWaitRun($transcript);
        expect($transcript->status)->toBe(TranscriptStatus::Failed);
        engineWaitFallback($transcript);

        expect($transcript->provider)->toBe('assemblyai')
            ->and($transcript->meta['route']['backup_reason'])->toBe('waited');
    });

    it('gives the next engine no new wait of its own after the first one ran out', function () {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $transcript = engineWaitTranscript($this);
        $transcript->update(['meta' => ['route' => ['used' => 'groq', 'wanted' => 'groq', 'wait_started_at' => '2026-11-04T03:00:00+00:00', 'waits' => 15]]]);
        $this->groq = [engineWaitRateLimited()];

        engineWaitRun($transcript);
        engineWaitFallback($transcript);
        expect($transcript->provider)->toBe('assemblyai')->and($transcript->meta['route']['wait_started_at'])->toBe('2026-11-04T03:00:00+00:00');

        // AssemblyAI is down as well: no second six hours of waiting, it fails at once and goes on to the next engine.
        $transcript->update(['status' => TranscriptStatus::Processing]);
        app(TranscriptStore::class)->failWith($transcript->refresh(), TranscriptErrorCode::ProviderUnreachable);
        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Failed);
    });
});

describe('refusals and other languages', function () {
    it('hands a recording Groq refuses to the next engine at once, marked as a backup', function () {
        $transcript = engineWaitTranscript($this);
        $this->groq = [Http::response(['error' => ['message' => 'file must be one of the following types: [flac mp3 mp4 mpeg mpga m4a ogg opus wav webm]', 'type' => 'invalid_request_error']], 400)];

        engineWaitRun($transcript);
        expect($transcript->status)->toBe(TranscriptStatus::Failed);
        engineWaitFallback($transcript);

        expect($transcript->provider)->toBe('assemblyai')
            ->and($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->meta['route']['backup'])->toBeTrue()
            ->and($transcript->meta['route']['backup_reason'])->toBe('rejected')
            ->and($transcript->meta['route'])->not->toHaveKey('wait_started_at');
        Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job): bool => $job->transcriptId === $transcript->id && $job->retryToken === null);
    });

    it('still hands an English recording to the next engine at once when Groq is rate limited', function () {
        $transcript = engineWaitTranscript($this, 'en');
        $this->groq = [engineWaitRateLimited(60)];

        engineWaitRun($transcript);
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('provider_rejected');
        engineWaitFallback($transcript);

        expect($transcript->provider)->toBe('assemblyai')
            ->and($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->meta['route'])->not->toHaveKey('backup')
            ->and(EngineWait::fields($transcript)['backup_engine'])->toBeFalse();
    });

    it('says "Transcription failed" once, when every engine has failed, and never while it waits', function () {
        $transcript = engineWaitTranscript($this);
        $this->groq = [engineWaitRateLimited()];

        engineWaitRun($transcript);
        expect($transcript->status)->toBe(TranscriptStatus::Pending)->and(engineWaitFailureNotices($transcript))->toBe(0);

        // Groq then refuses the recording; AssemblyAI refuses it too.
        $this->travelTo(Carbon::parse($transcript->meta['route']['retry_at']));
        $this->groq = [Http::response(['error' => ['message' => 'Request Entity Too Large']], 413)];
        engineWaitRun($transcript, $transcript->meta['route']['retry_at']);
        engineWaitFallback($transcript);
        expect($transcript->provider)->toBe('assemblyai')->and(engineWaitFailureNotices($transcript))->toBe(0);

        engineWaitRun($transcript);
        (new EngineFallbackJob($transcript->id))->handle(app(LanguageRouter::class));

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error)->toContain('Tried: Groq Whisper large-v3, AssemblyAI')
            ->and($this->assemblyCalls)->toBe(1)
            ->and(engineWaitFailureNotices($transcript))->toBe(1)
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Failed);
    });
});

describe('finishing and duplicates', function () {
    it('clears the wait when Groq answers', function () {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $transcript = engineWaitTranscript($this);
        $this->groq = [engineWaitRateLimited(), engineWaitHeard()];

        engineWaitRun($transcript);
        $this->travelTo(Carbon::parse('2026-11-04 10:02:00'));
        engineWaitRun($transcript, engineWaitScheduled($transcript)?->retryToken);

        expect($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->provider)->toBe('groq')
            ->and($transcript->meta['route'])->not->toHaveKeys(['retry_at', 'retry_reason', 'waits', 'wait_started_at', 'backup'])
            ->and(EngineWait::fields($transcript))->toBe(['backup_engine' => false, 'backup_reason' => null, 'retry_at' => null, 'retry_reason' => null])
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Completed);
    });

    it('runs nothing before the scheduled time, whoever queues the transcript again', function () {
        Carbon::setTestNow('2026-11-04 10:00:00');
        $transcript = engineWaitTranscript($this);
        $this->groq = [engineWaitRateLimited(3600), engineWaitHeard()];

        engineWaitRun($transcript);
        expect($transcript->meta['route']['retry_at'])->toBe('2026-11-04T11:00:00+00:00');
        $queued = fn (): int => Queue::pushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job): bool => $job->transcriptId === $transcript->id)->count();
        expect($queued())->toBe(1);

        // 45 minutes later: stt:reconcile leaves it alone, and a run queued by anyone else does nothing.
        $this->travelTo(Carbon::parse('2026-11-04 10:45:00'));
        $this->artisan('stt:reconcile')->assertSuccessful();
        app(FinalTranscriptCoordinator::class)->maybeStart($transcript->captureSession);
        engineWaitRun($transcript);
        engineWaitRun($transcript, '2026-11-04T10:02:00+00:00');
        expect($queued())->toBe(2) // the coordinator's run, which did nothing
            ->and($this->groqCalls)->toBe(1)
            ->and($transcript->status)->toBe(TranscriptStatus::Pending);

        // A scheduled run that the queue lost is queued again once it is well overdue.
        $this->travelTo(Carbon::parse('2026-11-04 11:11:00'));
        $this->artisan('stt:reconcile')->assertSuccessful();
        expect($queued())->toBe(3);
        engineWaitRun($transcript);
        expect($transcript->status)->toBe(TranscriptStatus::Completed)->and($this->groqCalls)->toBe(2);

        // The scheduled run arriving late finds the work done.
        engineWaitRun($transcript, '2026-11-04T11:00:00+00:00');
        expect($this->groqCalls)->toBe(2);
    });
});
