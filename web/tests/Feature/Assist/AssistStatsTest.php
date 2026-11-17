<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\LiveAssistStats;
use App\Models\AssistSession;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Models\Plan;
use App\Models\StaffRole;
use App\Models\Workspace;
use App\Services\Assist\AssistStats;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Live Assist's numbers for the super admin (v1.9 L8): cost per model and per sold plan, cost per hour by mode, the
 * cache's share, how suggestions ended and p50 / p95 latency, from the usage records alone.
 */

beforeEach(function () {
    assistRelaySetUp();
    Carbon::setTestNow('2026-11-20 12:00:00');
});

/** A session on [$plan] of [$seconds], with one usage row per [$rows] entry. */
function statsSession(string $plan, string $mode, bool $practice, int $seconds, array $rows): AssistSession
{
    $device = assistPerson($plan);
    $session = AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'mode' => $mode,
        'plan' => $plan, 'practice' => $practice, 'platform' => 'desktop', 'status' => 'ended', 'started_at' => now()->subHours(2), 'ended_at' => now()->subHour(), 'seconds_used' => $seconds]);
    foreach ($rows as $i => $row) {
        AssistUsage::query()->create([
            'assist_session_id' => $session->id, 'workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'plan' => $plan,
            'suggestion_id' => $session->id.'-'.$i, 'provider' => 'anthropic', 'mode' => $mode, 'occurred_at' => now()->subHour(),
            'model' => 'claude-haiku-5-5', 'kind' => 'answer', 'outcome' => 'done', 'input_tokens' => 0, 'cached_tokens' => 0, 'written_tokens' => 0, 'output_tokens' => 0,
            ...$row,
        ]);
    }

    return $session;
}

it('counts cost per model and per sold plan, Business apart from Personal, each with its tier', function () {
    statsSession('business', 'deep', false, 3600, [['model' => 'claude-sonnet-5-5', 'cost_usd' => 0.30, 'input_tokens' => 200, 'cached_tokens' => 800]]);
    statsSession('personal', 'max', false, 1800, [['model' => 'claude-opus-5-5', 'cost_usd' => 0.50, 'input_tokens' => 1000]]);
    statsSession('pro', 'fast', false, 7200, [['cost_usd' => 0.02, 'input_tokens' => 100, 'cached_tokens' => 300], ['cost_usd' => 0.02, 'input_tokens' => 100, 'cached_tokens' => 300, 'outcome' => 'error']]);
    statsSession('free', 'fast', true, 900, [['cost_usd' => 0.01, 'input_tokens' => 400, 'kind' => 'practice_question']]);
    // Outside the period: left out.
    AssistUsage::query()->update(['occurred_at' => now()->subHour()]);
    statsSession('pro', 'fast', false, 600, [['cost_usd' => 9.99]])->forceFill(['started_at' => now()->subMonths(2)])->save();
    AssistUsage::query()->where('cost_usd', 9.99)->update(['occurred_at' => now()->subMonths(2)]);

    $stats = app(AssistStats::class)->between(now()->startOfMonth(), now()->addDay());

    $plans = collect($stats['plans'])->keyBy('plan');
    expect($plans->keys()->sort()->values()->all())->toBe(['business', 'free', 'personal', 'pro'])
        ->and($plans['business'])->toMatchArray(['tier' => 'ultra', 'sessions' => 1, 'hours' => 1.0, 'cost_usd' => 0.3, 'cost_per_hour_usd' => 0.3, 'cache_hit_percent' => 80.0])
        ->and($plans['personal'])->toMatchArray(['tier' => 'ultra', 'hours' => 0.5, 'cost_usd' => 0.5, 'cost_per_hour_usd' => 1.0])
        ->and($plans['pro'])->toMatchArray(['tier' => 'pro', 'calls' => 2, 'cost_usd' => 0.04, 'cost_per_hour_usd' => 0.02, 'cache_hit_percent' => 75.0])
        ->and($plans['free']['tier'])->toBe('free');
    $models = collect($stats['models'])->keyBy('model');
    expect($models['claude-haiku-5-5'])->toMatchArray(['calls' => 3, 'cost_usd' => 0.05, 'input_tokens' => 600, 'cached_tokens' => 600, 'cache_hit_percent' => 50.0])
        ->and($stats['totals'])->toMatchArray(['calls' => 5, 'cost_usd' => 0.85, 'sessions' => 4, 'hours' => 3.75]);
    // Cost per hour by mode, Practice apart.
    $modes = collect($stats['modes'])->keyBy('mode');
    expect($modes->keys()->all())->toBe(['fast', 'deep', 'max', 'practice'])
        ->and($modes['fast'])->toMatchArray(['hours' => 2.0, 'cost_usd' => 0.04, 'cost_per_hour_usd' => 0.02])
        ->and($modes['practice'])->toMatchArray(['hours' => 0.25, 'cost_usd' => 0.01, 'cost_per_hour_usd' => 0.04]);
    expect($stats['outcomes'])->toBe(['done' => 4, 'error' => 1])->and($stats['error_share'])->toBe(20.0);
});

it('gives p50 and p95 of each latency stage, overall and per model, for the suggestions a person waits for', function () {
    $rows = [];
    foreach (range(1, 20) as $n) {
        $rows[] = ['latency' => ['end_of_turn_to_request_ms' => $n, 'request_to_first_token_ms' => 100 * $n, 'end_of_turn_to_first_token_ms' => 100 * $n + $n,
            'relay_overhead_ms' => $n / 2, 'total_ms' => 1000 * $n], 'cost_usd' => 0.001];
    }
    // The background calls and the report are not timed; a stage the relay could not measure is left out.
    $rows[] = ['kind' => 'facts', 'latency' => ['total_ms' => 999999], 'cost_usd' => 0.001];
    $rows[] = ['kind' => 'report', 'latency' => ['total_ms' => 999999], 'cost_usd' => 0.001];
    $rows[] = ['latency' => ['end_of_turn_to_request_ms' => 5, 'request_to_first_token_ms' => null, 'total_ms' => 'x'], 'cost_usd' => 0.001];
    statsSession('pro', 'fast', false, 600, $rows);

    $latency = app(AssistStats::class)->between(now()->startOfMonth(), now()->addDay())['latency'];

    expect($latency['rows'])->toBe(21)
        ->and($latency['all']['total_ms'])->toBe(['p50' => 10000.0, 'p95' => 19000.0, 'n' => 20])
        ->and($latency['all']['request_to_first_token_ms'])->toBe(['p50' => 1000.0, 'p95' => 1900.0, 'n' => 20])
        ->and($latency['all']['end_of_turn_to_request_ms']['n'])->toBe(21)
        ->and($latency['all']['relay_overhead_ms'])->toBe(['p50' => 5.0, 'p95' => 9.5, 'n' => 20])
        ->and($latency['models']['claude-haiku-5-5']['end_of_turn_to_first_token_ms']['p95'])->toBe(1919.0);
    expect(AssistStats::percentiles([]))->toBe(['p50' => null, 'p95' => null, 'n' => 0])
        ->and(AssistStats::percentiles([3.0]))->toBe(['p50' => 3.0, 'p95' => 3.0, 'n' => 1]);
});

it('keeps the sold plan on a session and on every model call of it, even after the plan changes', function () {
    $device = assistPerson('business');
    $id = $this->api(Fx::token($device))->postJson('/api/assist/sessions', ['platform' => 'desktop', 'mode' => 'max'])->assertCreated()->json('session.id');
    expect(AssistSession::query()->withoutGlobalScopes()->find($id)->plan)->toBe('business');
    Workspace::query()->whereKey($device->workspace_id)->update(['plan_id' => Plan::query()->where('key', 'pro')->value('id')]);

    $body = (string) json_encode(['suggestion_id' => 'sg-1', 'session_id' => $id, 'model' => 'claude-opus-5-5', 'provider' => 'anthropic', 'mode' => 'max',
        'kind' => 'answer', 'tokens' => ['input' => 1, 'cached' => 0, 'written' => 0, 'output' => 1], 'cost_usd' => 0.01]);
    $this->call('POST', '/api/internal/assist/usage', [], [], [], $this->transformHeadersToServerVars(relayHeaders('POST', '/api/internal/assist/usage', $body)), $body)->assertOk();

    expect(AssistUsage::query()->sole()->plan)->toBe('business');
});

it('is the super admin\'s only, and shows numbers, never a word anyone said', function () {
    $session = statsSession('pro', 'fast', false, 3600, [['cost_usd' => 0.12, 'latency' => ['total_ms' => 1200, 'end_of_turn_to_first_token_ms' => 800]]]);
    AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 'o-1', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'SECRET-WORDS-SAID']);
    Filament::setCurrentPanel('admin');

    $this->actingAs(Fx::user(), 'web');
    $this->get(LiveAssistStats::getUrl(panel: 'admin'))->assertForbidden();
    // Staff, even the Admin role, see the other usage pages but not this one.
    $staff = Fx::user();
    $staff->forceFill(['staff_role_id' => StaffRole::query()->where('key', 'admin')->value('id'), 'app_authentication_secret' => Fx::STAFF_TOTP_SECRET])->save();
    $this->actingAs($staff->fresh(), 'web');
    Livewire::test(LiveAssistStats::class)->assertForbidden();

    $this->actingAs(Fx::superAdmin(), 'web');
    Livewire::test(LiveAssistStats::class)->assertOk()->assertSee('$0.1200')->assertSee('Cache hits')->assertSee('1,200 ms')->assertSee('Pro')
        ->assertDontSee('SECRET-WORDS-SAID')
        ->set('filters.preset', 'last_month')->assertSee('No Live Assist in this period.');
});
