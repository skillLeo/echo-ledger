<?php

declare(strict_types=1);

use App\Models\AssistSession;
use App\Models\AssistUsage;
use App\Models\Device;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Assist\AssistAskAi;
use App\Services\Assist\AssistBudget;
use App\Services\Assist\AssistPlans;
use App\Services\Assist\AssistReportAi;
use App\Services\Assist\Http\Ai\ModelGateway;
use App\Services\Assist\RelayHealth;
use App\Services\Assist\RelaySecret;
use App\Services\CredentialVault;
use App\Services\InterfaceFlags;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Assist\Support\FakeModels;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Shared by the Live Assist tests (v1.9 L1): a person with a device on a plan, the flag on for them, the relay set up.
 */

const ASSIST_SECRET = 'relay-secret-for-the-tests-0123456789-abcdef';

/**
 * The relay set up: its host and its secret; and the captions' provider, which answers with a streaming key. With
 * [$healthy] the relay answers its health check (1.9.0-q: Automatic picks it while it does); false leaves its answers
 * to the test. Its host resolves to a public address: tests never ask DNS.
 */
function assistRelaySetUp(bool $healthy = true): void
{
    app(SettingsRepository::class)->set('relay.url', 'relay.example.com');
    app(RelaySecret::class)->rotate(ASSIST_SECRET);
    app(CredentialVault::class)->put('soniox', 'api_key', 'snx-test-key-0123456789');
    RelayHealth::$resolveUsing = fn (string $host): array => ['93.184.215.14'];
    Http::fake(['api.soniox.com/*' => Http::response(['api_key' => 'temp:abc', 'expires_at' => '2026-12-01T10:10:00Z'])]);
    if ($healthy) {
        Http::fake(['relay.example.com/healthz' => Http::response(['status' => 'ok', 'version' => '1.0.2'])]);
        app(RelayHealth::class)->record(['ok' => true, 'version' => '1.0.2']);
    }
}

/** A person on [$plan] with a device, and the live_assist flag on for them (unless [$flag] is false). */
function assistPerson(string $plan = 'pro', bool $flag = true): Device
{
    $user = Fx::user();
    Workspace::query()->whereKey($user->current_workspace_id)->update([
        'plan_id' => Plan::query()->where('key', $plan)->value('id'),
        'plan_expires_at' => $plan === Plan::FREE ? null : now()->addDays(30),
    ]);
    if ($flag) {
        $flags = app(InterfaceFlags::class);
        $current = $flags->rules()['live_assist'] ?? null;
        $flags->set('live_assist', false, 0, [], [...array_map('intval', $current?->user_ids ?? []), $user->id]);
    }

    return Fx::device($user->fresh(), ['workspace_id' => $user->current_workspace_id]);
}

/**
 * The claims of a relay token, after checking its header and signature as the relay does.
 *
 * @return array<string, mixed>
 */
function relayClaims(string $token, string $secret = ASSIST_SECRET): array
{
    $decode = fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);
    [$header, $payload, $signature] = explode('.', $token);
    expect(json_decode($decode($header), true))->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and(hash_equals(hash_hmac('sha256', $header.'.'.$payload, $secret, true), $decode($signature)))->toBeTrue();

    return json_decode($decode($payload), true);
}

/** The headers of a relay request, signed as services/assist-relay/src/internal.rs signs it. */
function relayHeaders(string $method, string $path, string $body = '', string $secret = ASSIST_SECRET, ?int $timestamp = null): array
{
    $timestamp ??= now()->getTimestamp();

    return [
        'X-Assist-Timestamp' => (string) $timestamp,
        'X-Assist-Signature' => hash_hmac('sha256', $timestamp."\n".$method."\n".$path."\n".$body, $secret),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}

/**
 * The report's model, faked: answers [$data] (a good default) and remembers what it read. Bound for AssistReportAi.
 *
 * @param  array<string, mixed>|null  $data
 */
function fakeAssistReportAi(?array $data = null, ?Throwable $fails = null): object
{
    $fake = new class($data, $fails) implements AssistReportAi
    {
        /** @var list<array<string, mixed>> */
        public array $inputs = [];

        /** @var list<string> what the budget check measured */
        public array $prompts = [];

        public function __construct(public ?array $data, public ?Throwable $fails) {}

        public function promptText(array $input): string
        {
            return $this->prompts[] = (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        }

        public function write(array $input): array
        {
            $this->inputs[] = $input;
            if ($this->fails !== null) {
                throw $this->fails;
            }

            return ['data' => $this->data ?? [
                'summary' => 'They asked about your notice period and salary.',
                'summary_translation' => 'Unhon ne notice period aur salary ka poocha.',
                'qa' => [['index' => 1, 'verdict' => 'good', 'better_answer' => ''], ['index' => 2, 'verdict' => 'weak', 'better_answer' => 'I am looking for 300,000 rupees a month.']],
                'mistakes' => [['said' => 'I have five years of Laravel', 'fact' => 'Me: three years of Laravel (minute 1)']],
                'promises' => ['Send my portfolio by Friday'],
                'grammar' => [['said' => 'I am working here since 2022', 'better' => 'I have been working here since 2022', 'why' => 'since needs the perfect'], ['said' => '', 'better' => 'x', 'why' => 'x']],
                'action_items' => ['Send the portfolio by Friday', 'Ask about the start date'],
                'follow_up_email' => ['subject' => 'Thank you', 'body' => 'Thank you for your time today.'],
            ], 'model' => 'claude-sonnet-5-5', 'tokens' => ['input' => 10000, 'cached' => 2000, 'written' => 1000, 'output' => 1500]];
        }
    };
    app()->instance(AssistReportAi::class, $fake);

    return $fake;
}

/**
 * The Ask model, faked: answers [$data] (an answer resting on lines 2 and 1) and remembers what it read.
 *
 * @param  array<string, mixed>|null  $data
 */
function fakeAssistAskAi(?array $data = null, ?Throwable $fails = null): object
{
    $fake = new class($data, $fails) implements AssistAskAi
    {
        /** @var list<array<string, mixed>> */
        public array $inputs = [];

        public function __construct(public ?array $data, public ?Throwable $fails) {}

        public function promptText(array $input): string
        {
            return (string) json_encode($input, JSON_UNESCAPED_UNICODE);
        }

        public function ask(array $input): array
        {
            $this->inputs[] = $input;
            if ($this->fails !== null) {
                throw $this->fails;
            }

            return ['data' => $this->data ?? ['answer' => 'They expect 300,000 rupees.', 'lines' => [2, 1, 2, 99]],
                'model' => 'claude-haiku-5-5', 'tokens' => ['input' => 2000, 'cached' => 0, 'written' => 0, 'output' => 100]];
        }
    };
    app()->instance(AssistAskAi::class, $fake);

    return $fake;
}

/** @return array{0: TestResponse, 1: string} a started HTTP-mode session's API client and id */
function httpSession(object $test, string $plan = 'pro', array $extra = []): array
{
    $api = (fn () => $this->api(Fx::token(assistPerson($plan))))->call($test);
    $start = $api->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'balanced', 'transports' => ['http'], ...$extra])->assertCreated();

    return [$api, (string) $start->json('session.id')];
}

/** The events of a streamed answer: [['event' => 'error', 'data' => [...]], …], keep-alive comments left out. */
function events(string $body): array
{
    $events = [];
    foreach (preg_split("/\n\n/", trim($body)) as $block) {
        if (! preg_match('/^event: (\S+)\ndata: (.*)$/s', $block, $m)) {
            continue;
        }
        $events[] = ['event' => $m[1], 'data' => json_decode($m[2], true)];
    }

    return $events;
}

/**
 * One message of HTTP mode posted, and the events its stream gave.
 *
 * @param  array<string, mixed>  $message
 * @return list<array{event: string, data: array<string, mixed>}>
 */
function assistLive(object $api, string $id, array $message): array
{
    return events($api->postJson("/api/assist/sessions/{$id}/live", $message)->assertOk()->streamedContent());
}

/** The names of the events, in order: ['suggestion_start', 'token', 'suggestion_end', 'end']. */
function assistNames(array $events): array
{
    return array_column($events, 'event');
}

/** The models of HTTP mode, scripted (Tests\Feature\Assist\Support\FakeModels): no network, no keys. */
function fakeAssistModels(): FakeModels
{
    $fake = new FakeModels;
    app()->instance(ModelGateway::class, $fake);

    return $fake;
}

/** The session row of an HTTP-mode session. */
function assistSessionOf(string $id): AssistSession
{
    return AssistSession::query()->withoutGlobalScopes()->findOrFail($id);
}

/** Spends [$usd] of the person's month already, so that little is left. */
function assistSpend(string $id, float $usd): void
{
    $session = assistSessionOf($id);
    AssistUsage::query()->create([
        'assist_session_id' => $id, 'workspace_id' => $session->workspace_id, 'user_id' => $session->user_id, 'suggestion_id' => 'spent-'.uniqid(),
        'provider' => 'anthropic', 'model' => 'claude-haiku-5-5', 'mode' => 'balanced', 'kind' => 'answer', 'outcome' => 'done', 'cost_usd' => $usd, 'occurred_at' => now(),
    ]);
}

/** Reserves all of the month's budget as a call in flight would (a pending row), so that the gate has nothing free. */
function assistHoldAllBudget(string $id): AssistUsage
{
    $session = assistSessionOf($id);

    $left = app(AssistPlans::class)->left($session->workspace_id, $session->user_id, false)['cents_left'];

    return app(AssistBudget::class)->reserve($session, 'answer', 'claude-haiku-5-5', $left, 'in-flight-'.uniqid()) ?? throw new RuntimeException('could not reserve');
}
