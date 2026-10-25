<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\ProcessSttWebhookJob;
use App\Jobs\TranscribeFinalJob;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\WebhookEvent;
use App\Services\CredentialVault;
use App\Stt\Drivers\AssemblyAiDriver;
use App\Stt\Drivers\ElevenLabsSignature;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

const HOOK_SECRET = 'whsec_test_secret';
const ELEVEN_SECRET = 'wsec_eleven_secret';

/** A final transcript that has been submitted to $provider as job $jobId. */
function submittedTranscript(string $provider = 'assemblyai', string $jobId = 'job_1', array $attributes = []): Transcript
{
    $session = CaptureSession::factory()->create(['status' => SessionStatus::Processing, 'final_provider' => $provider]);

    return $session->transcripts()->create(array_merge([
        'kind' => TranscriptKind::Final, 'provider' => $provider, 'status' => TranscriptStatus::Submitted,
        'provider_job_id' => $jobId, 'submitted_at' => now()->subMinutes(2),
    ], $attributes));
}

/** @return array<string, mixed> */
function aaiHeaders(Transcript $transcript, ?string $secret = HOOK_SECRET): array
{
    return [AssemblyAiDriver::WEBHOOK_HEADER => AssemblyAiDriver::webhookToken((string) $secret, $transcript->id)];
}

function aaiUrl(Transcript|string $transcript): string
{
    return '/api/webhooks/stt/assemblyai?rid='.($transcript instanceof Transcript ? $transcript->id : $transcript);
}

/** @return array{0: string, 1: array<string, string>} raw body and signature headers */
function elevenSigned(array $payload, int $at, string $secret = ELEVEN_SECRET, ?string $deliveryId = null): array
{
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $headers = ['elevenlabs-signature' => ElevenLabsSignature::sign($body, $secret, $at), 'Content-Type' => 'application/json'];
    if ($deliveryId !== null) {
        $headers['ElevenLabs-Webhook-Delivery-Id'] = $deliveryId;
    }

    return [$body, $headers];
}

/** @return array<string, mixed> */
function elevenPayload(Transcript $transcript, string $requestId = 'req_1', ?array $transcription = null, string $type = 'speech_to_text_transcription'): array
{
    return [
        'type' => $type,
        'data' => [
            'request_id' => $requestId,
            'webhook_metadata' => ['transcript_id' => $transcript->id, 'session_id' => $transcript->capture_session_id],
            'transcription' => $transcription ?? [
                'transcription_id' => 'tr_1', 'language_code' => 'en',
                'words' => [
                    ['text' => 'Hello', 'start' => 0.0, 'end' => 0.4, 'type' => 'word', 'logprob' => -0.1],
                    ['text' => ' ', 'start' => 0.4, 'end' => 0.5, 'type' => 'spacing'],
                    ['text' => 'world.', 'start' => 0.5, 'end' => 0.9, 'type' => 'word', 'logprob' => -0.2],
                ],
            ],
        ],
    ];
}

describe('unknown providers', function () {
    it('answers 404 for providers that do not call back', function (string $provider) {
        $this->postJson("/api/webhooks/stt/{$provider}", ['x' => 1])->assertNotFound();
        expect(WebhookEvent::query()->count())->toBe(0);
    })->with(['azure_mai', 'whispercpp_local', 'parakeet_local', 'nope', 'ASSEMBLYAI', 'AssemblyAI', 'assembly-ai', 'elevenlabs%2Fx']);

    it('only accepts POST', function (string $method) {
        $this->json($method, '/api/webhooks/stt/assemblyai')->assertStatus(405);
    })->with(['GET', 'PUT', 'DELETE', 'PATCH']);

    it('is public, and ignores a bearer token rather than rejecting it', function () {
        $this->configureAssemblyAi();
        $transcript = submittedTranscript();
        $this->fakeAssemblyAi('job_1');

        $this->withToken('1|eld_not_a_real_token')
            ->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();
    });
});

describe('AssemblyAI verification', function () {
    beforeEach(function () {
        $this->configureAssemblyAi();
        $this->transcript = submittedTranscript();
        Http::fake();
    });

    it('rejects a missing token without doing any work', function () {
        $this->postJson(aaiUrl($this->transcript), ['transcript_id' => 'job_1', 'status' => 'completed'])->assertUnauthorized();

        expect(WebhookEvent::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('rejects a token minted for a different recording', function () {
        $other = submittedTranscript('assemblyai', 'job_2');

        $this->postJson(aaiUrl($this->transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($other))->assertUnauthorized();

        expect($this->transcript->refresh()->status)->toBe(TranscriptStatus::Submitted)->and(WebhookEvent::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('rejects a token computed with another secret', function () {
        $this->postJson(aaiUrl($this->transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($this->transcript, 'some-other-secret'))->assertUnauthorized();
    });

    it('rejects a valid token presented without the recording id', function () {
        $this->postJson('/api/webhooks/stt/assemblyai', ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($this->transcript))->assertUnauthorized();
    });

    it('rejects a token that was altered by one character', function () {
        $headers = aaiHeaders($this->transcript);
        $token = $headers[AssemblyAiDriver::WEBHOOK_HEADER];
        $headers[AssemblyAiDriver::WEBHOOK_HEADER] = substr($token, 0, -1).($token[-1] === 'A' ? 'B' : 'A');

        $this->postJson(aaiUrl($this->transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], $headers)->assertUnauthorized();
    });

    it('rejects everything while no webhook secret is configured', function () {
        app(CredentialVault::class)->forget('assemblyai', 'webhook_secret');

        $this->postJson(aaiUrl($this->transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($this->transcript))->assertUnauthorized();
    });

    it('stops honouring tokens minted under a rotated secret', function () {
        $old = aaiHeaders($this->transcript);
        app(CredentialVault::class)->put('assemblyai', 'webhook_secret', 'rotated-secret');

        $this->postJson(aaiUrl($this->transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], $old)->assertUnauthorized();
    });

    it('answers a correctly authenticated but malformed body with a client error, never a server error', function (string $body) {
        $response = $this->call('POST', aaiUrl($this->transcript), [], [], [], $this->transformHeadersToServerVars(array_merge(aaiHeaders($this->transcript), ['Content-Type' => 'application/json', 'Accept' => 'application/json'])), $body);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400)->toBeLessThan(500);
        expect(WebhookEvent::query()->count())->toBe(0);
    })->with(['not json' => 'this is not json', 'empty' => '', 'json list' => '[1,2,3]', 'object without id' => '{"status":"completed"}', 'null' => 'null']);

    it('answers a malformed recording id with 401, never a server error', function (string $query) {
        $headers = aaiHeaders($this->transcript);

        $response = $this->postJson('/api/webhooks/stt/assemblyai?'.$query, ['transcript_id' => 'job_1', 'status' => 'completed'], $headers);

        $response->assertUnauthorized();
        expect(WebhookEvent::query()->count())->toBe(0);
    })->with(['an empty value' => 'rid=', 'twice' => 'rid=a&rid=b']);

    it('answers a recording id sent as an array with 401, never a server error', function (string $query) {
        $headers = aaiHeaders($this->transcript);

        $response = $this->postJson('/api/webhooks/stt/assemblyai?'.$query, ['transcript_id' => 'job_1', 'status' => 'completed'], $headers);

        $response->assertUnauthorized();
        expect(WebhookEvent::query()->count())->toBe(0);
    })->with(['an array' => 'rid[]=abc', 'a keyed array' => 'rid[a]=b', 'a nested array' => 'rid[][]=x'])
        ->skip('A6.W16: an array rid parameter on the AssemblyAI webhook gives HTTP 500');

    it('does not log the token that was presented', function () {
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = json_encode([$message->message, $message->context]);
        });
        $headers = aaiHeaders($this->transcript, 'some-other-secret');

        $this->postJson(aaiUrl($this->transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], $headers)->assertUnauthorized();

        expect(implode('', $logged))->not->toContain($headers[AssemblyAiDriver::WEBHOOK_HEADER]);
    });
});

describe('AssemblyAI processing', function () {
    beforeEach(function () {
        $this->configureAssemblyAi();
        $this->log = [];
    });

    it('stores the transcript, deletes the provider copy and marks both done', function () {
        $transcript = submittedTranscript();
        $this->fakeAssemblyAi('job_1', null, $this->log);

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))
            ->assertOk()->assertJsonPath('received', true);

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->language)->toBe('en')
            ->and($transcript->segments)->toHaveCount(2)
            ->and($transcript->segments[0])->toMatchArray(['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900])
            ->and($transcript->segments[1])->toMatchArray(['channel' => 2, 'speaker' => 'Client'])
            ->and($transcript->completed_at)->not->toBeNull()
            ->and($transcript->provider_deleted_at)->not->toBeNull()
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Completed)
            ->and($this->log)->toBe(['GET /v2/transcript/job_1', 'DELETE /v2/transcript/job_1']);
        $event = WebhookEvent::query()->sole();
        expect($event->status)->toBe('processed')->and($event->processed_at)->not->toBeNull()->and($event->transcript_id)->toBe($transcript->id);
    });

    it('drops the raw payload once it is processed and never stores it in clear', function () {
        $transcript = submittedTranscript();
        $this->fakeAssemblyAi('job_1', null, $this->log);

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();

        expect(DB::table('webhook_events')->value('payload'))->toBeNull();
    });

    it('keeps an unprocessed payload encrypted at rest', function () {
        Queue::fake();
        $transcript = submittedTranscript();
        Http::fake(['*' => Http::response(['id' => 'job_1', 'status' => 'processing'], 200)]);

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed', 'note' => 'distinctive-payload-marker'], aaiHeaders($transcript))->assertOk();

        $raw = (string) DB::table('webhook_events')->value('payload');
        expect($raw)->not->toBe('')->not->toContain('distinctive-payload-marker');
        expect(WebhookEvent::query()->sole()->payload)->toMatchArray(['note' => 'distinctive-payload-marker']);
    });

    it('does the work once for a replayed delivery', function () {
        $transcript = submittedTranscript();
        $this->fakeAssemblyAi('job_1', null, $this->log);
        $send = fn () => $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript));

        $send()->assertOk();
        $send()->assertOk();
        $send()->assertOk();

        expect(WebhookEvent::query()->count())->toBe(1)->and($this->log)->toHaveCount(2);
    });

    it('records a different status for the same job as a separate event', function () {
        Queue::fake();
        $transcript = submittedTranscript();
        Http::fake(['*' => Http::response(['id' => 'job_1', 'status' => 'processing'], 200)]);

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'queued'], aaiHeaders($transcript))->assertOk();
        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();

        expect(WebhookEvent::query()->pluck('idempotency_key')->sort()->values()->all())->toBe(['assemblyai:job_1:completed', 'assemblyai:job_1:queued']);
    });

    it('does not fetch again for a transcript that is already complete', function () {
        $transcript = submittedTranscript('assemblyai', 'job_1', ['status' => TranscriptStatus::Completed, 'text' => 'already stored', 'completed_at' => now()]);
        $this->fakeAssemblyAi('job_1', null, $this->log);

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();

        expect($this->log)->toBe([])->and($transcript->refresh()->text)->toBe('already stored')->and(WebhookEvent::query()->sole()->status)->toBe('processed');
    });

    it('fails the transcript with the provider\'s reason when the job errored, and still deletes the remote copy', function () {
        $transcript = submittedTranscript();
        $this->fakeAssemblyAi('job_1', ['id' => 'job_1', 'status' => 'error', 'error' => 'Audio file could not be decoded'], $this->log);

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'error'], aaiHeaders($transcript))->assertOk();

        $transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)
            ->and($transcript->error)->toBe('Audio file could not be decoded')
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Failed)
            ->and($transcript->provider_deleted_at)->not->toBeNull()
            ->and($this->log)->toBe(['GET /v2/transcript/job_1', 'DELETE /v2/transcript/job_1']);
    });

    it('leaves the transcript untouched and retries later while the provider is still working', function () {
        Queue::fake();
        $transcript = submittedTranscript();
        Http::fake(['*' => Http::response(['id' => 'job_1', 'status' => 'processing'], 200)]);

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Submitted)->and(WebhookEvent::query()->sole()->processed_at)->toBeNull();
        Queue::assertPushed(ProcessSttWebhookJob::class, fn ($job) => $job->delay !== null);
    });

    it('answers 409 when the callback names a different provider job', function () {
        $transcript = submittedTranscript('assemblyai', 'job_1');
        Http::fake();

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_OTHER', 'status' => 'completed'], aaiHeaders($transcript))->assertStatus(409);

        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Submitted)->and(WebhookEvent::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('accepts a callback for a transcript whose job id is not stored yet', function () {
        Queue::fake();
        $transcript = submittedTranscript('assemblyai', 'job_1', ['provider_job_id' => null, 'status' => TranscriptStatus::Processing]);
        Http::fake();

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_9', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();

        expect(WebhookEvent::query()->count())->toBe(1);
    });

    it('ignores, with 200 so it is not retried, a callback for a transcript that no longer exists', function () {
        Http::fake();
        $ghost = '01ARZ3NDEKTSV4RRFFQ69G5FAV';
        $headers = [AssemblyAiDriver::WEBHOOK_HEADER => AssemblyAiDriver::webhookToken(HOOK_SECRET, $ghost)];

        $this->postJson(aaiUrl($ghost), ['transcript_id' => 'job_1', 'status' => 'completed'], $headers)
            ->assertOk()->assertJsonPath('ignored', 'unknown transcript');

        expect(WebhookEvent::query()->count())->toBe(0);
        Http::assertNothingSent();
    });

    it('ignores a callback routed to the wrong provider even when its token is valid', function () {
        $transcript = submittedTranscript('elevenlabs', 'req_1');
        Http::fake();

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();

        expect(WebhookEvent::query()->count())->toBe(0)->and($transcript->refresh()->status)->toBe(TranscriptStatus::Submitted);
        Http::assertNothingSent();
    });

    it('survives the provider copy refusing to be deleted, leaving the delete to the reconciler', function () {
        $transcript = submittedTranscript();
        $deletes = 0;
        Http::fake(function ($request) use (&$deletes) {
            if ($request->method() === 'DELETE') {
                $deletes++;

                return Http::response(['error' => 'busy'], $deletes < 2 ? 500 : 200);
            }

            return Http::response(Fixtures::assemblyAiResult('job_1'), 200);
        });

        $this->postJson(aaiUrl($transcript), ['transcript_id' => 'job_1', 'status' => 'completed'], aaiHeaders($transcript))->assertOk();
        expect($transcript->refresh()->status)->toBe(TranscriptStatus::Completed)->and($transcript->provider_deleted_at)->toBeNull();

        $this->artisan('stt:reconcile')->assertSuccessful();

        expect($transcript->refresh()->provider_deleted_at)->not->toBeNull()->and($deletes)->toBe(2);
    });
});

describe('ElevenLabs verification', function () {
    beforeEach(function () {
        app(CredentialVault::class)->put('elevenlabs', 'webhook_secret', ELEVEN_SECRET);
        app(CredentialVault::class)->put('elevenlabs', 'api_key', 'xi_test_key_1234567890');
        $this->transcript = submittedTranscript('elevenlabs', 'req_1');
        Http::fake();
    });

    it('rejects an unsigned request', function () {
        $this->postRaw('/api/webhooks/stt/elevenlabs', '{"type":"x"}', ['Content-Type' => 'application/json'])->assertUnauthorized();
        Http::assertNothingSent();
    });

    it('rejects a signature made with another secret', function () {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time(), 'not-the-secret');

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertUnauthorized();
    });

    it('rejects a body changed after signing', function () {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body.' ', $headers)->assertUnauthorized();
        $this->postRaw('/api/webhooks/stt/elevenlabs', str_replace('req_1', 'req_2', $body), $headers)->assertUnauthorized();
    });

    it('rejects a timestamp older than thirty minutes or more than five minutes ahead', function (int $offset) {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time() + $offset);

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertUnauthorized();
        expect(WebhookEvent::query()->count())->toBe(0);
    })->with([-1801, -86400, 301, 3600]);

    it('accepts a timestamp at the edge of the tolerance', function (int $offset) {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time() + $offset);

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();
    })->with([-1700, -60, 0, 60, 250]);

    it('rejects malformed signature headers', function (string $header) {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time());
        $headers['elevenlabs-signature'] = $header;

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertUnauthorized();
    })->with(['garbage', 't=abc,v0=00', 't=,v0=', 'v0=deadbeef', 't=1700000000', 't=1700000000,v1=abcd', ',,,', 't=-5,v0=ab']);

    it('accepts the right signature among several', function () {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), $now = time());
        $good = explode(',', $headers['elevenlabs-signature'])[1];
        $headers['elevenlabs-signature'] = "t={$now},v0=".str_repeat('0', 64).",{$good}";

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();
    });

    it('rejects everything while no webhook secret is configured', function () {
        app(CredentialVault::class)->forget('elevenlabs', 'webhook_secret');
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertUnauthorized();
    });

    it('rejects a correctly signed body that is not JSON', function () {
        $body = 'not json at all';
        $headers = ['elevenlabs-signature' => ElevenLabsSignature::sign($body, ELEVEN_SECRET, time()), 'Content-Type' => 'application/json'];

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertUnauthorized();
    });

    it('rejects an AssemblyAI-style token on the ElevenLabs route', function () {
        $this->postJson('/api/webhooks/stt/elevenlabs', ['type' => 'x'], aaiHeaders($this->transcript))->assertUnauthorized();
    });
});

describe('ElevenLabs processing', function () {
    beforeEach(function () {
        app(CredentialVault::class)->put('elevenlabs', 'webhook_secret', ELEVEN_SECRET);
        app(CredentialVault::class)->put('elevenlabs', 'api_key', 'xi_test_key_1234567890');
        $this->transcript = submittedTranscript('elevenlabs', 'req_1');
        $this->calls = [];
        Http::fake(function ($request) {
            $this->calls[] = $request->method().' '.parse_url($request->url(), PHP_URL_PATH);

            return Http::response([], 200);
        });
    });

    it('completes from the payload alone and then deletes the provider copy', function () {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk()->assertJsonPath('received', true);

        $transcript = $this->transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Completed)
            ->and($transcript->text)->toBe('Speaker: Hello world.')
            ->and($transcript->language)->toBe('en')
            ->and($transcript->provider_transcript_id)->toBe('tr_1')
            ->and($transcript->provider_deleted_at)->not->toBeNull()
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Completed)
            ->and($this->calls)->toBe(['DELETE /v1/speech-to-text/transcripts/tr_1']);
    });

    it('labels the two channels of a multichannel transcript Me and Client', function () {
        $multi = ['transcription_id' => 'tr_1', 'language_code' => 'en', 'transcripts' => [
            ['channel_index' => 0, 'words' => [['text' => 'Hello', 'start' => 0.0, 'end' => 0.4, 'type' => 'word'], ['text' => 'there', 'start' => 0.5, 'end' => 0.9, 'type' => 'word']]],
            ['channel_index' => 1, 'words' => [['text' => 'Thanks', 'start' => 2.0, 'end' => 2.4, 'type' => 'word'], ['text' => 'for calling', 'start' => 2.5, 'end' => 3.0, 'type' => 'word']]],
        ]];
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript, 'req_1', $multi), time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();

        $segments = $this->transcript->refresh()->segments;
        expect(array_column($segments, 'speaker'))->toBe(['Me', 'Client'])
            ->and(array_column($segments, 'channel'))->toBe([1, 2])
            ->and($segments[1]['start_ms'])->toBe(2000);
    });

    it('treats a replayed delivery as one event whether or not a delivery id is sent', function (?string $deliveryId) {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time(), ELEVEN_SECRET, $deliveryId);

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();
        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();

        expect(WebhookEvent::query()->count())->toBe(1)->and($this->calls)->toHaveCount(1);
    })->with(['with delivery id' => ['delivery-123'], 'without' => [null]]);

    it('treats an exact replay of a signed delivery as one event even when the unsigned delivery id changes', function () {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript), time());

        foreach (['delivery-1', 'delivery-2', 'delivery-3'] as $id) {
            $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers + ['ElevenLabs-Webhook-Delivery-Id' => $id])->assertOk();
        }

        expect(WebhookEvent::query()->count())->toBe(1)->and($this->calls)->toHaveCount(1);
    })->skip('A6.W20: ElevenLabs webhook de-duplication keys on the unsigned delivery-id header');

    it('fails the transcript with the provider\'s message on a webhook_error', function () {
        $payload = elevenPayload($this->transcript, 'req_1', [], 'webhook_error');
        $payload['error_message'] = 'The audio file is silent.';
        [$body, $headers] = elevenSigned($payload, time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();

        $transcript = $this->transcript->refresh();
        expect($transcript->status)->toBe(TranscriptStatus::Failed)->and($transcript->error)->toBe('The audio file is silent.')
            ->and($transcript->captureSession->status)->toBe(SessionStatus::Failed);
    });

    it('answers 409 when the request id does not match the submitted job', function () {
        [$body, $headers] = elevenSigned(elevenPayload($this->transcript, 'req_OTHER'), time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertStatus(409);

        expect($this->transcript->refresh()->status)->toBe(TranscriptStatus::Submitted);
    });

    it('ignores a delivery whose metadata points at no transcript', function () {
        $payload = elevenPayload($this->transcript);
        $payload['data']['webhook_metadata'] = ['transcript_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'];
        [$body, $headers] = elevenSigned($payload, time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk()->assertJsonPath('ignored', 'unknown transcript');

        expect(WebhookEvent::query()->count())->toBe(0);
    });

    it('ignores a delivery without any metadata', function () {
        $payload = elevenPayload($this->transcript);
        unset($payload['data']['webhook_metadata']);
        [$body, $headers] = elevenSigned($payload, time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();

        expect(WebhookEvent::query()->count())->toBe(0)->and($this->transcript->refresh()->status)->toBe(TranscriptStatus::Submitted);
    });

    it('ignores a delivery for a transcript owned by another provider', function () {
        $assembly = submittedTranscript('assemblyai', 'job_5');
        [$body, $headers] = elevenSigned(elevenPayload($assembly, 'job_5'), time());

        $this->postRaw('/api/webhooks/stt/elevenlabs', $body, $headers)->assertOk();

        expect(WebhookEvent::query()->count())->toBe(0)->and($assembly->refresh()->status)->toBe(TranscriptStatus::Submitted);
    });
});

describe('flooding', function () {
    it('answers 429 once a single address exceeds 300 callbacks a minute', function () {
        $this->freezeTime();
        foreach (range(1, 300) as $_) {
            $this->postJson('/api/webhooks/stt/assemblyai', [])->assertUnauthorized();
        }

        $blocked = $this->postJson('/api/webhooks/stt/assemblyai', []);

        $blocked->assertStatus(429);
        expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
    });
});

describe('reconciliation', function () {
    beforeEach(function () {
        $this->configureAssemblyAi();
    });

    it('recovers a job whose callback never arrived once it is ten minutes old', function () {
        $stuck = submittedTranscript('assemblyai', 'job_1', ['submitted_at' => now()->subMinutes(11)]);
        $fresh = submittedTranscript('assemblyai', 'job_2', ['submitted_at' => now()->subMinutes(3)]);
        $calls = [];
        $this->fakeAssemblyAi('job_1', null, $calls);

        $this->artisan('stt:reconcile')->assertSuccessful();

        expect($stuck->refresh()->status)->toBe(TranscriptStatus::Completed)
            ->and($stuck->provider_deleted_at)->not->toBeNull()
            ->and($fresh->refresh()->status)->toBe(TranscriptStatus::Submitted)
            ->and($calls)->toBe(['GET /v2/transcript/job_1', 'DELETE /v2/transcript/job_1']);
    });

    it('fails a job the provider never finishes after six hours', function () {
        $stale = submittedTranscript('assemblyai', 'job_1', ['submitted_at' => now()->subHours(7)]);
        $calls = [];
        $this->fakeAssemblyAi('job_1', ['id' => 'job_1', 'status' => 'processing'], $calls);

        $this->artisan('stt:reconcile')->assertSuccessful();

        expect($stale->refresh()->status)->toBe(TranscriptStatus::Failed)->and($stale->error)->toContain('6 hours');
    });

    it('fails a job the provider reports as errored', function () {
        $broken = submittedTranscript('assemblyai', 'job_1', ['submitted_at' => now()->subMinutes(30)]);
        $calls = [];
        $this->fakeAssemblyAi('job_1', ['id' => 'job_1', 'status' => 'error', 'error' => 'unsupported file'], $calls);

        $this->artisan('stt:reconcile')->assertSuccessful();

        expect($broken->refresh()->status)->toBe(TranscriptStatus::Failed)->and($broken->error)->toBe('unsupported file');
    });

    it('survives a provider outage and tries again on the next run', function () {
        $stuck = submittedTranscript('assemblyai', 'job_1', ['submitted_at' => now()->subMinutes(30)]);
        Http::fake(['*' => Http::response(['error' => 'down'], 503)]);

        $this->artisan('stt:reconcile')->assertSuccessful();

        expect($stuck->refresh()->status)->toBe(TranscriptStatus::Submitted);
    });

    it('re-queues a pending transcript whose job was lost', function () {
        Queue::fake();
        $lost = submittedTranscript('assemblyai', 'job_1', ['status' => TranscriptStatus::Pending, 'provider_job_id' => null]);
        $lost->forceFill(['updated_at' => now()->subMinutes(45)])->saveQuietly();
        $recent = submittedTranscript('assemblyai', 'job_2', ['status' => TranscriptStatus::Pending, 'provider_job_id' => null]);

        $this->artisan('stt:reconcile')->assertSuccessful();

        Queue::assertPushed(TranscribeFinalJob::class, 1);
        Queue::assertPushed(TranscribeFinalJob::class, fn ($job) => $job->transcriptId === $lost->id);
    });
});
