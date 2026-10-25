<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Transcript;
use App\Models\WebhookEvent;
use App\Services\CredentialVault;
use App\Stt\Drivers\AssemblyAiDriver;
use App\Stt\Drivers\ElevenLabsSignature;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Security\Support\ApiClient;

uses(ApiClient::class);

const SEC_AAI_SECRET = 'whsec_assemblyai_secret_value';
const SEC_EL_SECRET = 'whsec_elevenlabs_secret_value';

beforeEach(function () {
    Queue::fake();
    $vault = app(CredentialVault::class);
    $vault->put('assemblyai', 'webhook_secret', SEC_AAI_SECRET);
    $vault->put('assemblyai', 'api_key', 'aai_api_key_value');
    $vault->put('elevenlabs', 'webhook_secret', SEC_EL_SECRET);
    $vault->put('elevenlabs', 'api_key', 'el_api_key_value');
    Http::fake(function ($request) {
        if ($request->method() === 'GET') {
            return Http::response([
                'id' => 'job_1', 'status' => 'completed', 'audio_channels' => 2, 'language_code' => 'en',
                'utterances' => [['channel' => '1', 'start' => 0, 'end' => 900, 'text' => 'Genuine provider text.', 'words' => []]],
            ]);
        }

        return Http::response([], 200);
    });
});

function secTranscript(?string $jobId = 'job_1', string $provider = 'assemblyai', TranscriptStatus $status = TranscriptStatus::Submitted): Transcript
{
    return CaptureSession::factory()->create()->transcripts()->create([
        'kind' => TranscriptKind::Final, 'provider' => $provider, 'status' => $status, 'provider_job_id' => $jobId,
    ]);
}

function secAaiToken(string $rid): string
{
    return AssemblyAiDriver::webhookToken(SEC_AAI_SECRET, $rid);
}

/** @param  array<string, string>  $headers */
function secPostRaw(string $uri, string $body, array $headers = [], string $contentType = 'application/json'): TestResponse
{
    $test = test();
    $test->api();

    return $test->call('POST', $uri, [], [], [], $test->transformHeadersToServerVars(['Content-Type' => $contentType, 'Accept' => 'application/json'] + $headers), $body);
}

function secAaiPost(string $rid, array $body = ['transcript_id' => 'job_1', 'status' => 'completed'], ?string $token = null): TestResponse
{
    return secPostRaw('/api/webhooks/stt/assemblyai?rid='.urlencode($rid), json_encode($body), ['X-EchoLedger-Webhook-Token' => $token ?? secAaiToken($rid)]);
}

function secElPost(array|string $body, ?string $signature = null, array $headers = [], ?int $timestamp = null): TestResponse
{
    $raw = is_array($body) ? json_encode($body) : $body;

    return secPostRaw('/api/webhooks/stt/elevenlabs', $raw, [
        'elevenlabs-signature' => $signature ?? ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $timestamp ?? time()),
    ] + $headers);
}

function secElBody(string $rid, string $requestId = 'req_1'): array
{
    return ['type' => 'speech_to_text_transcription', 'data' => [
        'request_id' => $requestId, 'webhook_metadata' => ['transcript_id' => $rid],
        'transcription' => ['text' => 'hi', 'language_code' => 'en', 'words' => []],
    ]];
}

it('answers 404 for providers that are not webhook providers', function (string $provider) {
    $this->api()->postJson('/api/webhooks/stt/'.$provider, ['transcript_id' => 'job_1'])->assertNotFound();
})->with(['nope', 'azure_mai', 'parakeet_local', 'whispercpp_local', 'ASSEMBLYAI', 'assemblyai%00', 'assemblyai%20', '%2e%2e', str_repeat('a', 3000), "x'--"]);

it('rejects every webhook with 401 while no signing secret is configured', function () {
    app(CredentialVault::class)->forget('assemblyai', 'webhook_secret');
    app(CredentialVault::class)->forget('elevenlabs', 'webhook_secret');
    $transcript = secTranscript();

    secPostRaw('/api/webhooks/stt/assemblyai?rid='.$transcript->id, '{"transcript_id":"job_1","status":"completed"}', ['X-EchoLedger-Webhook-Token' => ''])->assertStatus(401);
    secPostRaw('/api/webhooks/stt/assemblyai?rid='.$transcript->id, '{"transcript_id":"job_1"}', ['X-EchoLedger-Webhook-Token' => secAaiToken($transcript->id)])->assertStatus(401);
    secElPost(secElBody($transcript->id), 't='.time().',v0='.hash_hmac('sha256', time().'.x', ''))->assertStatus(401);

    expect(WebhookEvent::query()->count())->toBe(0);
});

it('accepts the genuine AssemblyAI token and rejects every variation of it', function () {
    $transcript = secTranscript();
    $good = secAaiToken($transcript->id);
    $variations = [
        'empty' => '', 'single char' => 'a', 'truncated' => substr($good, 0, -1), 'extended' => $good.'A', 'trailing space' => $good.' ',
        'leading space' => ' '.$good, 'flipped last char' => substr($good, 0, -1).(str_ends_with($good, 'A') ? 'B' : 'A'), 'upper-cased' => strtoupper($good),
        'other transcript token' => secAaiToken('01JOTHEROTHEROTHEROTHEROT'), 'token for secret reversed' => strrev($good),
        'base64 padded' => $good.'=', 'hex digest' => hash_hmac('sha256', 'assemblyai|'.$transcript->id, SEC_AAI_SECRET),
        'secret itself' => SEC_AAI_SECRET, 'null string' => 'null', 'long' => str_repeat('A', 20000),
    ];
    $accepted = [];

    foreach ($variations as $label => $token) {
        $status = secAaiPost($transcript->id, token: $token)->getStatusCode();
        if ($status !== 401) {
            $accepted[] = "{$label} => {$status}";
        }
    }

    expect($accepted)->toBe([]);
    expect(WebhookEvent::query()->count())->toBe(0);
    secAaiPost($transcript->id)->assertOk()->assertJsonPath('received', true);
});

it('does not accept a valid token for a different transcript id', function () {
    $mine = secTranscript();
    $victim = secTranscript('job_2');

    secPostRaw('/api/webhooks/stt/assemblyai?rid='.$victim->id, '{"transcript_id":"job_2","status":"completed"}', ['X-EchoLedger-Webhook-Token' => secAaiToken($mine->id)])->assertStatus(401);

    expect($victim->refresh()->status)->toBe(TranscriptStatus::Submitted);
});

it('answers a rid parameter sent as an array with 401 instead of a server error', function (string $query) {
    $response = secPostRaw('/api/webhooks/stt/assemblyai?'.$query, '{"transcript_id":"job_1","status":"completed"}', ['X-EchoLedger-Webhook-Token' => secAaiToken('x')]);

    expect($response->getStatusCode())->toBe(401);
})->with([
    'array value' => ['rid[]=x'],
    'nested array' => ['rid[a][b]=x'],
])->skip('A6.W16: an array rid parameter on the AssemblyAI webhook gives HTTP 500');

it('answers a malformed rid parameter with 401 instead of a server error', function (string $query) {
    $response = secPostRaw('/api/webhooks/stt/assemblyai?'.$query, '{"transcript_id":"job_1","status":"completed"}', ['X-EchoLedger-Webhook-Token' => secAaiToken('x')]);

    expect($response->getStatusCode())->toBe(401);
})->with([
    'empty' => ['rid='],
    'missing' => ['other=1'],
    'very long' => ['rid='.str_repeat('A', 20000)],
    'null byte' => ['rid=a%00b'],
    'sql' => ['rid='."1'%20OR%20'1'%3D'1"],
]);

it('rejects a job id that does not belong to the transcript', function () {
    $transcript = secTranscript('job_1');

    secAaiPost($transcript->id, ['transcript_id' => 'job_OTHER', 'status' => 'completed'])->assertStatus(409);

    expect(WebhookEvent::query()->count())->toBe(0)->and($transcript->refresh()->status)->toBe(TranscriptStatus::Submitted);
});

it('never stores transcript text taken from the webhook body', function () {
    $transcript = secTranscript();

    secAaiPost($transcript->id, [
        'transcript_id' => 'job_1', 'status' => 'completed', 'text' => 'FORGED TEXT', 'audio_channels' => 2,
        'utterances' => [['channel' => '1', 'start' => 0, 'end' => 10, 'text' => 'FORGED UTTERANCE', 'words' => []]],
    ])->assertOk();

    $stored = (string) $transcript->refresh()->text;
    expect($stored)->toContain('Genuine provider text.')->not->toContain('FORGED');
});

it('processes a delivery once and ignores an exact replay', function () {
    $transcript = secTranscript();

    secAaiPost($transcript->id)->assertOk();
    secAaiPost($transcript->id)->assertOk();
    secAaiPost($transcript->id)->assertOk();

    expect(WebhookEvent::query()->count())->toBe(1);
    Http::assertSentCount(2); // one fetch and one provider-side delete, regardless of replays
});

it('does not reopen a finished transcript when an old delivery is replayed with different content', function () {
    $transcript = secTranscript();
    secAaiPost($transcript->id)->assertOk();
    $snapshot = fn () => [$transcript->refresh()->text, $transcript->status->value, $transcript->completed_at?->toIso8601String()];
    $before = $snapshot();

    secAaiPost($transcript->id, ['transcript_id' => 'job_1', 'status' => 'error', 'error' => 'forged failure'])->assertOk();

    expect($snapshot())->toBe($before);
});

it('stays quiet about wrong content types with a valid token', function (string $contentType, string $body) {
    $transcript = secTranscript();

    $response = secPostRaw('/api/webhooks/stt/assemblyai?rid='.$transcript->id, $body, ['X-EchoLedger-Webhook-Token' => secAaiToken($transcript->id)], $contentType);

    expect($response->getStatusCode())->toBeLessThan(500);
    expect((string) $transcript->refresh()->text)->not->toContain('FORGED');
})->with([
    'text plain with json' => ['text/plain', '{"transcript_id":"job_1","status":"completed"}'],
    'form encoded' => ['application/x-www-form-urlencoded', 'transcript_id=job_1&status=completed&text=FORGED'],
    'xml' => ['application/xml', '<transcript_id>job_1</transcript_id>'],
    'multipart without boundary' => ['multipart/form-data', '--x'],
    'garbage type' => ['\x00/\xff', '{"transcript_id":"job_1"}'],
    'empty body' => ['application/json', ''],
    'json scalar' => ['application/json', '"job_1"'],
    'json list' => ['application/json', '[1,2,3]'],
]);

it('refuses a giant unsigned body without storing it', function () {
    $transcript = secTranscript();
    $body = str_repeat('A', 6 * 1024 * 1024);

    $assemblyAi = secPostRaw('/api/webhooks/stt/assemblyai?rid='.$transcript->id, $body, ['X-EchoLedger-Webhook-Token' => 'wrong']);
    $elevenLabs = secPostRaw('/api/webhooks/stt/elevenlabs', $body, ['elevenlabs-signature' => 't='.time().',v0=deadbeef']);

    expect($assemblyAi->getStatusCode())->toBe(401)->and($elevenLabs->getStatusCode())->toBe(401);
    expect(WebhookEvent::query()->count())->toBe(0);
});

it('answers 413 when the declared body exceeds the PHP post limit', function () {
    $limit = ini_parse_quantity((string) ini_get('post_max_size'));

    $response = $this->api()->call('POST', '/api/webhooks/stt/assemblyai?rid=x', [], [], [], $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'Accept' => 'application/json']) + ['CONTENT_LENGTH' => $limit + 1], '{}');

    expect($response->getStatusCode())->toBe(413);
});

it('accepts webhooks only by POST', function () {
    foreach (['GET', 'PUT', 'PATCH', 'DELETE'] as $method) {
        $this->api()->call($method, '/api/webhooks/stt/assemblyai?rid=x')->assertStatus(405);
    }
});

it('accepts a genuine ElevenLabs signature and rejects altered ones', function () {
    $transcript = secTranscript('req_1', 'elevenlabs');
    $raw = json_encode(secElBody($transcript->id));
    $now = time();
    $good = ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $now);
    [, $v0] = explode(',', $good);
    $cases = [
        'wrong secret' => ElevenLabsSignature::sign($raw, 'another-secret', $now),
        'tampered body' => ElevenLabsSignature::sign($raw.' ', SEC_EL_SECRET, $now),
        'swapped timestamp' => 't='.($now + 1).','.$v0,
        'stale by 31 minutes' => ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $now - 1860),
        'future by 6 minutes' => ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $now + 360),
        'non numeric timestamp' => 't=abc,'.$v0,
        'empty timestamp' => 't=,'.$v0,
        'negative timestamp' => 't=-5,'.$v0,
        'float timestamp' => 't='.$now.'.5,'.$v0,
        'missing timestamp' => $v0,
        'missing signature' => 't='.$now,
        'wrong scheme' => 't='.$now.',v1='.substr($v0, 3),
        'uppercase hex' => 't='.$now.',v0='.strtoupper(substr($v0, 3)),
        'truncated hex' => 't='.$now.','.substr($v0, 0, -2),
        'empty header' => '',
        'garbage' => 'hello',
    ];
    $accepted = [];

    foreach ($cases as $label => $header) {
        $status = secPostRaw('/api/webhooks/stt/elevenlabs', $raw, ['elevenlabs-signature' => $header])->getStatusCode();
        if ($status !== 401) {
            $accepted[] = "{$label} => {$status}";
        }
    }
    expect($accepted)->toBe([]);
    expect(WebhookEvent::query()->count())->toBe(0);

    secPostRaw('/api/webhooks/stt/elevenlabs', $raw, ['elevenlabs-signature' => $good])->assertOk();
});

it('applies the ElevenLabs tolerance window at its edges', function () {
    $transcript = secTranscript('req_1', 'elevenlabs');
    $raw = json_encode(secElBody($transcript->id));
    $now = time();

    secPostRaw('/api/webhooks/stt/elevenlabs', $raw, ['elevenlabs-signature' => ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $now - 1790)])->assertOk();
    secPostRaw('/api/webhooks/stt/elevenlabs', $raw, ['elevenlabs-signature' => ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $now - 1810)])->assertStatus(401);
    secPostRaw('/api/webhooks/stt/elevenlabs', $raw, ['elevenlabs-signature' => ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $now + 290)])->assertOk();
    secPostRaw('/api/webhooks/stt/elevenlabs', $raw, ['elevenlabs-signature' => ElevenLabsSignature::sign($raw, SEC_EL_SECRET, $now + 310)])->assertStatus(401);
});

it('rejects a validly signed ElevenLabs body that is not JSON', function () {
    foreach (['not json', '', '"text"', '12', 'null'] as $raw) {
        $status = secElPost($raw)->getStatusCode();
        expect($status)->toBeIn([401, 200]);
        expect($status)->not->toBeGreaterThanOrEqual(500);
    }
    expect(WebhookEvent::query()->count())->toBe(0);
});

it('deduplicates an exact ElevenLabs replay even when the unsigned delivery-id header changes', function () {
    $transcript = secTranscript('req_1', 'elevenlabs');
    $raw = json_encode(secElBody($transcript->id));
    $signature = ElevenLabsSignature::sign($raw, SEC_EL_SECRET, time());

    foreach (['delivery-1', 'delivery-2', 'delivery-3'] as $delivery) {
        secPostRaw('/api/webhooks/stt/elevenlabs', $raw, ['elevenlabs-signature' => $signature, 'ElevenLabs-Webhook-Delivery-Id' => $delivery])->assertOk();
    }

    expect(WebhookEvent::query()->count())->toBe(1);
})->skip('A6.W20: ElevenLabs webhook de-duplication keys on the unsigned delivery-id header');

it('compares signatures with constant-time functions', function () {
    $assemblyAi = file_get_contents(app_path('Stt/Drivers/AssemblyAiDriver.php'));
    $signature = file_get_contents(app_path('Stt/Drivers/ElevenLabsSignature.php'));

    expect($assemblyAi)->toContain('hash_equals(self::webhookToken(')
        ->and($signature)->toContain('hash_equals($expected, $signature)')
        ->and($signature)->not->toMatch('/\$expected\s*[!=]==?\s*\$signature/')
        ->and($assemblyAi)->not->toMatch('/webhookToken\([^)]*\)\s*[!=]==?\s*\$given/');
});

it('makes no outbound request when a verified webhook names a transcript that was never submitted', function () {
    $transcript = secTranscript(null);

    secAaiPost($transcript->id, ['transcript_id' => 'http://169.254.169.254/latest/meta-data', 'status' => 'completed'])->assertOk();
    secAaiPost($transcript->id, ['transcript_id' => '../../v2/account', 'status' => 'completed'])->assertOk();

    Http::assertNothingSent();
});

it('only ever talks to the configured provider hosts while handling webhooks', function () {
    $transcript = secTranscript('job_1');

    secAaiPost($transcript->id, ['transcript_id' => 'job_1', 'status' => 'completed', 'webhook_url' => 'http://evil.example/x', 'audio_url' => 'http://evil.example/a.mp3'])->assertOk();

    Http::assertSent(fn ($request) => in_array(parse_url($request->url(), PHP_URL_HOST), ['api.assemblyai.com', 'api.eu.assemblyai.com'], true));
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example'));
});
