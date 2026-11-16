<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ApiCredentials;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistUsage;
use App\Models\Credential;
use App\Models\UsageLedgerEntry;
use App\Services\Assist\RelaySecret;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * The relay's side (v1.9 L1, docs/API_ASSIST.md "Internal"): requests signed as services/assist-relay/src/internal.rs
 * signs them, the catalog it fetches, the reports it sends, and the secret's rotation with its 15-minute overlap.
 */

beforeEach(fn () => assistRelaySetUp());

/** Sends a signed relay request. */
function relayCall(object $test, string $method, string $path, array $body = [], string $secret = ASSIST_SECRET, ?int $timestamp = null): TestResponse
{
    $raw = $method === 'GET' ? '' : (string) json_encode($body);

    return $test->call($method, $path, [], [], [], test()->transformHeadersToServerVars(relayHeaders($method, $path, $raw, $secret, $timestamp)), $raw);
}

/** A live session of a Pro person. */
function relaySession(): AssistSession
{
    $device = assistPerson('pro');

    return AssistSession::query()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'device_id' => $device->id,
        'mode' => 'balanced', 'platform' => 'desktop', 'status' => 'active', 'started_at' => now()]);
}

describe('signatures', function () {
    it('opens the internal API only to a fresh, correct signature, and never to a device token', function () {
        relayCall($this, 'GET', '/api/internal/assist/providers')->assertOk();

        relayCall($this, 'GET', '/api/internal/assist/providers', [], 'another-secret-that-is-long-enough-0123456789')->assertUnauthorized();
        relayCall($this, 'GET', '/api/internal/assist/providers', [], ASSIST_SECRET, now()->getTimestamp() - 61)->assertUnauthorized();
        relayCall($this, 'GET', '/api/internal/assist/providers', [], ASSIST_SECRET, now()->getTimestamp() + 61)->assertUnauthorized();
        // The body is signed: changing it after signing is refused.
        $headers = relayHeaders('POST', '/api/internal/assist/summary', '{"session_id":"x","summary":"a"}');
        $this->call('POST', '/api/internal/assist/summary', [], [], [], $this->transformHeadersToServerVars($headers), '{"session_id":"x","summary":"b"}')->assertUnauthorized();
        // Signed for another path or method: refused.
        $headers = relayHeaders('GET', '/api/internal/assist/usage');
        $this->call('GET', '/api/internal/assist/providers', [], [], [], $this->transformHeadersToServerVars($headers))->assertUnauthorized();
        $headers = relayHeaders('POST', '/api/internal/assist/providers');
        $this->call('GET', '/api/internal/assist/providers', [], [], [], $this->transformHeadersToServerVars($headers))->assertUnauthorized();
        // A device token, with every ability, opens nothing.
        $this->api(Fx::token(Fx::device()))->getJson('/api/internal/assist/providers')->assertUnauthorized();
        $this->getJson('/api/internal/assist/providers')->assertUnauthorized();
    });

    it('accepts the previous secret for 15 minutes after a rotation, and the relay token is signed with the new one', function () {
        $this->freezeTime();
        $new = app(RelaySecret::class)->rotate();
        expect($new)->not->toBe(ASSIST_SECRET)->and(strlen($new))->toBeGreaterThanOrEqual(RelaySecret::MIN_LENGTH);

        relayCall($this, 'GET', '/api/internal/assist/providers', [], $new)->assertOk();
        relayCall($this, 'GET', '/api/internal/assist/providers', [], ASSIST_SECRET)->assertOk();
        $token = $this->api(Fx::token(assistPerson()))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true])->json('relay.token');
        relayClaims($token, $new);

        $this->travel(16)->minutes();
        relayCall($this, 'GET', '/api/internal/assist/providers', [], ASSIST_SECRET)->assertUnauthorized();
        relayCall($this, 'GET', '/api/internal/assist/providers', [], $new)->assertOk();
    });
});

describe('the catalog', function () {
    it('gives the relay the keys of the providers it knows, the models as relay.toml has them and the mode map', function () {
        $vault = app(CredentialVault::class);
        $vault->put('anthropic', 'api_key', 'sk-ant-relay-test-0123456789');
        $vault->put('groq', 'api_key', 'gsk-relay-test-0123456789');
        $vault->put('moonshot', 'api_key', 'moonshot-relay-test-0123456789');
        $vault->put('assemblyai', 'api_key', 'aai-relay-test-0123456789');

        $answer = relayCall($this, 'GET', '/api/internal/assist/providers')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();

        expect($answer['keys'])->toBe(['anthropic' => 'sk-ant-relay-test-0123456789', 'groq' => 'gsk-relay-test-0123456789'])
            ->and($answer['modes']['balanced'])->toBe(['primary' => 'claude-haiku-5-5', 'fallback' => 'openai/gpt-oss-120b', 'upgrade' => 'claude-sonnet-5-5', 'transcript' => 'full', 'max_output_tokens' => 400])
            ->and(array_keys($answer['modes']))->toBe(['fast', 'balanced', 'deep', 'max']);
        // Held until complete where a refusal can come after words have streamed; the options exactly as relay.toml.
        expect($answer['models']['claude-sonnet-5-5'])->toMatchArray(['provider' => 'anthropic', 'hold_until_complete' => true, 'options' => ['effort' => 'low', 'thinking' => 'between_tools']])
            ->and($answer['models']['claude-opus-5-5'])->toMatchArray(['hold_until_complete' => true, 'options' => ['effort' => 'low']])
            ->and($answer['models']['claude-haiku-5-5'])->not->toHaveKey('hold_until_complete')
            ->and($answer['models']['claude-haiku-5-5']['options'])->toBe(['effort' => 'low', 'thinking' => 'disabled'])
            ->and($answer['models']['openai/gpt-oss-120b']['options'])->toBe(['reasoning_effort' => 'low'])
            ->and($answer['models']['gemini-3.8-flash'])->toMatchArray(['enabled' => false, 'options' => ['thinking_budget' => 0]])
            // An empty part is left out, never sent as a list the relay cannot read.
            ->and($answer['models']['gpt-6-luna'])->not->toHaveKey('options');
        foreach ($answer['models'] as $model) {
            expect(in_array($model['provider'], ['anthropic', 'openai', 'gemini', 'groq'], true))->toBeTrue();
        }
    });
});

describe('reports', function () {
    it('stores each suggestion\'s usage once, in the usage ledger too, and its words encrypted', function () {
        $session = relaySession();
        $usage = ['suggestion_id' => 'sug-1', 'session_id' => $session->id, 'model' => 'claude-haiku-5-5', 'provider' => 'anthropic', 'mode' => 'balanced',
            'kind' => 'answer', 'trigger' => 'end_of_turn', 'outcome' => 'complete', 'fallback' => false,
            'tokens' => ['input' => 1200, 'cached' => 3000, 'written' => 0, 'output' => 80], 'cost_usd' => 0.00025, 'estimated' => false,
            'latency' => ['end_of_turn_to_request_ms' => 12.5, 'request_to_first_token_ms' => 410.0, 'total_ms' => 900.0], 'at' => now()->toAtomString()];

        relayCall($this, 'POST', '/api/internal/assist/usage', $usage)->assertOk()->assertJsonPath('stored', true);
        relayCall($this, 'POST', '/api/internal/assist/usage', $usage)->assertOk()->assertJsonPath('stored', false);
        expect(AssistUsage::query()->count())->toBe(1)
            ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->where('report_id', 'assist/sug-1')->sole())->toMatchArray([
                'use_case' => 'live_assist', 'provider' => 'anthropic', 'input_tokens' => 4200, 'output_tokens' => 80, 'user_id' => $session->user_id, 'workspace_id' => $session->workspace_id,
            ]);

        relayCall($this, 'POST', '/api/internal/assist/suggestions', ['suggestion_id' => 'sug-1', 'session_id' => $session->id, 'turn_id' => 't1',
            'kind' => 'answer', 'mode' => 'balanced', 'model' => 'claude-haiku-5-5', 'replaces' => null, 'text' => '**One month** notice.', 'at' => now()->toAtomString()])->assertOk();
        relayCall($this, 'POST', '/api/internal/assist/facts', ['session_id' => $session->id, 'facts' => ['Notice period: one month']])->assertOk();
        relayCall($this, 'POST', '/api/internal/assist/summary', ['session_id' => $session->id, 'summary' => 'They asked about the notice period.'])->assertOk();

        expect(AssistSuggestion::query()->sole()->text)->toBe('**One month** notice.')
            ->and((string) DB::table('assist_suggestions')->value('text'))->not->toContain('One month')
            ->and($session->fresh()->facts)->toBe(['Notice period: one month'])
            ->and((string) DB::table('assist_sessions')->where('id', $session->id)->value('summary'))->not->toContain('notice period');
    });

    it('answers 404 for a session it does not know, which the relay does not send again', function () {
        relayCall($this, 'POST', '/api/internal/assist/summary', ['session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'summary' => 'x'])->assertNotFound();
        relayCall($this, 'POST', '/api/internal/assist/usage', ['suggestion_id' => 's', 'session_id' => 'nope'])->assertStatus(422);
    });
});

describe('the admin panel', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');
    });

    it('generates a relay secret, keeps the old one as the previous for its overlap, and has a Moonshot key', function () {
        Livewire::test(ApiCredentials::class)->assertSee('Live Assist relay')->assertSee('Moonshot')
            ->callFormComponentAction('assist_relay__secret', 'generate_assist_relay_secret')->assertNotified('New relay secret generated');

        $relay = app(RelaySecret::class);
        expect($relay->current())->not->toBe(ASSIST_SECRET)->and($relay->previous())->toBe(ASSIST_SECRET)
            ->and(Credential::query()->where(['provider' => 'assist_relay', 'key' => 'previous_secret'])->exists())->toBeTrue();

        // A pasted secret must be long enough for the relay.
        Livewire::test(ApiCredentials::class)->set('data.assist_relay__secret', 'short-secret')->call('save')->assertHasErrors(['data.assist_relay__secret']);
    });

    it('keeps the relay\'s host and gives the apps wss://<host>/v1/assist', function () {
        Livewire::test(ApiCredentials::class)->assertSet('data.relay_url', 'relay.example.com')
            ->set('data.relay_url', 'https://Relay.Example.org/anything')->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('relay.url'))->toBe('relay.example.org');

        $answer = $this->api(Fx::token(assistPerson()))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true]);
        $answer->assertCreated()->assertJsonPath('relay.url', 'wss://relay.example.org/v1/assist');

        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(ApiCredentials::class)->set('data.relay_url', 'not a host')->call('save')->assertHasErrors(['data.relay_url']);
        expect(app(SettingsRepository::class)->get('relay.url'))->toBe('relay.example.org');
    });
});
