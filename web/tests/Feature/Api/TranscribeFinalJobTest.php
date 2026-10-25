<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\FinalTranscriptCoordinator;
use App\Services\SettingsRepository;
use App\Services\TranscriptStore;
use App\Stt\Drivers\AssemblyAiDriver;
use App\Stt\SttManager;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device] = $this->enroll();
    $this->audio = Fixtures::audio(4096, 'JOB-AUDIO');
    $this->configureAssemblyAi();
});

/** A pending final transcript for $session, as the coordinator would create it. */
function pendingFinal(CaptureSession $session, string $provider = 'assemblyai'): Transcript
{
    return $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => $provider, 'status' => TranscriptStatus::Pending]);
}

/** Run the job the way the queue worker does. */
function runFinalJob(Transcript $transcript): void
{
    (new TranscribeFinalJob($transcript->id))->handle(app(SttManager::class), app(ChunkedUploadService::class), app(TranscriptStore::class));
}

/** @return array<string, mixed> the JSON body of the first recorded request matching $path */
function sentBody(string $method, string $path): array
{
    foreach (Http::recorded() as [$request]) {
        if ($request->method() === $method && parse_url($request->url(), PHP_URL_PATH) === $path) {
            return $request->data();
        }
    }

    return [];
}

describe('AssemblyAI submission', function () {
    it('uploads the decrypted audio and submits a multichannel job for a stereo recording', function () {
        $log = [];
        $this->fakeAssemblyAi('job_1', null, $log);
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Submitted)
            ->and($transcript->provider_job_id)->toBe('job_1')
            ->and($transcript->attempts)->toBe(1)
            ->and($transcript->submitted_at)->not->toBeNull()
            ->and($transcript->error)->toBeNull()
            ->and($log)->toBe(['POST /v2/upload', 'POST /v2/transcript']);
        $body = sentBody('POST', '/v2/transcript');
        expect($body['audio_url'])->toBe('https://cdn.assemblyai.com/upload/abc')
            ->and($body['multichannel'])->toBeTrue()
            ->and($body['speech_models'])->toBe(['universal-3-5-pro', 'universal-2'])
            ->and($body['punctuate'])->toBeTrue()
            ->and($body['language_detection'])->toBeTrue()
            ->and($body)->not->toHaveKey('language_code');
    });

    it('uploads exactly the plaintext that was recorded', function () {
        $uploaded = null;
        $contentType = null;
        Http::fake(function (HttpRequest $request) use (&$uploaded, &$contentType) {
            if (str_ends_with($request->url(), '/v2/upload')) {
                $uploaded = $request->body(); // read while the stream is still open
                $contentType = $request->header('Content-Type')[0] ?? null;

                return Http::response(['upload_url' => 'https://x/u'], 200);
            }

            return Http::response(['id' => 'job_1'], 200);
        });
        $session = $this->makeReadySession($this->device, $this->audio);

        runFinalJob(pendingFinal($session));

        expect($uploaded)->toBe($this->audio)->and($contentType)->toBe('application/octet-stream');
    });

    it('sends the raw API key, never as a bearer token and never in the body', function () {
        $this->fakeAssemblyAi();
        $session = $this->makeReadySession($this->device, $this->audio);

        runFinalJob(pendingFinal($session));

        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/upload') && $r->hasHeader('Authorization', 'aai_test_key_1234567890'));
        Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/v2/transcript') && $r->hasHeader('Authorization', 'aai_test_key_1234567890')
            && ! str_contains($r->body(), 'aai_test_key_1234567890'));
    });

    it('asks for single-channel transcription for a mono recording', function () {
        $this->fakeAssemblyAi();
        $session = $this->makeReadySession($this->device, $this->audio, [], ['channels' => 1]);

        runFinalJob(pendingFinal($session));

        expect(sentBody('POST', '/v2/transcript')['multichannel'])->toBeFalse();
    });

    it('prefers the session language, then the client language, then auto-detection', function (?string $session, ?string $client, ?string $expected) {
        $this->fakeAssemblyAi();
        $owner = $this->device->user;
        $clientModel = Client::factory()->create(['user_id' => $owner->id, 'language' => $client]);
        $model = $this->makeReadySession($this->device, $this->audio, ['language' => $session, 'client_id' => $clientModel->id]);

        runFinalJob(pendingFinal($model));

        $body = sentBody('POST', '/v2/transcript');
        if ($expected === null) {
            expect($body['language_detection'])->toBeTrue()->and($body)->not->toHaveKey('language_code');
        } else {
            expect($body['language_code'])->toBe($expected)->and($body)->not->toHaveKey('language_detection');
        }
    })->with([
        'session wins' => ['ur', 'fr', 'ur'],
        'client used' => [null, 'fr', 'fr'],
        'none: detect' => [null, null, null],
        'empty strings count as none' => ['', '', null],
    ]);

    it('passes the client\'s keyterms through the policy and caps them', function () {
        $this->fakeAssemblyAi();
        $client = Client::factory()->create(['user_id' => $this->device->user_id]);
        $terms = ['Acme Holdings', 'acme holdings', '  Farah   Khan ', 'X<b>Y', str_repeat('x', 60), 'one two three four five six seven'];
        foreach (range(1, 120) as $i) {
            $terms[] = "Term {$i}";
        }
        foreach ($terms as $term) {
            ClientKeyterm::query()->firstOrCreate(['client_id' => $client->id, 'term' => mb_substr($term, 0, 100)]);
        }
        $session = $this->makeReadySession($this->device, $this->audio, ['client_id' => $client->id]);

        runFinalJob(pendingFinal($session));

        $sent = sentBody('POST', '/v2/transcript')['keyterms_prompt'];
        expect(count($sent))->toBe(100)
            ->and($sent)->toContain('Acme Holdings', 'Farah Khan', 'XbY')
            ->and($sent)->not->toContain('acme holdings', str_repeat('x', 60), 'one two three four five six seven');
        foreach ($sent as $term) {
            expect(mb_strlen($term))->toBeLessThanOrEqual(50)->and(count(explode(' ', $term)))->toBeLessThanOrEqual(6);
        }
        expect(count(array_unique(array_map('mb_strtolower', $sent))))->toBe(count($sent));
    });

    it('omits keyterms when the session has no client', function () {
        $this->fakeAssemblyAi();
        $session = $this->makeReadySession($this->device, $this->audio);

        runFinalJob(pendingFinal($session));

        expect(sentBody('POST', '/v2/transcript'))->not->toHaveKey('keyterms_prompt');
    });

    it('registers a signed callback URL when the server is publicly reachable', function () {
        config(['app.url' => 'https://ledger.example.com']);
        $this->fakeAssemblyAi();
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        $body = sentBody('POST', '/v2/transcript');
        expect($body['webhook_url'])->toBe("https://ledger.example.com/api/webhooks/stt/assemblyai?rid={$transcript->id}")
            ->and($body['webhook_auth_header_name'])->toBe(AssemblyAiDriver::WEBHOOK_HEADER)
            ->and($body['webhook_auth_header_value'])->toBe(AssemblyAiDriver::webhookToken('whsec_test_secret', $transcript->id));
    });

    it('does not register a callback when the server is not reachable from the internet', function (string $url) {
        config(['app.url' => $url]);
        $this->fakeAssemblyAi();
        $session = $this->makeReadySession($this->device, $this->audio);

        runFinalJob(pendingFinal($session));

        expect(sentBody('POST', '/v2/transcript'))->not->toHaveKey('webhook_url');
    })->with(['http://ledger.example.com', 'https://localhost', 'https://echoledger.test', 'https://nas.local', 'https://192.168.1.20', 'https://10.0.0.5', 'https://127.0.0.1']);

    it('does not register a callback when no webhook secret is set', function () {
        config(['app.url' => 'https://ledger.example.com']);
        app(CredentialVault::class)->forget('assemblyai', 'webhook_secret');
        $this->fakeAssemblyAi();
        $session = $this->makeReadySession($this->device, $this->audio);

        runFinalJob(pendingFinal($session));

        expect(sentBody('POST', '/v2/transcript'))->not->toHaveKey('webhook_auth_header_value');
    });

    it('removes every decrypted temp file when it is done', function () {
        $this->fakeAssemblyAi();
        $session = $this->makeReadySession($this->device, $this->audio);

        runFinalJob(pendingFinal($session));

        expect($this->workFiles())->toBe([]);
    });

    it('uses the regional endpoint when configured', function () {
        app(SettingsRepository::class)->set('assemblyai.region', 'eu');
        Http::fake(['api.eu.assemblyai.com/v2/upload' => Http::response(['upload_url' => 'https://x/u'], 200), 'api.eu.assemblyai.com/v2/transcript' => Http::response(['id' => 'job_eu'], 200)]);
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        expect($transcript->refresh()->provider_job_id)->toBe('job_eu');
    });
});

describe('recordings that cannot be transcribed', function () {
    it('fails a recording with no usable audio before calling any provider', function (int $size, bool $fails) {
        $this->fakeAssemblyAi();
        $bytes = Fixtures::audio(max($size, 40));
        $bytes = $size < 40 ? substr($bytes, 0, $size) : $bytes;
        $session = $this->makeReadySession($this->device, $bytes);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        $transcript->refresh();
        if ($fails) {
            expect($transcript->status)->toBe(TranscriptStatus::Failed)
                ->and($transcript->error)->toContain('No audio was captured')
                ->and($session->refresh()->status)->toBe(SessionStatus::Failed);
            Http::assertNothingSent();
        } else {
            expect($transcript->status)->toBe(TranscriptStatus::Submitted);
        }
    })->with([
        'one byte' => [1, true],
        'an Ogg header and nothing else' => [98, true],
        'one byte under the floor' => [1023, true],
        'exactly at the floor' => [1024, false],
        'above the floor' => [4096, false],
    ]);

    it('fails when the primary recording is not ready', function () {
        Http::fake();
        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'status' => SessionStatus::Processing]);
        $session->recordings()->create([
            'role' => 'primary', 'mime' => 'audio/ogg', 'channels' => 2, 'declared_size_bytes' => 5000, 'sha256' => str_repeat('a', 64),
            'chunk_size_bytes' => 5000, 'total_chunks' => 1, 'received_chunks' => [0], 'upload_status' => 'assembling',
        ]);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Failed)->and($transcript->error)->toContain('not ready');
        Http::assertNothingSent();
    });

    it('fails when the session has no recording at all', function () {
        Http::fake();
        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'status' => SessionStatus::Processing]);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Failed);
        Http::assertNothingSent();
    });

    it('fails permanently, without retrying, when the provider is not configured', function () {
        app(CredentialVault::class)->forget('assemblyai', 'api_key');
        Http::fake();
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript); // must not throw: a retry cannot fix a missing credential

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error)->toContain('assemblyai.api_key')
            ->and($session->refresh()->status)->toBe(SessionStatus::Failed);
        Http::assertNothingSent();
        expect($this->workFiles())->toBe([]);
    });
});

describe('provider errors and retries', function () {
    it('fails the transcript with provider_rejected when the provider refuses the upload, without retrying it', function (int $status) {
        Http::fake(['api.assemblyai.com/v2/upload' => Http::response(['error' => 'try later'], $status)]);
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('provider_rejected')
            ->and($transcript->error)->toStartWith('The speech provider could not transcribe this recording: ')
            ->and($transcript->provider_job_id)->toBeNull()
            ->and($session->refresh()->status)->toBe(SessionStatus::Failed)
            ->and($this->workFiles())->toBe([]);
    })->with([500, 502, 503, 429]);

    it('fails the transcript with provider_unreachable when the provider cannot be reached', function () {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('provider_unreachable')
            ->and($transcript->error)->toBe('The speech provider could not be reached. Try again later.')
            ->and($this->workFiles())->toBe([]);
    });

    it('fails the transcript when the submit after the audio upload is refused', function () {
        Http::fake([
            'api.assemblyai.com/v2/upload' => Http::response(['upload_url' => 'https://x/u'], 200),
            'api.assemblyai.com/v2/transcript' => Http::response(['error' => 'overloaded'], 503),
        ]);
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('provider_rejected')
            ->and($transcript->provider_job_id)->toBeNull();
    });

    it('never resubmits a refused transcript on its own, because the first submit may already be billed', function () {
        $uploads = 0;
        Http::fake(function (HttpRequest $request) use (&$uploads) {
            if (parse_url($request->url(), PHP_URL_PATH) === '/v2/upload') {
                $uploads++;

                return Http::response([], 500);
            }

            return Http::response(['id' => 'job_retry'], 200);
        });
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);
        runFinalJob($transcript);

        expect($uploads)->toBe(1)
            ->and($transcript->refresh()->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->provider_job_id)->toBeNull();
    });

    it('marks the transcript and session failed with the reason once the queue gives up', function () {
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);
        $transcript->update(['status' => TranscriptStatus::Processing]);

        (new TranscribeFinalJob($transcript->id))->failed(new RuntimeException(str_repeat('boom ', 1000)));

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error)->toStartWith('boom')
            ->and(mb_strlen($transcript->error))->toBeLessThanOrEqual(2000)
            ->and($session->refresh()->status)->toBe(SessionStatus::Failed);
    });

    it('does not overwrite a finished transcript when a stale failure arrives', function () {
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);
        $transcript->update(['status' => TranscriptStatus::Completed, 'text' => 'done']);

        (new TranscribeFinalJob($transcript->id))->failed(new RuntimeException('late'));

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Completed)->and($transcript->text)->toBe('done');
    });

    it('ignores a failure callback for a transcript that no longer exists', function () {
        (new TranscribeFinalJob('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->failed(new RuntimeException('late'));

        expect(Transcript::query()->count())->toBe(0);
    });

    it('declares retry settings that match the scheduled worker', function () {
        $job = new TranscribeFinalJob('x');

        expect($job->tries)->toBe(3)->and($job->backoff)->toBe([60, 300])->and($job->timeout)->toBe(900);
        expect(collect(file(base_path('routes/console.php')))->contains(fn ($line) => str_contains($line, '--tries=3') && str_contains($line, '--timeout=900')))->toBeTrue();
    });
});

describe('idempotency', function () {
    it('does nothing for a transcript that already has a provider job', function () {
        Http::fake();
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);
        $transcript->update(['status' => TranscriptStatus::Submitted, 'provider_job_id' => 'job_existing']);

        runFinalJob($transcript);
        runFinalJob($transcript);

        Http::assertNothingSent();
        expect($transcript->refresh()->provider_job_id)->toBe('job_existing')->and($transcript->attempts)->toBe(0);
    });

    it('does nothing for finished or device-owned transcripts', function (TranscriptStatus $status) {
        Http::fake();
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);
        $transcript->update(['status' => $status]);

        runFinalJob($transcript);

        Http::assertNothingSent();
        expect($transcript->refresh()->status)->toBe($status);
    })->with([TranscriptStatus::Completed, TranscriptStatus::Failed, TranscriptStatus::AwaitingDevice]);

    it('submits once however many times the job runs after success', function () {
        $log = [];
        $this->fakeAssemblyAi('job_1', null, $log);
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        foreach (range(1, 4) as $_) {
            runFinalJob($transcript);
        }

        expect($log)->toBe(['POST /v2/upload', 'POST /v2/transcript']);
    });

    it('ignores a job for a transcript that was deleted', function () {
        Http::fake();

        (new TranscribeFinalJob('01ARZ3NDEKTSV4RRFFQ69G5FAV'))->handle(app(SttManager::class), app(ChunkedUploadService::class), app(TranscriptStore::class));

        Http::assertNothingSent();
    });
});

describe('the coordinator', function () {
    it('starts one final transcript per session no matter how often it is asked', function () {
        Queue::fake();
        $session = $this->makeReadySession($this->device, $this->audio);
        $coordinator = app(FinalTranscriptCoordinator::class);

        $first = $coordinator->maybeStart($session);
        $second = $coordinator->maybeStart($session);
        $third = $coordinator->maybeStart($session);

        expect($second->id)->toBe($first->id)->and($third->id)->toBe($first->id)->and(Transcript::query()->where('kind', 'final')->count())->toBe(1);
        Queue::assertPushed(TranscribeFinalJob::class, 1);
        expect($session->refresh()->status)->toBe(SessionStatus::Processing);
    });

    it('waits while any declared artifact is still uploading', function () {
        Queue::fake();
        $session = $this->makeReadySession($this->device, $this->audio);
        $session->recordings()->create([
            'role' => 'channel_1', 'mime' => 'audio/wav', 'channels' => 1, 'declared_size_bytes' => 100, 'sha256' => str_repeat('a', 64),
            'chunk_size_bytes' => 100, 'total_chunks' => 1, 'received_chunks' => [], 'upload_status' => 'uploading',
        ]);

        expect(app(FinalTranscriptCoordinator::class)->maybeStart($session))->toBeNull();
        Queue::assertNothingPushed();
    });

    it('does not start without a primary recording', function () {
        Queue::fake();
        $session = CaptureSession::factory()->create(['user_id' => $this->device->user_id]);

        expect(app(FinalTranscriptCoordinator::class)->maybeStart($session))->toBeNull();
    });

    it('restarts a failed transcript from a clean slate', function () {
        Queue::fake();
        $session = $this->makeReadySession($this->device, $this->audio);
        $failed = pendingFinal($session);
        $failed->update(['status' => TranscriptStatus::Failed, 'error' => 'boom', 'attempts' => 3, 'provider_job_id' => 'old', 'provider_deleted_at' => now()]);

        $restarted = app(FinalTranscriptCoordinator::class)->maybeStart($session);

        $restarted->refresh();
        expect($restarted->id)->toBe($failed->id)
            ->and($restarted->status)->toBe(TranscriptStatus::Pending)
            ->and($restarted->error)->toBeNull()
            ->and($restarted->attempts)->toBe(0)
            ->and($restarted->provider_job_id)->toBeNull()
            ->and($restarted->provider_deleted_at)->toBeNull();
        Queue::assertPushed(TranscribeFinalJob::class, 1);
    });

    it('uses the provider chosen at session creation even if the setting changed since', function () {
        Queue::fake();
        $session = $this->makeReadySession($this->device, $this->audio, ['final_provider' => 'elevenlabs']);
        app(SettingsRepository::class)->set('provider.final_transcript', 'assemblyai');

        $transcript = app(FinalTranscriptCoordinator::class)->maybeStart($session);

        expect($transcript->provider)->toBe('elevenlabs');
    });

    it('uses the voice note provider for voice notes', function () {
        Queue::fake();
        app(SettingsRepository::class)->set('provider.voice_note', 'azure_mai');
        $session = $this->makeReadySession($this->device, $this->audio, ['source_app' => 'voice_note', 'final_provider' => null]);

        expect(app(FinalTranscriptCoordinator::class)->maybeStart($session)->provider)->toBe('azure_mai');
    });
});

describe('other completion modes', function () {
    it('hands an on-device provider\'s session to the device', function () {
        Http::fake();
        $session = $this->makeReadySession($this->device, $this->audio, ['final_provider' => 'parakeet_local']);
        $transcript = pendingFinal($session, 'parakeet_local');

        runFinalJob($transcript);

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::AwaitingDevice)->and($transcript->submitted_at)->not->toBeNull();
        expect($session->refresh()->status)->toBe(SessionStatus::Processing);
        Http::assertNothingSent();
    });

    it('stores a synchronous provider\'s result inside the job and completes the session', function () {
        $settings = app(SettingsRepository::class);
        $settings->setMany(['azure.model' => 'standard', 'azure.endpoint' => 'https://speech.example.cognitiveservices.azure.com']);
        app(CredentialVault::class)->put('azure', 'api_key', 'azure_test_key_1234567890');
        $captured = null;
        Http::fake(['speech.example.cognitiveservices.azure.com/*' => function (HttpRequest $request) use (&$captured) {
            $captured = ['body' => $request->body(), 'key' => $request->header('Ocp-Apim-Subscription-Key')[0] ?? null];

            return Http::response(['phrases' => [
                ['channel' => 0, 'offsetMilliseconds' => 0, 'durationMilliseconds' => 900, 'text' => 'Hello there.', 'words' => [['text' => 'Hello', 'offsetMilliseconds' => 0, 'durationMilliseconds' => 400]]],
                ['channel' => 1, 'offsetMilliseconds' => 1000, 'durationMilliseconds' => 900, 'text' => 'Hi, thanks for calling.', 'words' => []],
            ]], 200);
        }]);
        $session = $this->makeReadySession($this->device, $this->audio, ['final_provider' => 'azure_mai']);
        $transcript = pendingFinal($session, 'azure_mai');

        runFinalJob($transcript);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->text)->toBe("Me: Hello there.\nClient: Hi, thanks for calling.")
            ->and($transcript->completed_at)->not->toBeNull()
            ->and($session->refresh()->status)->toBe(SessionStatus::Completed);
        expect($captured['body'])->toContain('"channels":[0,1]')->and($captured['key'])->toBe('azure_test_key_1234567890');
    });

    it('completes an ElevenLabs job in the same call when no callback is registered', function () {
        app(CredentialVault::class)->put('elevenlabs', 'api_key', 'xi_test_key_1234567890');
        Http::fake(['api.elevenlabs.io/v1/speech-to-text' => Http::response([
            'transcription_id' => 'tr_9', 'language_code' => 'en',
            'transcripts' => [
                ['channel_index' => 0, 'words' => [['text' => 'Hi', 'start' => 0.1, 'end' => 0.3, 'type' => 'word']]],
                ['channel_index' => 1, 'words' => [['text' => 'Hello', 'start' => 1.1, 'end' => 1.5, 'type' => 'word']]],
            ],
        ], 200)]);
        $session = $this->makeReadySession($this->device, $this->audio, ['final_provider' => 'elevenlabs']);
        $transcript = pendingFinal($session, 'elevenlabs');

        runFinalJob($transcript);

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Completed)->and($transcript->text)->toBe("Me: Hi\nClient: Hello");
    });

    it('submits an ElevenLabs job for a callback when one is possible', function () {
        config(['app.url' => 'https://ledger.example.com']);
        app(CredentialVault::class)->put('elevenlabs', 'api_key', 'xi_test_key_1234567890');
        app(CredentialVault::class)->put('elevenlabs', 'webhook_id', 'wh_123');
        $captured = null;
        Http::fake(['api.elevenlabs.io/v1/speech-to-text' => function (HttpRequest $request) use (&$captured) {
            $captured = $request->body();

            return Http::response(['request_id' => 'req_7', 'transcription_id' => 'tr_7'], 200);
        }]);
        $session = $this->makeReadySession($this->device, $this->audio, ['final_provider' => 'elevenlabs']);
        $transcript = pendingFinal($session, 'elevenlabs');

        runFinalJob($transcript);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Submitted)->and($transcript->provider_job_id)->toBe('req_7')->and($transcript->provider_transcript_id)->toBe('tr_7');
        expect($captured)->toContain('name="webhook"')->toContain('wh_123')->toContain($transcript->id);
    });
});

describe('usage and the monthly limit', function () {
    it('fails the final transcript with budget_exceeded and calls no provider once the limit is reached', function () {
        app(SettingsRepository::class)->set('budget.monthly_minutes', 1);
        UsageLedgerEntry::query()->create([
            'user_id' => $this->device->user_id, 'source' => 'device', 'report_id' => 'seed-limit', 'use_case' => 'live_captions',
            'provider' => 'assemblyai', 'duration_ms' => 120_000, 'channels' => 1, 'billed_ms' => 120_000,
            'unit_price_usd' => 0.45, 'cost_usd' => 0.015, 'billable' => true, 'occurred_at' => now(),
        ]);
        Http::fake();
        $session = $this->makeReadySession($this->device, $this->audio);
        $transcript = pendingFinal($session);

        runFinalJob($transcript);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error_code)->toBe('budget_exceeded')
            ->and($transcript->error)->toBe('Monthly transcription limit reached');
        Http::assertNothingSent();
    });

    it('writes one ledger row per completed job, billed for every channel', function () {
        app(CredentialVault::class)->put('elevenlabs', 'api_key', 'xi_test_key_1234567890');
        Http::fake(['api.elevenlabs.io/v1/speech-to-text' => Http::response([
            'transcription_id' => 'tr_ledger', 'language_code' => 'en',
            'transcripts' => [
                ['channel_index' => 0, 'words' => [['text' => 'Hi', 'start' => 0.1, 'end' => 0.3, 'type' => 'word']]],
                ['channel_index' => 1, 'words' => [['text' => 'Hello', 'start' => 1.1, 'end' => 1.5, 'type' => 'word']]],
            ],
        ], 200)]);
        $session = $this->makeReadySession($this->device, $this->audio, ['final_provider' => 'elevenlabs']);
        $transcript = pendingFinal($session, 'elevenlabs');

        runFinalJob($transcript);
        runFinalJob($transcript);

        $rows = UsageLedgerEntry::query()->get();
        expect($rows)->toHaveCount(1)
            ->and($rows[0]->use_case)->toBe('final_transcript')
            ->and($rows[0]->provider)->toBe('elevenlabs')
            ->and($rows[0]->channels)->toBe(2)
            ->and($rows[0]->billed_ms)->toBe($rows[0]->duration_ms * 2)
            ->and($rows[0]->transcript_id)->toBe($transcript->id);
    });

    it('writes no ledger row when the provider refuses the job', function () {
        Http::fake(['api.assemblyai.com/v2/upload' => Http::response(['error' => 'no'], 400)]);
        $session = $this->makeReadySession($this->device, $this->audio);

        runFinalJob(pendingFinal($session));

        expect(UsageLedgerEntry::query()->count())->toBe(0);
    });
});
