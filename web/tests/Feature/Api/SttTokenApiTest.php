<?php

declare(strict_types=1);

use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
    $this->configureAssemblyAi();
    Http::fake(['streaming.assemblyai.com/v3/token*' => Http::response(['token' => 'tmp_stream_token'], 200)]);
});

/** @return array<string, string> parsed query of a grant's ws_url */
function wsQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

/** Attach $terms as keyterms of a new client of the device's owner. */
function clientWithTerms(object $test, array $terms, array $attributes = []): Client
{
    $client = Client::factory()->create(array_merge(['user_id' => $test->device->user_id], $attributes));
    foreach ($terms as $term) {
        ClientKeyterm::query()->firstOrCreate(['client_id' => $client->id, 'term' => mb_substr($term, 0, 100)]);
    }

    return $client;
}

describe('AssemblyAI', function () {
    it('mints one short-lived token and hands the same URL to every channel', function () {
        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me', 'client']])->assertOk();

        $grants = $response->json('grants');
        expect(array_column($grants, 'channel'))->toBe(['me', 'client'])
            ->and($grants[0]['ws_url'])->toBe($grants[1]['ws_url'])
            ->and($grants[0])->toMatchArray(['transport' => 'websocket', 'protocol' => 'assemblyai_v3', 'max_session_seconds' => 10800])
            ->and($grants[0]['audio'])->toBe(['encoding' => 'pcm_s16le', 'sample_rate' => 16000, 'channels' => 1, 'frame_ms' => 50]);
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://streaming.assemblyai.com/v3/token?expires_in_seconds=60&max_session_duration_seconds=10800');
    });

    it('builds a ws_url the device can open as is', function () {
        $url = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'sample_rate' => 48000])->assertOk()->json('grants.0.ws_url');

        expect($url)->toStartWith('wss://streaming.assemblyai.com/v3/ws?')
            ->and(wsQuery($url))->toBe([
                'speech_model' => 'universal-3-6-pro', 'encoding' => 'pcm_s16le', 'sample_rate' => '48000',
                'inactivity_timeout' => '120', 'token' => 'tmp_stream_token',
            ]);
    });

    it('follows the configured streaming model', function () {
        app(SettingsRepository::class)->set('assemblyai.streaming_model', 'universal-streaming-english');

        $url = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->json('grants.0.ws_url');

        expect(wsQuery($url)['speech_model'])->toBe('universal-streaming-english');
    });

    it('expires the grant within a minute', function () {
        $this->freezeTime();

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

        expect(strtotime($response->json('expires_at')))->toBe(now()->addSeconds(60)->getTimestamp())
            ->and(strtotime($response->json('grants.0.redeem_by')))->toBe(now()->addSeconds(60)->getTimestamp());
    });

    it('is never cacheable', function () {
        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

        expect($response->headers->get('Cache-Control'))->toContain('no-store');
    });

    it('sends the client\'s keyterms in the opening configuration message', function () {
        $client = clientWithTerms($this, ['Acme Holdings', 'Farah Khan']);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $client->id])->assertOk();

        expect($response->json('keyterms'))->toBe(['Acme Holdings', 'Farah Khan'])
            ->and($response->json('grants.0.init_messages'))->toBe([['type' => 'UpdateConfiguration', 'keyterms_prompt' => ['Acme Holdings', 'Farah Khan']]]);
    });

    it('sends no opening message when there is nothing to tune', function () {
        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

        expect($response->json('keyterms'))->toBe([])->and($response->json('grants.0.init_messages'))->toBe([]);
    });

    it('applies the keyterm policy to what a device is told', function () {
        $terms = ['Acme Holdings', 'ACME holdings', '  padded   term ', 'A<b>C', str_repeat('y', 51), 'a b c d e f g'];
        foreach (range(1, 130) as $i) {
            $terms[] = "Name {$i}";
        }
        $client = clientWithTerms($this, $terms);

        $sent = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $client->id])->json('keyterms');

        expect(count($sent))->toBe(100)
            ->and($sent)->toContain('Acme Holdings', 'padded term', 'AbC')
            ->and($sent)->not->toContain('ACME holdings', str_repeat('y', 51), 'a b c d e f g');
        foreach ($sent as $term) {
            expect(mb_strlen($term))->toBeLessThanOrEqual(50);
        }
    });

    it('treats a client of another owner as not found, so their terms cannot leak', function () {
        $stranger = Client::factory()->create();
        ClientKeyterm::query()->create(['client_id' => $stranger->id, 'term' => 'Secret Codename']);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $stranger->id]);

        $response->assertNotFound();
        expect($response->getContent())->not->toContain('Secret Codename');
    });

    it('answers 404 for a client that does not exist', function () {
        $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])->assertNotFound();
    });
});

describe('channel selection', function () {
    it('honours the requested channels once each, in order', function (array $requested, array $expected) {
        $channels = array_column($this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => $requested])->assertOk()->json('grants'), 'channel');

        expect($channels)->toBe($expected);
    })->with([
        'both' => [['me', 'client'], ['me', 'client']],
        'reversed' => [['client', 'me'], ['client', 'me']],
        'me only' => [['me'], ['me']],
        'client only' => [['client'], ['client']],
        'duplicates collapse' => [['me', 'me'], ['me']],
    ]);

    it('defaults to both channels', function () {
        expect(array_column($this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->json('grants'), 'channel'))->toBe(['me', 'client']);
    });

    it('serves dictation on the microphone channel only, whatever is asked', function (array $payload) {
        $response = $this->postJson('/api/stt/token', ['use_case' => 'dictation'] + $payload)->assertOk();

        expect(array_column($response->json('grants'), 'channel'))->toBe(['me']);
    })->with([[[]], [['channels' => ['client']]], [['channels' => ['me', 'client']]]]);

    it('forces the client channel only when the admin restricts captions that way', function () {
        app(SettingsRepository::class)->set('captions.channels', 'client_only');

        $live = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me', 'client']])->json('grants');
        $dictation = $this->postJson('/api/stt/token', ['use_case' => 'dictation'])->json('grants');

        expect(array_column($live, 'channel'))->toBe(['client'])->and(array_column($dictation, 'channel'))->toBe(['me']);
    });

    it('answers "off" without calling any provider when the admin disabled captions', function () {
        app(SettingsRepository::class)->set('captions.channels', 'off');

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

        expect($response->json())->toBe(['provider' => null, 'mode' => 'off', 'expires_at' => null, 'keyterms' => [], 'grants' => []]);
        Http::assertNothingSent();
    });

    it('still serves dictation when live captions are off', function () {
        app(SettingsRepository::class)->set('captions.channels', 'off');

        $this->postJson('/api/stt/token', ['use_case' => 'dictation'])->assertOk()->assertJsonPath('mode', 'cloud');
    });
});

describe('validation', function () {
    it('rejects a request without a use case, or with one that is not streaming', function (array $payload) {
        $this->postJson('/api/stt/token', $payload)->assertStatus(422)->assertJsonValidationErrors(['use_case']);
        Http::assertNothingSent();
    })->with([[[]], [['use_case' => 'final_transcript']], [['use_case' => 'voice_note']], [['use_case' => 'Live_Captions']], [['use_case' => 7]]]);

    it('rejects out-of-range options', function (array $payload, string $field) {
        $this->postJson('/api/stt/token', ['use_case' => 'live_captions'] + $payload)->assertStatus(422)->assertJsonValidationErrors([$field]);
        Http::assertNothingSent();
    })->with([
        'unknown channel' => [['channels' => ['me', 'speaker']], 'channels.1'],
        'empty channel list' => [['channels' => []], 'channels'],
        'three channels' => [['channels' => ['me', 'client', 'me']], 'channels'],
        'channels not a list' => [['channels' => 'me'], 'channels'],
        'channel not a string' => [['channels' => [1]], 'channels.0'],
        'unsupported rate' => [['sample_rate' => 12345], 'sample_rate'],
        'rate as text' => [['sample_rate' => 'high'], 'sample_rate'],
        'client id too short' => [['client_id' => 'abc'], 'client_id'],
        'language too long' => [['language' => str_repeat('x', 17)], 'language'],
    ]);

    it('accepts every supported sample rate', function (int $rate) {
        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'sample_rate' => $rate])->assertOk();

        expect($response->json('grants.0.audio.sample_rate'))->toBe($rate);
    })->with([8000, 16000, 24000, 44100, 48000]);

    it('accepts null for the optional fields', function () {
        $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => null, 'channels' => null, 'sample_rate' => null, 'language' => null])->assertOk();
    });
});

describe('ElevenLabs', function () {
    beforeEach(function () {
        app(SettingsRepository::class)->set('provider.live_captions', 'elevenlabs');
        app(CredentialVault::class)->put('elevenlabs', 'api_key', 'xi_test_key_1234567890');
        $this->tokens = ['tok_me', 'tok_client'];
        Http::fake(function (HttpRequest $request) {
            return Http::response(['token' => array_shift($this->tokens) ?? 'tok_extra'], 200);
        });
    });

    it('mints a separate single-use token for every channel', function () {
        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me', 'client']])->assertOk();

        $grants = $response->json('grants');
        expect($response->json('provider'))->toBe('elevenlabs')
            ->and(wsQuery($grants[0]['ws_url'])['token'])->toBe('tok_me')
            ->and(wsQuery($grants[1]['ws_url'])['token'])->toBe('tok_client')
            ->and($grants[0])->toMatchArray(['protocol' => 'elevenlabs_realtime', 'init_messages' => [], 'max_session_seconds' => null])
            ->and($grants[0]['audio'])->toBe(['encoding' => 'pcm_s16le', 'sample_rate' => 16000, 'channels' => 1, 'frame_ms' => 100]);
        Http::assertSentCount(2);
        Http::assertSent(fn (HttpRequest $r) => $r->method() === 'POST' && $r->url() === 'https://api.elevenlabs.io/v1/single-use-token/realtime_scribe' && $r->hasHeader('xi-api-key', 'xi_test_key_1234567890'));
    });

    it('repeats the keyterms parameter rather than indexing it, and URL-encodes each', function () {
        $client = clientWithTerms($this, ['Acme & Sons', 'Farah Khan', 'naïve café']);

        $url = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me'], 'client_id' => $client->id])->json('grants.0.ws_url');

        expect($url)->toStartWith('wss://api.elevenlabs.io/v1/speech-to-text/realtime?')
            ->and(substr_count($url, 'keyterms='))->toBe(3)
            ->and($url)->not->toContain('keyterms%5B')->not->toContain('keyterms[')
            ->and($url)->toContain('keyterms=Acme%20%26%20Sons')->toContain('keyterms=Farah%20Khan')->toContain('keyterms=na%C3%AFve%20caf%C3%A9');
    });

    it('applies the stricter realtime keyterm limits of this provider', function () {
        $terms = ['short term', 'this one is longer than twenty characters'];
        foreach (range(1, 80) as $i) {
            $terms[] = "T{$i}";
        }
        $client = clientWithTerms($this, $terms);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me'], 'client_id' => $client->id])->assertOk();

        $sent = $response->json('keyterms');
        expect(count($sent))->toBe(50)->and($sent)->toContain('short term')->and($sent)->not->toContain('this one is longer than twenty characters');
        foreach ($sent as $term) {
            expect(mb_strlen($term))->toBeLessThanOrEqual(20);
        }
    });

    it('passes the language through, preferring the request over the client over the default', function (?string $request, ?string $client, ?string $default, ?string $expected) {
        app(SettingsRepository::class)->set('default_language', $default);
        $model = Client::factory()->create(['user_id' => $this->device->user_id, 'language' => $client]);

        $url = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me'], 'client_id' => $model->id, 'language' => $request])->json('grants.0.ws_url');

        $query = wsQuery($url);
        $expected === null ? expect($query)->not->toHaveKey('language_code') : expect($query['language_code'])->toBe($expected);
    })->with([
        'request wins' => ['ur', 'fr', 'de', 'ur'],
        'client next' => [null, 'fr', 'de', 'fr'],
        'default last' => [null, null, 'de', 'de'],
        'auto' => [null, null, null, null],
    ]);

    it('follows the configured realtime model', function () {
        app(SettingsRepository::class)->set('elevenlabs.realtime_model_id', 'scribe_v2_realtime_custom');

        $url = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me']])->json('grants.0.ws_url');

        expect(wsQuery($url)['model_id'])->toBe('scribe_v2_realtime_custom');
    });
});

describe('Azure', function () {
    beforeEach(function () {
        app(SettingsRepository::class)->setMany(['provider.live_captions' => 'azure_mai', 'azure.region' => 'westeurope']);
        app(CredentialVault::class)->put('azure', 'api_key', 'azure_test_key_1234567890');
        Http::fake(['westeurope.api.cognitive.microsoft.com/*' => Http::response('sts-token-value', 200)]);
    });

    it('hands out a speech SDK token with its lifetime and refresh point', function () {
        $client = clientWithTerms($this, ['Acme Holdings']);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions', 'channels' => ['me', 'client'], 'client_id' => $client->id])->assertOk();

        expect($response->json('provider'))->toBe('azure_mai')
            ->and($response->json('grants.0'))->toMatchArray([
                'channel' => 'me', 'transport' => 'azure_speech_sdk', 'token' => 'sts-token-value', 'region' => 'westeurope',
                'expires_in' => 600, 'refresh_after' => 540, 'phrases' => ['Acme Holdings'],
            ]);
        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://westeurope.api.cognitive.microsoft.com/sts/v1.0/issueToken' && $r->hasHeader('Ocp-Apim-Subscription-Key', 'azure_test_key_1234567890'));
    });

    it('answers 503 with a configuration hint when the region is missing', function () {
        app(SettingsRepository::class)->set('azure.region', null);

        $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertStatus(503)->assertJsonPath('message', fn (string $m) => str_contains($m, 'region'));
    });
});

describe('on-device providers', function () {
    it('returns an empty on-device answer so the app falls back to its local engine', function (string $provider) {
        app(SettingsRepository::class)->set('provider.live_captions', $provider);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

        expect($response->json())->toBe(['provider' => $provider, 'mode' => 'on_device', 'expires_at' => null, 'keyterms' => [], 'grants' => []]);
        Http::assertNothingSent();
    })->with(['whispercpp_local', 'parakeet_local']);
});

describe('provider failures', function () {
    it('answers 503 and names what to configure when the key is missing', function () {
        app(CredentialVault::class)->forget('assemblyai', 'api_key');

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertStatus(503);

        expect($response->json('message'))->toContain('assemblyai.api_key');
        Http::assertNothingSent();
    });

    it('answers 502 without repeating the provider\'s error body when it refuses', function () {
        $this->resetHttpFakes();
        Http::fake(['streaming.assemblyai.com/*' => Http::response(['error' => 'Invalid API key sk_provider_internal_detail'], 401)]);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertStatus(502);

        expect($response->getContent())->not->toContain('sk_provider_internal_detail')->not->toContain('aai_test_key_1234567890');
    });

    it('answers with a JSON server error that leaks nothing when the provider is unreachable', function () {
        $this->resetHttpFakes();
        Http::fake(fn () => throw new ConnectionException('cURL error 6: Could not resolve host: streaming.assemblyai.com'));

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions']);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(500)->toBeLessThan(600);
        expect($response->headers->get('Content-Type'))->toContain('application/json');
        expect($response->getContent())->not->toContain('aai_test_key_1234567890')->not->toContain('cURL');
    });

    it('tells the device the provider is unavailable rather than that the server is broken', function () {
        $this->resetHttpFakes();
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        $status = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->getStatusCode();

        // 502/503/504 say "upstream trouble, retry"; a bare 500 reads as a server bug.
        expect($status)->toBeIn([502, 503, 504]);
    })->skip('A6.W16: a provider connection failure on stt/token gives 500 instead of 502');
});

describe('secrecy', function () {
    it('never puts a provider key in a response, whichever provider is used', function (string $provider, string $secret) {
        $vault = app(CredentialVault::class);
        $vault->put('assemblyai', 'api_key', 'SECRET-assemblyai-key-0001');
        $vault->put('elevenlabs', 'api_key', 'SECRET-elevenlabs-key-0002');
        $vault->put('azure', 'api_key', 'SECRET-azure-key-0003');
        app(SettingsRepository::class)->setMany(['provider.live_captions' => $provider, 'azure.region' => 'westeurope']);
        Http::fake(['*' => Http::response(['token' => 'short-lived'], 200)]);

        $response = $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

        expect($response->getContent())->not->toContain('SECRET-');
        expect(json_encode($response->headers->all()))->not->toContain('SECRET-');
    })->with([['assemblyai', ''], ['elevenlabs', ''], ['azure_mai', '']]);
});

describe('limits', function () {
    it('allows thirty requests a minute per device and then answers 429', function () {
        $this->freezeTime();
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();
        }

        $blocked = $this->postJson('/api/stt/token', ['use_case' => 'live_captions']);

        $blocked->assertStatus(429);
        expect((int) $blocked->headers->get('Retry-After'))->toBeGreaterThan(0);
    });

    it('does not let one device\'s requests use up another\'s budget', function () {
        $this->freezeTime();
        foreach (range(1, 30) as $_) {
            $this->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();
        }
        [, $other] = $this->enroll();

        $this->asToken($other)->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();
    });

    it('requires the stt:token ability', function () {
        [, $limited] = $this->enroll($this->device->user, ['library:read', 'config:read']);

        $this->asToken($limited)->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertForbidden();
        Http::assertNothingSent();
    });
});
