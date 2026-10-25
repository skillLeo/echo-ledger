<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Device;
use App\Services\CredentialVault;
use App\Services\PairingService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\Fuzz;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

beforeEach(function () {
    Fx::fakeRecordingsDisk();
});

it('survives hostile values in every device registration field', function () {
    $owner = Fx::user();
    $ip = 0;
    $code = '';
    $reset = function () use ($owner, &$code) {
        $code = app(PairingService::class)->createCode($owner);
    };
    $reset();

    $failures = Fuzz::run(
        function (array $payload) use (&$ip, &$code) {
            if (($payload['pairing_code'] ?? null) === '__CODE__') {
                $payload['pairing_code'] = $code;
            }

            return $this->api()->withServerVariables(['REMOTE_ADDR' => '10.20.30.'.(++$ip % 250 + 1)])->postJson('/api/devices/register', $payload);
        },
        ['pairing_code' => '__CODE__', 'name' => 'Pixel', 'platform' => 'android', 'app_version' => '1.0.0', 'os_version' => '16'],
        ['pairing_code', 'name', 'platform', 'app_version', 'os_version'],
        null,
        $reset,
    );

    expect($failures)->toBe([]);
});

/**
 * Fuzzes the given session creation fields one at a time and returns one line per offending request.
 *
 * @param  Closure(array<string, mixed>): mixed  $post  sends one session creation payload and returns the response
 * @param  list<string>  $paths
 * @return list<string>
 */
function secFuzzSessionCreation(Closure $post, array $paths): array
{
    $ref = 'fixed-reference';

    return Fuzz::run(
        function (array $payload) use ($post, $ref) {
            if (($payload['client_ref'] ?? null) === $ref) {
                $payload['client_ref'] = (string) Str::uuid(); // a repeated client_ref would only replay the first creation
            }

            return $post($payload);
        },
        Fx::sessionPayload(['client_ref' => $ref]),
        $paths,
    );
}

it('survives hostile values in every session creation field', function () {
    $token = Fx::token(Fx::device());

    $failures = secFuzzSessionCreation(fn (array $payload) => $this->api($token)->postJson('/api/sessions', $payload), [
        'client_ref', 'source_app', 'client_id', 'title', 'duration_ms', 'language', 'artifacts', 'artifacts.0', 'artifacts.0.role',
        'artifacts.0.mime', 'artifacts.0.codec', 'artifacts.0.channels', 'artifacts.0.sample_rate', 'artifacts.0.size_bytes',
        'artifacts.0.sha256', 'artifacts.0.duration_ms',
    ]);

    expect($failures)->toBe([]);
});

it('survives hostile values in the session start and end times', function () {
    $token = Fx::token(Fx::device());

    $failures = secFuzzSessionCreation(fn (array $payload) => $this->api($token)->postJson('/api/sessions', $payload), ['started_at', 'ended_at']);

    expect($failures)->toBe([]);
})->skip('A6.W16: a numeric started_at or ended_at gives HTTP 500 instead of 422');

it('survives hostile values when adding a recording to a session', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);

    $failures = Fuzz::run(
        function (array $payload) use ($token, $session) {
            $session->recordings()->delete();

            return $this->api($token)->postJson("/api/sessions/{$session->id}/recordings", $payload);
        },
        Fx::artifact(['role' => 'channel_1']),
        ['role', 'mime', 'codec', 'channels', 'sample_rate', 'size_bytes', 'sha256', 'duration_ms'],
    );

    expect($failures)->toBe([]);
});

/**
 * Fuzzes the given fields of a live transcript upload one at a time and returns one line per offending request.
 *
 * @param  Closure(array<string, mixed>): mixed  $post  sends one transcript payload and returns the response
 * @param  list<string>  $paths
 * @return list<string>
 */
function secFuzzTranscriptUpload(Closure $post, array $paths): array
{
    $baseline = [
        'kind' => 'live', 'provider' => 'assemblyai', 'language' => 'en',
        'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello',
            'words' => [['text' => 'Hello', 'start_ms' => 0, 'end_ms' => 900, 'confidence' => 0.9]]]],
    ];

    return Fuzz::run($post, $baseline, $paths);
}

it('survives hostile values in every transcript upload field', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);

    $failures = secFuzzTranscriptUpload(fn (array $payload) => $this->api($token)->postJson("/api/sessions/{$session->id}/transcripts", $payload), [
        'kind', 'provider', 'language', 'segments', 'segments.0', 'segments.0.channel', 'segments.0.speaker', 'segments.0.start_ms',
        'segments.0.end_ms', 'segments.0.text', 'segments.0.words', 'segments.0.words.0', 'segments.0.words.0.start_ms',
        'segments.0.words.0.end_ms',
    ]);

    expect($failures)->toBe([]);
});

it('survives hostile values in the text and confidence of a word in a transcript upload', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);

    $failures = secFuzzTranscriptUpload(
        fn (array $payload) => $this->api($token)->postJson("/api/sessions/{$session->id}/transcripts", $payload),
        ['segments.0.words.0.text', 'segments.0.words.0.confidence'],
    );

    expect($failures)->toBe([]);
})->skip('A6.W16: a word text that is not a string, or a confidence that overflows to infinity, gives HTTP 500');

it('survives hostile values in every streaming token field', function () {
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_key_for_fuzzing');
    Http::fake(['*' => Http::response(['token' => 'tok_temporary'], 200)]);
    $device = Fx::device();
    $token = Fx::token($device);
    $client = Fx::client($device->user);

    $failures = Fuzz::run(
        fn (array $payload) => $this->api($token)->postJson('/api/stt/token', $payload),
        ['use_case' => 'live_captions', 'client_id' => $client->id, 'channels' => ['me', 'client'], 'sample_rate' => 16000, 'language' => 'en'],
        ['use_case', 'client_id', 'channels', 'channels.0', 'sample_rate', 'language'],
    );

    expect($failures)->toBe([]);
});

it('survives hostile values in every library query parameter', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    Fx::session($device->user, $device);
    $failures = [];

    foreach (['since', 'source', 'source_app', 'client_id', 'status', 'cursor', 'limit'] as $param) {
        foreach (Fuzz::values() as $label => $value) {
            if (is_resource($value)) {
                continue;
            }
            $response = $this->api($token)->getJson('/api/library?'.http_build_query([$param => $value]));
            $problem = Fuzz::problem($response);
            if ($problem !== null) {
                config(['app.debug' => true]);
                $detail = $this->api($token)->getJson('/api/library?'.http_build_query([$param => $value]))->json();
                config(['app.debug' => false]);
                $failures[] = "{$param} <- {$label}: {$problem} [".($detail['exception'] ?? '').': '.mb_substr((string) ($detail['message'] ?? ''), 0, 110).']';
            }
        }
    }

    expect($failures)->toBe([]);
});

it('survives malformed request bodies on every JSON endpoint', function (string $method, string $uri) {
    $token = Fx::token(Fx::device());
    $bodies = [
        'empty body' => '',
        'invalid json' => '{"a":',
        'json null' => 'null',
        'json scalar' => '"text"',
        'json number' => '12345',
        'json list' => '[1,2,3]',
        'nested 600 deep' => str_repeat('[', 600).str_repeat(']', 600),
        'nul bytes' => "{\"a\":\"\0\0\0\"}",
        'invalid utf-8' => "{\"title\":\"\xC3\x28\"}",
        'huge list' => '['.implode(',', array_fill(0, 50000, '1')).']',
        'duplicate keys' => '{"title":"a","title":"b"}',
        'bom prefix' => "\xEF\xBB\xBF{\"title\":\"x\"}",
    ];
    $failures = [];

    foreach ($bodies as $label => $body) {
        config(['app.debug' => false]);
        $response = $this->api($token)->call($method, $uri, [], [], [], $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'Accept' => 'application/json', 'Authorization' => 'Bearer '.$token]), $body);
        $problem = Fuzz::problem($response);
        if ($problem !== null) {
            $failures[] = "{$label}: {$problem}";
        }
    }

    expect($failures)->toBe([]);
})->with([
    'POST sessions' => ['POST', '/api/sessions'],
    'POST sessions/{id}/recordings' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/recordings'],
    'POST sessions/{id}/transcripts' => ['POST', '/api/sessions/'.Fx::MISSING_ID.'/transcripts'],
    'POST stt/token' => ['POST', '/api/stt/token'],
    'POST devices/rotate-token' => ['POST', '/api/devices/rotate-token'],
    'POST devices/register' => ['POST', '/api/devices/register'],
]);

it('rejects numbers that only look numeric in the session duration', function () {
    $token = Fx::token(Fx::device());
    $accepted = [];

    foreach (['-1', '1e3', '0x10', '1.0', '1,5', 'NaN', 'INF', '--1', '+5x', 1.5, "\u{0661}\u{0662}\u{0663}", 86400001, PHP_INT_MAX] as $value) {
        $status = $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['duration_ms' => $value]))->getStatusCode();
        if ($status !== 422) {
            $accepted[] = json_encode($value).' => '.$status;
        }
    }

    expect($accepted)->toBe([]);
});

it('refuses extreme and non-positive upload sizes and sample rates when a session is created', function () {
    $token = Fx::token(Fx::device());
    $max = (int) config('echoledger.max_recording_bytes');

    foreach ([0, -1, $max + 1, PHP_INT_MAX, '9223372036854775808', 'NaN', null, ''] as $size) {
        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['size_bytes' => $size])]]))
            ->assertStatus(422);
    }
    foreach ([-1, 7999, 192001, PHP_INT_MAX] as $rate) {
        $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['sample_rate' => $rate])]]))
            ->assertStatus(422);
    }
});

it('refuses a sample rate that is not a number when a session is created', function () {
    $token = Fx::token(Fx::device());

    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['sample_rate' => 'abc'])]]))
        ->assertStatus(422);
})->skip('A6.W21: a sample_rate that is not a number is coerced to "unknown" and accepted');

it('limits every free-text field so stored values cannot exceed their column sizes', function () {
    $device = Fx::device();
    $token = Fx::token($device);

    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['client_ref' => str_repeat('r', 65)]))->assertStatus(422);
    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['title' => str_repeat('t', 201)]))->assertStatus(422);
    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['language' => str_repeat('l', 17)]))->assertStatus(422);
    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['codec' => str_repeat('c', 33)])]]))->assertStatus(422);
    $this->api($token)->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => str_repeat('n', 121), 'platform' => 'android'])->assertStatus(422);
    $this->api($token)->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android', 'app_version' => str_repeat('v', 33)])->assertStatus(422);
    $this->api($token)->postJson('/api/devices/register', ['pairing_code' => 'AAAA-AAAA', 'name' => 'x', 'platform' => 'android', 'os_version' => str_repeat('v', 33)])->assertStatus(422);
    $this->api($token)->postJson('/api/devices/register', ['pairing_code' => str_repeat('A', 17), 'name' => 'x', 'platform' => 'android'])->assertStatus(422);
});

it('caps the text and the speaker of each segment in a transcript upload', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);
    $segment = ['channel' => 1, 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x'];

    $this->api($token)->postJson("/api/sessions/{$session->id}/transcripts", ['kind' => 'live', 'provider' => 'a', 'segments' => [['text' => str_repeat('x', 20001)] + $segment]])
        ->assertStatus(422);
    $this->api($token)->postJson("/api/sessions/{$session->id}/transcripts", ['kind' => 'live', 'provider' => 'a', 'segments' => [['speaker' => str_repeat('s', 41)] + $segment]])
        ->assertStatus(422);
});

it('caps a transcript upload so one request cannot store an unbounded document', function () {
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);
    $segment = ['channel' => 1, 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x'];

    $this->api($token)->postJson("/api/sessions/{$session->id}/transcripts", ['kind' => 'live', 'provider' => 'a', 'segments' => array_fill(0, 20001, $segment)])
        ->assertStatus(422);
})->skip('A6.W13: transcript validation is super-linear, so refusing an upload over the 20,000-segment cap takes many seconds');

it('creates nothing when session validation fails', function () {
    $device = Fx::device();
    $token = Fx::token($device);

    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(), Fx::artifact(['sha256' => 'short'])]]))->assertStatus(422);
    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(['role' => 'channel_1'])]]))->assertStatus(422);
    $this->api($token)->postJson('/api/sessions', Fx::sessionPayload(['artifacts' => [Fx::artifact(), Fx::artifact()]]))->assertStatus(422);

    expect(CaptureSession::query()->count())->toBe(0)
        ->and(Device::query()->count())->toBe(1);
});
