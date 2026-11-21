<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\LiveAssistStats;
use App\Livewire\Admin\LiveAssistNow;
use App\Models\AssistRelayCheck;
use App\Models\AssistSession;
use App\Models\AssistSuggestion;
use App\Models\AssistTurn;
use App\Models\AssistUsage;
use App\Services\Assist\AssistMonitor;
use App\Services\Assist\RelayHealth;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

require_once __DIR__.'/Support/assist_helpers.php';

uses(ApiClient::class);

/*
 * Plan B4, Live Assist monitoring for the super admin: the relay's 5-minute health checks (kept 7 days, nothing of the
 * answer but ok / time / version), uptime and outages, the relay's last call, the sessions running and the last hour's
 * speed, on Usage → Live Assist. No content anywhere.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-11-21 12:00:00');
    assistRelaySetUp();
    Cache::forget(AssistMonitor::CONTACT_KEY);
});

/** Checks every 5 minutes over the last [$hours] hours, oldest first; [$down] says which (0-based) failed. */
function relayChecks(int $hours, ?callable $down = null): void
{
    $count = $hours * 12;
    for ($i = 0; $i < $count; $i++) {
        $failed = $down !== null && $down($i, $count);
        AssistRelayCheck::query()->create(['host' => 'relay.example.com', 'checked_at' => now()->subMinutes(5 * ($count - $i)),
            'ok' => ! $failed, 'ms' => 40, 'version' => $failed ? null : '1.0.2', 'error' => $failed ? 'The relay did not answer.' : null]);
    }
}

/** A GET from the relay, signed over its empty body as the relay signs it. */
function monitorRelayGet(object $test, string $path): TestResponse
{
    return $test->call('GET', $path, [], [], [], test()->transformHeadersToServerVars(relayHeaders('GET', $path)), '');
}

function monitorSession(array $state = []): AssistSession
{
    $device = assistPerson();

    return AssistSession::query()->withoutGlobalScopes()->create(['workspace_id' => $device->workspace_id, 'user_id' => $device->user_id, 'mode' => 'fast',
        'plan' => 'pro', 'practice' => false, 'platform' => 'android', 'transport' => 'relay', 'status' => 'active', 'started_at' => now()->subMinutes(10), ...$state]);
}

describe('the 5-minute check', function () {
    it('asks the relay once, keeps ok, time and version, and nothing else of its answer', function () {
        Http::swap(new HttpFactory);
        Http::fake(['relay.example.com/healthz' => Http::response(['status' => 'ok', 'version' => '1.0.3', 'sessions' => 42, 'note' => 'SECRET RELAY DETAIL'])]);

        $this->artisan('assist:relay-check')->assertSuccessful();

        $check = AssistRelayCheck::query()->sole();
        expect($check->only(['host', 'ok', 'version', 'error']))->toBe(['host' => 'relay.example.com', 'ok' => true, 'version' => '1.0.3', 'error' => null])
            ->and($check->ms)->toBeGreaterThanOrEqual(0)
            ->and(json_encode($check->getAttributes()))->not->toContain('SECRET')->not->toContain('42');
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $r): bool => $r->url() === 'https://relay.example.com/healthz');
    });

    it('keeps a failure as the check\'s own sentence, once, with no retry and never the relay\'s body', function () {
        Http::swap(new HttpFactory);
        Http::fake(['relay.example.com/healthz' => Http::response('<html>SECRET STACK TRACE</html>', 502)]);

        $this->artisan('assist:relay-check')->assertSuccessful();

        $check = AssistRelayCheck::query()->sole();
        expect($check->ok)->toBeFalse()->and($check->version)->toBeNull()->and($check->error)->toBe('The relay answered HTTP 502.')
            ->and(json_encode($check->getAttributes()))->not->toContain('SECRET');
        Http::assertSentCount(1);
    });

    it('keeps the guard against private addresses: such a relay is not asked, and the check says why', function () {
        Http::swap(new HttpFactory);
        Http::fake();
        RelayHealth::$resolveUsing = fn (string $host): array => ['10.0.0.5'];

        $this->artisan('assist:relay-check')->assertSuccessful();

        Http::assertNothingSent();
        $check = AssistRelayCheck::query()->sole();
        expect($check->ok)->toBeFalse()->and($check->error)->toContain('private network')->and($check->source)->toBe('scheduled');
    });

    it('asks nothing without a relay, drops checks older than 7 days, and runs every 5 minutes', function () {
        AssistRelayCheck::query()->create(['host' => 'relay.example.com', 'checked_at' => now()->subDays(8), 'ok' => true, 'ms' => 1]);
        AssistRelayCheck::query()->create(['host' => 'relay.example.com', 'checked_at' => now()->subDays(6), 'ok' => true, 'ms' => 1]);
        app(SettingsRepository::class)->set('relay.url', '');
        Http::swap(new HttpFactory);
        Http::fake();

        $this->artisan('assist:relay-check')->assertSuccessful();

        expect(AssistRelayCheck::query()->count())->toBe(1);
        Http::assertNothingSent();
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'assist:relay-check'));
        expect($event)->not->toBeNull()->and($event->expression)->toBe('*/5 * * * *');
    });
});

describe('up, down, uptime and outages', function () {
    it('works out uptime and the outages from the checks', function () {
        // 7 days of checks; 3 failed in a row 2 days ago, and the last 2 failed: still down.
        relayChecks(7 * 24, fn (int $i, int $n): bool => in_array($i, [$n - 576, $n - 575, $n - 574], true) || $i >= $n - 2);

        $relay = app(AssistMonitor::class)->now()['relay'];

        expect($relay['status'])->toBe('up') // the newest answer: the helper's ok check (RelayHealth::recent) is newer than the rows
            ->and($relay['checks_24h'])->toBe(288)
            ->and($relay['uptime_24h'])->toBe(round(286 * 100 / 288, 2))
            ->and($relay['uptime_7d'])->toBe(round((2016 - 5) * 100 / 2016, 2))
            ->and($relay['outages'])->toHaveCount(2)
            ->and($relay['outages'][0]['to'])->toBeNull()
            ->and($relay['outages'][1]['checks'])->toBe(3)
            ->and($relay['outages'][1]['minutes'])->toBe(15)
            ->and($relay['outages'][1]['error'])->toBe('The relay did not answer.')
            ->and($relay['checks_stale'])->toBeFalse();
    });

    it('says down from the newest check, and that the checks stopped when none came for 15 minutes', function () {
        Cache::flush();
        relayChecks(1, fn (int $i, int $n): bool => $i === $n - 1);
        expect(app(AssistMonitor::class)->now()['relay']['status'])->toBe('down');

        $this->travel(20)->minutes();
        expect(app(AssistMonitor::class)->now()['relay']['checks_stale'])->toBeTrue();
    });

    it('says not set up without a relay address, and not checked yet before the first check', function () {
        Cache::flush();
        expect(app(AssistMonitor::class)->now()['relay']['status'])->toBe('unknown');
        app(SettingsRepository::class)->set('relay.url', '');
        expect(app(AssistMonitor::class)->now()['relay'])->toMatchArray(['status' => 'not_set', 'host' => null, 'uptime_24h' => null]);
    });
});

describe('manual checks', function () {
    it('say up or down now, but never count in the uptime or hide that the scheduled checks stopped', function () {
        Cache::flush();
        // The scheduled checks: one an hour ago, down. Then nothing scheduled since (the scheduler stopped).
        AssistRelayCheck::query()->create(['host' => 'relay.example.com', 'checked_at' => now()->subHour(), 'source' => 'scheduled', 'ok' => false, 'ms' => 3000, 'error' => 'The relay did not answer.']);
        foreach (range(1, 5) as $i) {
            AssistRelayCheck::query()->create(['host' => 'relay.example.com', 'checked_at' => now()->subMinutes($i), 'source' => 'manual', 'ok' => true, 'ms' => 40, 'version' => '1.0.2']);
        }

        $relay = app(AssistMonitor::class)->now()['relay'];

        expect($relay['status'])->toBe('up')
            ->and($relay['uptime_24h'])->toBe(0.0)->and($relay['checks_24h'])->toBe(1)
            ->and($relay['checks_stale'])->toBeTrue()
            ->and($relay['outages'][0]['to'])->toBeNull();
    });
});

describe('the relay\'s last call', function () {
    it('never fails the relay\'s call when the note cannot be kept', function () {
        Cache::partialMock()->shouldReceive('get')->with(AssistMonitor::CONTACT_KEY)->andThrow(new RuntimeException('cache down'));

        monitorRelayGet($this, '/api/internal/assist/providers')->assertOk();
    });

    it('notes a signed call from the relay, once a minute at most, and never an unsigned one', function () {
        $this->api()->getJson('/api/internal/assist/providers')->assertUnauthorized();
        expect(Cache::get(AssistMonitor::CONTACT_KEY))->toBeNull();

        monitorRelayGet($this, '/api/internal/assist/providers')->assertOk();
        $first = Cache::get(AssistMonitor::CONTACT_KEY);
        expect($first)->toBe(now()->getTimestamp());

        $this->travel(30)->seconds();
        monitorRelayGet($this, '/api/internal/assist/providers')->assertOk();
        expect(Cache::get(AssistMonitor::CONTACT_KEY))->toBe($first);

        $this->travel(31)->seconds();
        monitorRelayGet($this, '/api/internal/assist/providers')->assertOk();
        expect(Cache::get(AssistMonitor::CONTACT_KEY))->toBe(now()->getTimestamp())
            ->and(app(AssistMonitor::class)->now()['relay']['last_contact']->getTimestamp())->toBe(now()->getTimestamp());
    });
});

describe('sessions and the last hour', function () {
    it('counts the sessions running by transport and practice, and those started', function () {
        monitorSession();
        monitorSession(['transport' => 'http']);
        monitorSession(['practice' => true]);
        monitorSession(['status' => 'ended', 'ended_at' => now()->subMinute()]);
        monitorSession(['status' => 'ended', 'ended_at' => now()->subHours(3), 'started_at' => now()->subHours(4)]);

        expect(app(AssistMonitor::class)->now()['sessions'])->toBe([
            'active' => 3, 'active_relay' => 2, 'active_http' => 1, 'active_practice' => 1, 'started_hour' => 4, 'started_today' => 5,
        ]);
    });

    it('gives the last hour\'s first word, relay overhead, errors and cost, from the usage records only', function () {
        $session = monitorSession();
        $row = fn (int $i, array $state): AssistUsage => AssistUsage::query()->create([
            'assist_session_id' => $session->id, 'workspace_id' => $session->workspace_id, 'user_id' => $session->user_id, 'plan' => 'pro',
            'suggestion_id' => 'sg-'.$i, 'provider' => 'anthropic', 'mode' => 'fast', 'model' => 'claude-haiku-5-5', 'kind' => 'answer', 'outcome' => 'done',
            'input_tokens' => 0, 'cached_tokens' => 0, 'written_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.01, 'occurred_at' => now()->subMinutes(10), ...$state]);
        foreach (range(1, 10) as $i) {
            $row($i, ['latency' => ['end_of_turn_to_first_token_ms' => $i * 100, 'relay_overhead_ms' => $i]]);
        }
        $row(11, ['outcome' => 'error', 'latency' => null]);
        $row(12, ['kind' => 'facts', 'latency' => ['end_of_turn_to_first_token_ms' => 99999]]); // background: not timed, still costs
        $row(13, ['occurred_at' => now()->subHours(2), 'cost_usd' => 5, 'latency' => ['end_of_turn_to_first_token_ms' => 99999]]);

        $hour = app(AssistMonitor::class)->now()['hour'];

        expect($hour['suggestions'])->toBe(11)
            ->and($hour['first_word'])->toBe(['p50' => 500.0, 'p95' => 1000.0, 'n' => 10])
            ->and($hour['overhead']['p95'])->toBe(10.0)
            ->and($hour['error_share'])->toBe(round(100 / 11, 1))
            ->and($hour['cost_usd'])->toBe(0.12);
    });
});

describe('the admin page', function () {
    beforeEach(fn () => Filament::setCurrentPanel('admin'));

    it('shows the relay, the sessions and the last hour to the super admin, and no word anyone said', function () {
        relayChecks(24, fn (int $i, int $n): bool => $i === 100 || $i === 101);
        $session = monitorSession();
        AssistTurn::query()->create(['assist_session_id' => $session->id, 'client_ref' => 't1', 'channel' => 'other', 'start_ms' => 0, 'end_ms' => 900,
            'text' => 'SECRET WORDS FROM THE CALL', 'is_question' => true]);
        AssistSuggestion::query()->create(['assist_session_id' => $session->id, 'suggestion_id' => 'sg-1', 'text' => 'SECRET SUGGESTION TEXT']);
        $this->actingAs(Fx::superAdmin(), 'web');

        $page = Livewire::test(LiveAssistStats::class)->assertOk()
            ->assertSee('Relay')->assertSee('relay.example.com')->assertSee('Up')->assertSee('Outages in the last 7 days')
            ->assertSee('Sessions now')->assertSee('First word, last hour');

        expect($page->html())->not->toContain('SECRET')->toContain('data-relay-status="up"');
    });

    it('checks the relay now on request, 6 a minute at most, and hides the button without a relay', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        $page = Livewire::test(LiveAssistStats::class);
        foreach (range(1, 6) as $_) {
            $page->callAction('checkRelay');
        }
        expect(AssistRelayCheck::query()->count())->toBe(6);
        $page->callAction('checkRelay')->assertNotified('Wait a moment');
        expect(AssistRelayCheck::query()->count())->toBe(6)
            ->and(AssistRelayCheck::query()->where('source', 'manual')->count())->toBe(6);

        app(SettingsRepository::class)->set('relay.url', '');
        Livewire::test(LiveAssistStats::class)->assertActionHidden('checkRelay')->assertSee('Not set up');
    });

    it('stays the super admin\'s, the polled part too', function () {
        $this->actingAs(Fx::user(), 'web');
        $this->get(LiveAssistStats::getUrl(panel: 'admin'))->assertForbidden();
        Livewire::test(LiveAssistNow::class)->assertForbidden();
    });

    it('refreshes the "now" part by itself every 30 s, without working out the period\'s numbers again', function () {
        $this->actingAs(Fx::superAdmin(), 'web');
        $now = Livewire::test(LiveAssistNow::class)->assertOk()->assertSee('Sessions now');
        expect($now->html())->toContain('wire:poll.30s');

        // A refresh (what the 30 s poll sends) reads the "now" numbers only: none of AssistStats::between's sums.
        DB::flushQueryLog();
        DB::enableQueryLog();
        $now->call('$refresh')->assertOk();
        $now->dispatch('relay-checked')->assertOk();
        $queries = implode("\n", array_column(DB::getQueryLog(), 'query'));
        DB::disableQueryLog();
        expect($queries)->toContain('assist_relay_checks')->not->toContain('SUM(input_tokens)')->not->toContain('cached_tokens');
    });
});
