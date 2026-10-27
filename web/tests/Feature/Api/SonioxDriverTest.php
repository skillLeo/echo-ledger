<?php

declare(strict_types=1);

use App\Enums\RecordingRole;
use App\Enums\SttUseCase;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Data\StreamingRequest;
use App\Stt\Drivers\SonioxDriver;
use App\Stt\Exceptions\InvalidWebhookSignature;
use App\Stt\Exceptions\ProviderJobFailed;
use App\Stt\Exceptions\UnsupportedFeature;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * The Soniox driver (v1.1.2), against recorded request and response shapes from soniox.com/docs/stt/api-reference:
 * file upload, transcription request (model, language_hints, context terms, webhook auth header), status polling,
 * tokens turned into Me / Client segments, webhook verification and deletion of the provider copy.
 */

beforeEach(function () {
    [$this->device] = $this->enroll(platform: 'windows');
    app(CredentialVault::class)->put('soniox', 'api_key', 'soniox_test_key_1234567890');
    app(CredentialVault::class)->put('soniox', 'webhook_secret', 'whsec_soniox_secret');
    config(['app.url' => 'https://ledger.example.com']);
});

/** Soniox tokens for "Hello there." spoken from $startMs: word pieces, the second word split in two. */
function sonioxTokens(int $startMs, string $first = 'Hello', string $second = 'there.'): array
{
    return [
        ['text' => $first, 'start_ms' => $startMs, 'end_ms' => $startMs + 300, 'confidence' => 0.98],
        ['text' => ' '.mb_substr($second, 0, 3), 'start_ms' => $startMs + 400, 'end_ms' => $startMs + 600, 'confidence' => 0.91],
        ['text' => mb_substr($second, 3), 'start_ms' => $startMs + 600, 'end_ms' => $startMs + 800, 'confidence' => 0.95],
    ];
}

function fakeSoniox(array &$log, string $status = 'completed', ?array $transcripts = null): void
{
    $jobs = 0;
    $files = 0;
    Http::fake(function (HttpRequest $request) use (&$log, &$jobs, &$files, $status, $transcripts) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $log[] = $request->method().' '.$path;
        if ($request->method() === 'POST' && $path === '/v1/files') {
            return Http::response(['id' => 'file-'.(++$files)], 201);
        }
        if ($request->method() === 'POST' && $path === '/v1/transcriptions') {
            return Http::response(['id' => 'job-'.(++$jobs), 'status' => 'queued'], 201);
        }
        if ($request->method() === 'GET' && preg_match('#^/v1/transcriptions/(job-\d)$#', $path, $m) === 1) {
            return Http::response(['id' => $m[1], 'status' => $status, 'error_message' => $status === 'error' ? 'Audio could not be decoded.' : null], 200);
        }
        if ($request->method() === 'GET' && preg_match('#^/v1/transcriptions/job-(\d)/transcript$#', $path, $m) === 1) {
            $body = $transcripts[(int) $m[1] - 1] ?? ['text' => 'Hello there.', 'tokens' => sonioxTokens(0)];

            return Http::response(['id' => 'job-'.$m[1], ...$body], 200);
        }

        return Http::response(null, 204);
    });
}

function sonioxTranscript(object $test, array $session = [], bool $channels = false): Transcript
{
    $s = $test->makeReadySession($test->device, Fixtures::audio(4000), ['final_provider' => 'soniox', ...$session]);
    if ($channels) {
        $test->makeReadyRecording($s, Fixtures::audio(2000, 'CH1'), ['role' => RecordingRole::Channel1->value, 'channels' => 1, 'mime' => 'audio/wav']);
        $test->makeReadyRecording($s, Fixtures::audio(2000, 'CH2'), ['role' => RecordingRole::Channel2->value, 'channels' => 1, 'mime' => 'audio/wav']);
    }

    return $s->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'soniox', 'status' => TranscriptStatus::Pending, 'language' => $session['language'] ?? null]);
}

it('uploads the stereo file once, asks for speaker separation and the language, and signs the webhook', function () {
    $log = [];
    fakeSoniox($log);
    $transcript = sonioxTranscript($this, ['language' => 'en+ur']);

    dispatch_sync(new TranscribeFinalJob($transcript->id));

    $create = null;
    Http::assertSent(function (HttpRequest $r) use (&$create): bool {
        if ($r->method() === 'POST' && str_ends_with($r->url(), '/v1/transcriptions')) {
            $create = $r->data();
        }

        return true;
    });
    expect($log)->toBe(['POST /v1/files', 'POST /v1/transcriptions'])
        ->and($create)->toMatchArray([
            'model' => 'stt-async-v5', 'file_id' => 'file-1', 'language_hints' => ['ur', 'en'], 'language_hints_strict' => true,
            'enable_speaker_diarization' => true, 'webhook_auth_header_name' => SonioxDriver::WEBHOOK_HEADER,
            'webhook_auth_header_value' => SonioxDriver::webhookToken('whsec_soniox_secret', $transcript->id),
        ])
        ->and($create['webhook_url'])->toBe("https://ledger.example.com/api/webhooks/stt/soniox?rid={$transcript->id}")
        ->and($create)->not->toHaveKey('enable_language_identification');
    expect($transcript->refresh()->provider_job_id)->toBe('job-1')->and($transcript->provider_transcript_id)->toBe('file-1')
        ->and($transcript->status)->toBe(TranscriptStatus::Submitted);
    Http::assertSent(fn (HttpRequest $r): bool => ! str_ends_with($r->url(), '/v1/files') || $r->hasHeader('Authorization', 'Bearer soniox_test_key_1234567890'));
});

it('detects the language for automatic and sends the client keyterms as context terms', function () {
    $body = app(SonioxDriver::class)->transcriptionBody('file-1', new FinalJobRequest(
        sonioxTranscript($this), $this->makeReadySession($this->device, Fixtures::audio(100)), Recording::query()->firstOrFail(),
        'x', null, null, null, null, ['Farah Khan', 'EchoLedger'], null, null,
    ), false, 'rid-1');

    expect($body['enable_language_identification'])->toBeTrue()
        ->and($body['language_hints'])->toBe(['en', 'ur', 'ar']) // steered to what Auto may pick, not strict
        ->and($body)->not->toHaveKey('language_hints_strict')
        ->and($body['context'])->toBe(['terms' => ['Farah Khan', 'EchoLedger']])
        ->and($body)->not->toHaveKey('webhook_url');
});

it('sends each channel file as its own job when the device uploaded them, and keeps Me and Client apart', function () {
    $log = [];
    fakeSoniox($log, 'completed', [
        ['text' => 'Hello there.', 'tokens' => sonioxTokens(0)],
        ['text' => 'جی ہاں۔', 'tokens' => sonioxTokens(2000, 'جی', 'ہاں۔')],
    ]);
    $transcript = sonioxTranscript($this, ['language' => 'ur'], channels: true);

    dispatch_sync(new TranscribeFinalJob($transcript->id));
    $transcript->refresh();
    expect($transcript->provider_job_id)->toBe('job-1,job-2')->and($transcript->provider_transcript_id)->toBe('file-1,file-2');

    $result = app(SonioxDriver::class)->fetchResult($transcript);

    expect($result->segments)->toHaveCount(2)
        ->and($result->segments[0]->channel)->toBe(1)->and($result->segments[0]->speaker)->toBe('Me')
        ->and($result->segments[0]->text)->toBe('Hello there.')
        ->and($result->segments[0]->words)->toHaveCount(2)
        ->and($result->segments[1]->speaker)->toBe('Client')->and($result->segments[1]->text)->toBe('جی ہاں۔')
        ->and($result->language)->toBe('ur')
        ->and($result->meta['audio_channels'])->toBe(2);
});

it('labels diarized speakers of a single stereo job and joins word pieces into words', function () {
    $tokens = [
        ['text' => 'Hel', 'start_ms' => 0, 'end_ms' => 100, 'speaker' => '1'],
        ['text' => 'lo', 'start_ms' => 100, 'end_ms' => 200, 'speaker' => '1'],
        ['text' => ' Ali', 'start_ms' => 250, 'end_ms' => 400, 'speaker' => '1'],
        ['text' => 'Salaam', 'start_ms' => 3000, 'end_ms' => 3400, 'speaker' => '2'],
        ['text' => '<end>', 'start_ms' => 3400, 'end_ms' => 3400, 'speaker' => '2'],
    ];

    $result = app(SonioxDriver::class)->normalize([['text' => 'Hello Ali Salaam', 'tokens' => $tokens]]);

    expect(array_map(fn ($s) => [$s->channel, $s->speaker, $s->text], $result->segments))->toBe([
        [1, 'Speaker 1', 'Hello Ali'],
        [2, 'Speaker 2', 'Salaam'],
    ]);
});

it('waits until every job is done and reports a failed one', function () {
    $log = [];
    fakeSoniox($log, 'processing');
    $transcript = Transcript::query()->create(['capture_session_id' => $this->makeReadySession($this->device, Fixtures::audio(100))->id,
        'kind' => TranscriptKind::Final, 'provider' => 'soniox', 'status' => TranscriptStatus::Processing, 'provider_job_id' => 'job-1,job-2']);

    expect(app(SonioxDriver::class)->fetchResult($transcript))->toBeNull();

    $this->resetHttpFakes();
    Http::fake(['api.soniox.com/v1/transcriptions/*' => Http::response(['id' => 'job-1', 'status' => 'error', 'error_message' => 'Audio could not be decoded.'], 200)]);
    expect(fn () => app(SonioxDriver::class)->fetchResult($transcript))->toThrow(ProviderJobFailed::class, 'Audio could not be decoded.');
});

it('accepts only webhooks that carry the token for that recording', function () {
    $driver = app(SonioxDriver::class);
    $good = Request::create('/api/webhooks/stt/soniox?rid=T1', 'POST', [], [], [], ['HTTP_'.strtoupper(str_replace('-', '_', SonioxDriver::WEBHOOK_HEADER)) => SonioxDriver::webhookToken('whsec_soniox_secret', 'T1')], json_encode(['id' => 'job-1', 'status' => 'completed']));

    $envelope = $driver->verifyWebhook($good);
    expect($envelope->transcriptRef)->toBe('T1')->and($envelope->status)->toBe('completed')->and($envelope->jobId)->toBeNull();

    $forged = Request::create('/api/webhooks/stt/soniox?rid=T2', 'POST', [], [], [], ['HTTP_'.strtoupper(str_replace('-', '_', SonioxDriver::WEBHOOK_HEADER)) => SonioxDriver::webhookToken('whsec_soniox_secret', 'T1')], json_encode(['id' => 'job-1', 'status' => 'completed']));
    expect(fn () => $driver->verifyWebhook($forged))->toThrow(InvalidWebhookSignature::class);
});

it('completes the transcript from the webhook and deletes the jobs and the files at Soniox', function () {
    $log = [];
    fakeSoniox($log);
    $transcript = sonioxTranscript($this, ['language' => 'en']);
    dispatch_sync(new TranscribeFinalJob($transcript->id));

    $this->postJson("/api/webhooks/stt/soniox?rid={$transcript->id}", ['id' => 'job-1', 'status' => 'completed'],
        [SonioxDriver::WEBHOOK_HEADER => SonioxDriver::webhookToken('whsec_soniox_secret', $transcript->id)])->assertOk();

    expect($transcript->refresh()->status)->toBe(TranscriptStatus::Completed)->and($transcript->text)->toContain('Hello there.');
    expect($log)->toContain('DELETE /v1/transcriptions/job-1')->toContain('DELETE /v1/files/file-1');
});

it('offers no live captions, which the apps cannot stream to Soniox yet', function () {
    expect(fn () => app(SonioxDriver::class)->streamingGrant(new StreamingRequest(SttUseCase::LiveCaptions, ['me'], 16000, 'ur', [])))
        ->toThrow(UnsupportedFeature::class);
});
