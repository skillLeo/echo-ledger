<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ApiCredentials;
use App\Filament\Admin\Pages\Settings;
use App\Http\Middleware\VerifyAssistRelay;
use App\Jobs\MakeAssistReportJob;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistUsage;
use App\Models\Credential;
use App\Models\UsageLedgerEntry;
use App\Services\Assist\AssistPlans;
use App\Services\Assist\RelaySecret;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
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

it('limits the internal API before it works out any signature', function () {
    foreach (['providers', 'usage', 'suggestions', 'facts', 'summary'] as $name) {
        $middleware = Route::getRoutes()->getByName("api.internal.assist.{$name}")->gatherMiddleware();
        expect(array_search('throttle:assist-relay', $middleware, true))->toBeLessThan(array_search(VerifyAssistRelay::class, $middleware, true));
    }
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

    it('charges a session deleted since, and keeps the rest of it away', function () {
        $session = relaySession();
        $session->delete();
        $usage = ['suggestion_id' => 'sug-late', 'session_id' => $session->id, 'model' => 'claude-haiku-5-5', 'provider' => 'anthropic', 'mode' => 'fast',
            'kind' => 'answer', 'tokens' => ['input' => 10, 'cached' => 0, 'written' => 0, 'output' => 5], 'cost_usd' => 0.5];

        relayCall($this, 'POST', '/api/internal/assist/usage', $usage)->assertOk()->assertJsonPath('stored', true);
        expect(AssistUsage::query()->where('assist_session_id', $session->id)->count())->toBe(1)
            ->and(UsageLedgerEntry::query()->withoutGlobalScopes()->where('report_id', 'assist/sug-late')->count())->toBe(1);
        // Deleting a session never gives back what it spent.
        expect(app(AssistPlans::class)->left($session->workspace_id, $session->user_id, false)['cents_left'])->toBe(1450);

        relayCall($this, 'POST', '/api/internal/assist/suggestions', ['suggestion_id' => 'sug-late', 'session_id' => $session->id, 'text' => 'x'])->assertNotFound();
        relayCall($this, 'POST', '/api/internal/assist/facts', ['session_id' => $session->id, 'facts' => ['x']])->assertNotFound();
        relayCall($this, 'POST', '/api/internal/assist/summary', ['session_id' => $session->id, 'summary' => 'x'])->assertNotFound();
        expect(AssistSuggestion::query()->count())->toBe(0);
    });

    it('answers stored false to a repeat that raced the first one', function () {
        $session = relaySession();
        // The same suggestion stored by another request between this one's check and its insert.
        $raced = false;
        AssistUsage::creating(function (AssistUsage $usage) use (&$raced): void {
            if (! $raced) {
                $raced = true;
                DB::table('assist_usage')->insert(['assist_session_id' => $usage->assist_session_id, 'workspace_id' => $usage->workspace_id, 'user_id' => $usage->user_id,
                    'suggestion_id' => $usage->suggestion_id, 'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'fast', 'kind' => 'answer',
                    'cost_usd' => 0.01, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        relayCall($this, 'POST', '/api/internal/assist/usage', ['suggestion_id' => 'sug-race', 'session_id' => $session->id, 'model' => 'claude-haiku-5-5',
            'provider' => 'anthropic', 'mode' => 'fast', 'kind' => 'answer', 'tokens' => ['input' => 1, 'cached' => 0, 'written' => 0, 'output' => 1], 'cost_usd' => 0.01])
            ->assertOk()->assertJsonPath('stored', false);
        // Nothing of this request's is left (the other request's row is its own: here it went with this transaction).
        expect(UsageLedgerEntry::query()->withoutGlobalScopes()->where('report_id', 'assist/sug-race')->count())->toBe(0);
    });

    it('keeps the newest facts ledger and summary, whatever order they arrive in', function () {
        $session = relaySession();
        $post = fn (string $what, mixed $value, string $at) => relayCall($this, 'POST', "/api/internal/assist/{$what}",
            ['session_id' => $session->id, $what => $value, 'at' => $at])->assertOk();

        $post('facts', ['Them: budget 8,000 USD (minute 2)', 'Me: start in January'], '2026-11-17T10:05:00Z')->assertJsonPath('stored', true);
        $post('facts', ['Them: budget 8,000 USD (minute 2)'], '2026-11-17T10:03:00Z')->assertJsonPath('stored', false);
        $post('summary', 'Up to minute 10.', '2026-11-17T10:10:00Z')->assertJsonPath('stored', true);
        $post('summary', 'Up to minute 5.', '2026-11-17T10:05:00Z')->assertJsonPath('stored', false);

        $session->refresh();
        expect($session->facts)->toBe(['Them: budget 8,000 USD (minute 2)', 'Me: start in January'])
            ->and($session->facts_at->toAtomString())->toBe('2026-11-17T10:05:00+00:00')
            ->and($session->summary)->toBe('Up to minute 10.')->and($session->summary_at->toAtomString())->toBe('2026-11-17T10:10:00+00:00');
    });

    it('makes the report about 30 seconds after the end, and again when the relay posts after it', function () {
        $device = assistPerson('pro');
        $api = $this->api(Fx::token($device));
        $id = $api->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])->json('session.id');
        Queue::fake();
        $api->postJson("/api/assist/sessions/{$id}/end")->assertOk();
        Queue::assertPushed(MakeAssistReportJob::class, fn (MakeAssistReportJob $job): bool => $job->sessionId === $id
            && $job->delay instanceof DateTimeInterface && abs(now()->diffInSeconds($job->delay) - 30) <= 1);
        $api->getJson("/api/assist/sessions/{$id}/report")->assertNotFound()->assertJsonPath('code', 'report_not_ready');

        (new MakeAssistReportJob($id))->handle();
        expect($api->getJson("/api/assist/sessions/{$id}/report")->assertOk()->json('summary'))->toBeNull();

        // A late summary marks it stale and queues it again; the one made before is shown meanwhile.
        relayCall($this, 'POST', '/api/internal/assist/summary', ['session_id' => $id, 'summary' => 'The last minute.', 'at' => now()->toAtomString()])->assertOk();
        Queue::assertPushed(MakeAssistReportJob::class, 2);
        $api->getJson("/api/assist/sessions/{$id}")->assertJsonPath('report', 'queued');
        $api->getJson("/api/assist/sessions/{$id}/report")->assertOk();
        (new MakeAssistReportJob($id))->handle();
        expect($api->getJson("/api/assist/sessions/{$id}/report")->json('summary'))->toBe('The last minute.');
        $api->getJson("/api/assist/sessions/{$id}")->assertJsonPath('report', 'ready');

        // A post while a report is being made: that report is kept for now and made again.
        $session = AssistSession::query()->withoutGlobalScopes()->find($id);
        $session->forceFill(['report_status' => 'queued'])->save();
        $raced = false;
        AssistSession::retrieved(function (AssistSession $read) use ($id, &$raced): void {
            // Right after the job read the session, the relay's post counts once more.
            if ($read->id === $id && ! $raced) {
                $raced = true;
                DB::table('assist_sessions')->where('id', $id)->increment('report_rev');
            }
        });
        (new MakeAssistReportJob($id))->handle();
        expect($session->fresh()->report_status)->toBe('queued');
        Queue::assertPushed(MakeAssistReportJob::class, 3);
    });

    it('answers 404 for a session it does not know, which the relay does not send again', function () {
        relayCall($this, 'POST', '/api/internal/assist/summary', ['session_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV', 'summary' => 'x'])->assertNotFound();
        relayCall($this, 'POST', '/api/internal/assist/usage', ['suggestion_id' => 's', 'session_id' => 'nope'])->assertStatus(422);
    });
});

describe('rotating the secret', function () {
    it('signs relay tokens with the previous secret during the overlap, until the relay signs with the new one', function () {
        $device = assistPerson('pro');
        $relay = app(RelaySecret::class);
        $new = $relay->rotate('the-new-relay-secret-0123456789-abcdefghij');
        $token = fn (): string => $this->api(Fx::token($device))->postJson('/api/assist/sessions', ['platform' => 'android', 'practice' => true])
            ->assertCreated()->json('relay.token');
        $end = fn (string $token, string $secret = ASSIST_SECRET) => $this->api(Fx::token($device))
            ->postJson('/api/assist/sessions/'.relayClaims($token, $secret)['sid'].'/end')->assertOk();

        // The relay may not have the new secret yet: tokens are signed with the old one, which it has.
        $first = $token();
        relayClaims($first, ASSIST_SECRET);
        $end($first);
        // A request the relay signed with the old one changes nothing.
        relayCall($this, 'GET', '/api/internal/assist/providers', [], ASSIST_SECRET)->assertOk();
        $second = $token();
        relayClaims($second, ASSIST_SECRET);
        $end($second);

        // Its first request signed with the new one: from now on the tokens are signed with the new one.
        relayCall($this, 'GET', '/api/internal/assist/providers', [], $new)->assertOk();
        $third = $token();
        expect(relayClaims($third, $new)['practice'])->toBeTrue();
        $end($third, $new);

        // After the overlap the new one signs, whatever the relay did.
        $newer = $relay->rotate('the-newer-relay-secret-0123456789-abcdefgh');
        $this->travel(16)->minutes();
        expect(relayClaims($token(), $newer)['practice'])->toBeTrue();
    });
});

describe('the admin panel', function () {
    beforeEach(function () {
        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');
    });

    it('generates a relay secret, shows it once in a modal with a copy box, keeps the old one for its overlap, and has a Moonshot key', function () {
        $page = Livewire::test(ApiCredentials::class)->assertSee('Live Assist relay')->assertSee('Moonshot')
            ->callFormComponentAction('assist_relay__secret', 'generate_assist_relay_secret');

        $relay = app(RelaySecret::class);
        expect($relay->current())->not->toBe(ASSIST_SECRET)->and($relay->previous())->toBe(ASSIST_SECRET)
            ->and(Credential::query()->where(['provider' => 'assist_relay', 'key' => 'previous_secret'])->exists())->toBeTrue();
        // Shown in the modal, never in a notification (stored notifications outlive the page), and forgotten by the page.
        $page->assertActionMounted('relaySecretShown')->assertActionDataSet(['secret' => $relay->current()])
            ->assertMountedActionModalSee('It is not shown again')->assertSet('newRelaySecret', null)->assertNotNotified();
        $page->callMountedAction()->assertActionNotMounted('relaySecretShown');
        expect(DB::table('notifications')->count())->toBe(0);

        // A pasted secret must be long enough for the relay.
        Livewire::test(ApiCredentials::class)->set('data.assist_relay__secret', 'short-secret')->call('save')->assertHasErrors(['data.assist_relay__secret']);
    });

    it('keeps the relay\'s host and gives the apps wss://<host>/v1/assist', function () {
        // Since 1.9.0-q the address is on Admin › Settings › General, beside the Live Assist connection.
        Livewire::test(Settings::class)->assertSet('data.relay_url', 'relay.example.com')
            ->set('data.relay_url', 'https://Relay.Example.org/anything')->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('relay.url'))->toBe('relay.example.org');

        $answer = $this->api(Fx::token(assistPerson()))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'practice' => true]);
        $answer->assertCreated()->assertJsonPath('relay.url', 'wss://relay.example.org/v1/assist');

        Filament::setCurrentPanel('admin');
        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(Settings::class)->set('data.relay_url', 'not a host')->call('save')->assertHasErrors(['data.relay_url']);
        expect(app(SettingsRepository::class)->get('relay.url'))->toBe('relay.example.org');
    });
});
