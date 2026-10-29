<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptErrorCode;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\EngineFallbackJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\LanguageRouter;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * Why transcriptions failed on production (v1.3 audit) and what happens now: a provider that heard no speech is
 * "No audio", and any other provider failure hands the final transcript to the next engine of the routing table.
 */

beforeEach(function () {
    [$this->device] = $this->enroll(platform: 'android');
    app(SettingsRepository::class)->set('routing.final', ['ur' => ['assemblyai', 'groq', 'elevenlabs'], 'en' => ['assemblyai', 'groq']]);
});

function failingTranscript(object $test, string $provider = 'assemblyai', string $language = 'ur'): Transcript
{
    $session = CaptureSession::factory()->create([
        'user_id' => $test->device->user_id, 'device_id' => $test->device->id, 'language' => $language, 'status' => SessionStatus::Processing,
    ]);

    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => $provider, 'status' => TranscriptStatus::Processing, 'language' => $language]);
}

describe('no spoken audio', function () {
    it('stores AssemblyAI "no spoken audio" as No audio and tries no other engine', function () {
        Queue::fake();
        app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test');
        $transcript = failingTranscript($this);

        app(TranscriptStore::class)->fail(
            $transcript,
            TranscriptErrorCode::rejectedMessage('language_detection cannot be performed on files with no spoken audio.'),
            TranscriptErrorCode::ProviderRejected,
        );

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('no_audio')
            ->and($transcript->error)->toBe('No audio was captured in this recording.')
            ->and($transcript->captureSession->hasNoAudio())->toBeTrue();
        Queue::assertNotPushed(EngineFallbackJob::class);
    });

    it('recognises the ways engines say there is no speech, and only those', function (string $reason, bool $silent) {
        expect(TranscriptErrorCode::saysNoSpeech($reason))->toBe($silent);
    })->with([
        ['language_detection cannot be performed on files with no spoken audio.', true],
        ['No speech detected in the audio', true],
        ['The audio file is empty', true],
        ['File contains no audio', true],
        ['Invalid API key', false],
        ['Transcoding failed. File type audio/ogg', false],
        ['Unsupported language: ur', false],
        ['no audio codec found', false],
    ]);
});

describe('the next engine', function () {
    it('hands a rejected Urdu transcript to the next engine with a key and remembers the first', function () {
        Queue::fake();
        app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_key');
        app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test');
        $transcript = failingTranscript($this);

        app(TranscriptStore::class)->fail($transcript, TranscriptErrorCode::rejectedMessage('Unsupported audio'), TranscriptErrorCode::ProviderRejected);
        Queue::assertPushed(EngineFallbackJob::class, 1);
        (new EngineFallbackJob($transcript->id))->handle(app(LanguageRouter::class));

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Pending)
            ->and($transcript->provider)->toBe('groq')
            ->and($transcript->error)->toBeNull()
            ->and($transcript->meta['route']['used'])->toBe('groq')
            ->and($transcript->meta['route']['fallback'])->toBeTrue()
            ->and($transcript->meta['route']['tried'][0]['engine'])->toBe('assemblyai')
            ->and($transcript->meta['route']['tried'][0]['code'])->toBe('provider_rejected')
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Processing);
        Queue::assertPushed(TranscribeFinalJob::class, fn (TranscribeFinalJob $job) => $job->transcriptId === $transcript->id);
    });

    it('never goes back to an engine it already tried, and says which ones failed when none is left', function () {
        Queue::fake();
        app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_key');
        app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test');
        $transcript = failingTranscript($this);
        $router = app(LanguageRouter::class);

        app(TranscriptStore::class)->failWith($transcript, TranscriptErrorCode::ProviderUnreachable);
        (new EngineFallbackJob($transcript->id))->handle($router);
        expect($transcript->refresh()->provider)->toBe('groq');

        $transcript->update(['status' => TranscriptStatus::Processing]);
        app(TranscriptStore::class)->fail($transcript, TranscriptErrorCode::rejectedMessage('Request too large'), TranscriptErrorCode::ProviderRejected);
        (new EngineFallbackJob($transcript->id))->handle($router);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->provider)->toBe('groq')
            ->and($transcript->error)->toContain('Tried: AssemblyAI, Groq')
            ->and(array_column($transcript->meta['route']['tried'], 'engine'))->toBe(['assemblyai', 'groq']);
        Queue::assertPushed(TranscribeFinalJob::class, 1);
    });

    it('leaves the limit, missing recordings and alternates alone', function () {
        Queue::fake();
        app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test');
        $budget = failingTranscript($this);
        app(TranscriptStore::class)->failWith($budget, TranscriptErrorCode::BudgetExceeded);
        $missing = failingTranscript($this);
        app(TranscriptStore::class)->failWith($missing, TranscriptErrorCode::RecordingMissing);
        $alternate = failingTranscript($this);
        $alternate->update(['kind' => TranscriptKind::Alternate]);
        app(TranscriptStore::class)->failWith($alternate->refresh(), TranscriptErrorCode::ProviderUnreachable);

        Queue::assertNotPushed(EngineFallbackJob::class);
    });

    it('does nothing for a transcript that was retried or deleted meanwhile', function () {
        app(CredentialVault::class)->put('groq', 'api_key', 'gsk_test');
        $transcript = failingTranscript($this);
        $transcript->update(['status' => TranscriptStatus::Completed]);

        (new EngineFallbackJob($transcript->id))->handle(app(LanguageRouter::class));
        (new EngineFallbackJob('01JXXXXXXXXXXXXXXXXXXXXXXX'))->handle(app(LanguageRouter::class));

        expect($transcript->refresh()->provider)->toBe('assemblyai')->and($transcript->status)->toBe(TranscriptStatus::Completed);
    });
});
