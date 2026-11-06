<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Filament\Pages\ServerUsage;
use App\Filament\Pages\Usage;
use App\Models\Plan;
use App\Models\Screen;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\Usage\UsageBreakdown;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| Usage and costs (since v1.5): per person, per workspace and per provider, one Anthropic with its use cases, minutes and
| tokens and dollars from the price table, % of the plan, six months, date filters, CSV; owners and admins see their
| workspace per person, a member their own, a viewer nothing; alerts for the plan's minutes, storage and AI credits.
*/

uses(ApiClient::class);

beforeEach(function () {
    Fx::fakeRecordingsDisk();
    Filament::setCurrentPanel('admin');
});
afterEach(fn () => Fx::cleanupDisks());

/** One ledger row of the user's workspace. */
function ledger(User $user, array $attributes = [], ?int $workspaceId = null): UsageLedgerEntry
{
    return UsageLedgerEntry::query()->withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId ?? $user->current_workspace_id, 'user_id' => $user->id, 'source' => 'server', 'report_id' => 'test/'.Str::ulid(),
        'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => 0, 'channels' => 1, 'billed_ms' => 0, 'unit_price_usd' => 0, 'cost_usd' => 0,
        'billable' => true, 'occurred_at' => now(), ...$attributes,
    ]);
}

/** @return array{ws: Workspace, owner: User, admin: User, member: User, viewer: User} */
function usageTeam(): array
{
    $owner = Fx::user(['name' => 'Olive Owner']);
    $ws = Workspace::query()->findOrFail($owner->current_workspace_id);
    $people = ['ws' => $ws, 'owner' => $owner];
    foreach ([WorkspaceRole::Admin, WorkspaceRole::Member, WorkspaceRole::Viewer] as $role) {
        $person = withoutWorkspace(Fx::user(['name' => $role->label().' Person']));
        app(Workspaces::class)->addMember($ws, $person, $role);
        $people[$role->value] = $person->fresh();
    }

    return $people;
}

function monthRange(): array
{
    return [Carbon::now()->startOfMonth(), Carbon::now()->startOfMonth()->addMonthNoOverflow()];
}

describe('the numbers', function () {
    it('adds minutes, tokens and dollars per provider from the price table', function () {
        $user = Fx::user();
        $breakdown = app(UsageBreakdown::class);
        $breakdown->savePrices(['assemblyai' => ['final_hour' => 1.0, 'live_hour' => 2.0], 'anthropic' => ['input_mtok' => 3.0, 'output_mtok' => 15.0]]);
        ledger($user, ['billed_ms' => 30 * 60000, 'cost_usd' => 99]); // the table decides, not the stored cost
        ledger($user, ['billed_ms' => 30 * 60000, 'source' => 'device', 'use_case' => 'live_captions']);
        ledger($user, ['provider' => 'claude', 'use_case' => 'show_as', 'input_tokens' => 1_000_000, 'output_tokens' => 100_000]);

        [$from, $to] = monthRange();
        $report = $breakdown->report($from, $to, $user->current_workspace_id);

        $byKey = collect($report['providers'])->keyBy('key');
        expect($byKey['assemblyai']['minutes'])->toBe(60.0)
            ->and($byKey['assemblyai']['cost_usd'])->toBe(1.5) // half an hour final at 1, half an hour live at 2
            ->and($byKey['anthropic']['input_tokens'])->toBe(1_000_000)
            ->and($byKey['anthropic']['output_tokens'])->toBe(100_000)
            ->and($byKey['anthropic']['cost_usd'])->toBe(4.5)
            ->and($report['totals']['cost_usd'])->toBe(6.0)
            ->and($report['totals']['minutes'])->toBe(60.0);
    });

    it('makes one Anthropic of the ledger\'s claude and anthropic, with each use case apart', function () {
        $user = Fx::user();
        $breakdown = app(UsageBreakdown::class);
        $breakdown->savePrices(['anthropic' => ['input_mtok' => 1.0, 'output_mtok' => 1.0]]);
        ledger($user, ['provider' => 'claude', 'use_case' => 'show_as', 'input_tokens' => 1000, 'output_tokens' => 0]);
        ledger($user, ['provider' => 'claude', 'use_case' => 'client_ask', 'input_tokens' => 2000, 'output_tokens' => 0]);
        ledger($user, ['provider' => 'anthropic', 'use_case' => 'screen_describe', 'input_tokens' => 4000, 'output_tokens' => 0]);

        [$from, $to] = monthRange();
        $providers = $breakdown->report($from, $to)['providers'];

        expect(collect($providers)->pluck('key')->all())->toBe(['anthropic']);
        $cases = collect($providers[0]['use_cases'])->pluck('input_tokens', 'label');
        expect($providers[0]['label'])->toBe('Anthropic')
            ->and($providers[0]['input_tokens'])->toBe(7000)
            ->and($cases->all())->toBe(['Screen descriptions' => 4000, 'Ask' => 2000, 'Show as (transcript views)' => 1000]);
    });

    it('counts what a row stored when it has neither minutes nor tokens, and what the table does not list', function () {
        $user = Fx::user();
        ledger($user, ['provider' => 'claude', 'use_case' => 'show_as', 'cost_usd' => 0.25]);
        ledger($user, ['provider' => 'azure_mai', 'billed_ms' => 60000, 'cost_usd' => 0.5]);
        ledger($user, ['provider' => 'whispercpp_local', 'billed_ms' => 60000, 'cost_usd' => 0, 'billable' => false]);

        [$from, $to] = monthRange();
        $byKey = collect(app(UsageBreakdown::class)->report($from, $to)['providers'])->keyBy('key');

        expect($byKey['anthropic']['cost_usd'])->toBe(0.25)
            ->and($byKey['azure_mai']['cost_usd'])->toBe(0.5)
            ->and($byKey['whispercpp_local']['minutes'])->toBe(0.0)
            ->and($byKey['whispercpp_local']['cost_usd'])->toBe(0.0);
    });

    it('lists the named providers in order', function () {
        $user = Fx::user();
        foreach (['gemini', 'claude', 'soniox', 'elevenlabs', 'assemblyai', 'groq'] as $provider) {
            ledger($user, ['provider' => $provider, 'billed_ms' => 60000]);
        }

        [$from, $to] = monthRange();

        expect(collect(app(UsageBreakdown::class)->report($from, $to)['providers'])->pluck('label')->all())
            ->toBe(['Groq', 'AssemblyAI', 'ElevenLabs', 'Soniox', 'Anthropic', 'Gemini']);
    });

    it('filters by date, workspace and person, and adds the tokens Claude spent', function () {
        $user = Fx::user();
        $other = Fx::user();
        ledger($user, ['billed_ms' => 60000]);
        ledger($user, ['billed_ms' => 120000, 'occurred_at' => now()->subMonths(2)]);
        ledger($other, ['billed_ms' => 180000]);
        $breakdown = app(UsageBreakdown::class);
        [$from, $to] = monthRange();

        expect($breakdown->report($from, $to)['totals']['minutes'])->toBe(4.0)
            ->and($breakdown->report($from, $to, $user->current_workspace_id)['totals']['minutes'])->toBe(1.0)
            ->and($breakdown->report(now()->subMonths(3), $to, $user->current_workspace_id)['totals']['minutes'])->toBe(3.0)
            ->and($breakdown->report($from, $to, null, $other->id)['totals']['minutes'])->toBe(3.0);
    });

    it('says how much of the plan each person and workspace used', function () {
        $t = usageTeam();
        Plan::personal()->update(['monthly_minutes' => 100, 'storage_bytes' => 1000, 'ai_credits_usd' => 10]);
        ledger($t['member'], ['billed_ms' => 25 * 60000], $t['ws']->id);
        ledger($t['admin'], ['billed_ms' => 25 * 60000], $t['ws']->id);
        ledger($t['admin'], ['provider' => 'claude', 'use_case' => 'client_ask', 'cost_usd' => 2.5], $t['ws']->id);
        $session = Fx::session($t['owner'], null);
        Screen::query()->create(['workspace_id' => $t['ws']->id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual',
            'sha256' => str_repeat('a', 64), 'mime' => 'image/png', 'bytes' => 100, 'stored_bytes' => 100]);

        [$from, $to] = monthRange();
        $report = app(UsageBreakdown::class)->report($from, $to, $t['ws']->id);

        expect(collect($report['people'])->firstWhere('id', $t['member']->id)['plan_percent'])->toBe(25.0)
            ->and($report['workspaces'][0]['minutes_percent'])->toBe(50.0)
            ->and($report['workspaces'][0]['storage_percent'])->toBe(10.0)
            ->and($report['workspaces'][0]['ai_percent'])->toBe(25.0);
    });

    it('gives the last six months, oldest first, this one included', function () {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $user = Fx::user();
        ledger($user, ['billed_ms' => 60000]);
        ledger($user, ['billed_ms' => 120000, 'occurred_at' => now()->subMonths(5)]);
        ledger($user, ['billed_ms' => 180000, 'occurred_at' => now()->subMonths(6)]); // before the six

        $trend = app(UsageBreakdown::class)->trend((int) $user->current_workspace_id);

        expect(collect($trend)->pluck('month')->all())->toBe(['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'])
            ->and($trend[0]['minutes'])->toBe(2.0)
            ->and($trend[5]['minutes'])->toBe(1.0)
            ->and($trend[1]['minutes'])->toBe(0.0);
        Carbon::setTestNow();
    });
});

describe('who sees what', function () {
    it('shows the owner and an admin the workspace per person, never content', function () {
        $t = usageTeam();
        ledger($t['member'], ['billed_ms' => 5 * 60000], $t['ws']->id);
        Fx::session($t['member'], null, ['title' => 'MEMBER-SECRET-TITLE', 'workspace_id' => $t['ws']->id]);
        $this->app['auth']->forgetGuards();

        foreach (['owner', 'admin'] as $who) {
            $this->actingAs($t[$who], 'web');
            Livewire::test(Usage::class)->assertSee('By person')->assertSee('Member Person')->assertSee('Last six months')->assertDontSee('MEMBER-SECRET-TITLE');
        }
    });

    it('shows a member only their own numbers', function () {
        $t = usageTeam();
        ledger($t['member'], ['billed_ms' => 5 * 60000], $t['ws']->id);
        ledger($t['admin'], ['billed_ms' => 70 * 60000], $t['ws']->id);
        $this->actingAs($t['member'], 'web');

        Livewire::test(Usage::class)
            ->assertSee('Your billable minutes')
            ->assertDontSee('By person')
            ->assertDontSee('Admin Person');
    });

    it('answers a viewer 403 on the page', function () {
        $t = usageTeam();
        $this->actingAs($t['viewer'], 'web');

        $this->get(Usage::getUrl())->assertForbidden();
    });

    it('keeps the server page for the super admin', function () {
        $t = usageTeam();
        foreach (['owner', 'admin', 'member', 'viewer'] as $who) {
            $this->actingAs($t[$who], 'web');
            $this->get(ServerUsage::getUrl())->assertForbidden();
        }
        $this->actingAs(Fx::superAdmin(), 'web');
        $this->get(ServerUsage::getUrl())->assertOk();
    });

    it('shows the super admin every workspace, person and provider in the chosen days, and no content', function () {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $admin = Fx::superAdmin();
        $person = Fx::user(['name' => 'Far Away']);
        ledger($person, ['provider' => 'groq', 'billed_ms' => 42 * 60000]);
        ledger($person, ['provider' => 'groq', 'billed_ms' => 60000, 'occurred_at' => now()->subDays(40)]);
        Fx::session($person, null, ['title' => 'FAR-SECRET-TITLE']);
        $this->app['auth']->forgetGuards();
        $this->actingAs($admin, 'web');

        Livewire::test(ServerUsage::class)
            ->assertSee('Groq')->assertSee('Far Away')->assertSee('42.0')->assertDontSee('FAR-SECRET-TITLE')
            ->set('filters.from', now()->subDays(45)->toDateString())->assertSee('43.0')
            ->set('filters.workspace_id', (string) $admin->current_workspace_id)->assertDontSee('Far Away');
        Carbon::setTestNow();
    });

    it('edits the price table, and the dollars follow', function () {
        $admin = Fx::superAdmin();
        ledger($admin, ['provider' => 'groq', 'billed_ms' => 60 * 60000]);
        $this->actingAs($admin, 'web');
        $state = [];
        foreach (UsageBreakdown::PROVIDERS as $provider => $_) {
            foreach (UsageBreakdown::UNITS as $unit => $__) {
                $state["{$provider}__{$unit}"] = 0;
            }
        }

        Livewire::test(ServerUsage::class)->callAction('prices', [...$state, 'groq__final_hour' => 7])->assertHasNoActionErrors()->assertNotified('Prices saved')->assertSee('$7.00');

        [$from, $to] = monthRange();
        expect(app(UsageBreakdown::class)->prices()['groq']['final_hour'])->toBe(7.0)
            ->and(app(UsageBreakdown::class)->report($from, $to)['totals']['cost_usd'])->toBe(7.0);
    });
});

describe('the CSV', function () {
    it('has one line per provider, use case, workspace and person, and the total', function () {
        $user = Fx::user(['name' => 'Csv Person']);
        app(UsageBreakdown::class)->savePrices(['groq' => ['final_hour' => 6.0]]);
        ledger($user, ['provider' => 'groq', 'billed_ms' => 10 * 60000]);

        [$from, $to] = monthRange();
        $rows = app(UsageBreakdown::class)->csv($from, $to, $user->current_workspace_id);

        expect($rows[0])->toBe(['Section', 'Name', 'Detail', 'Minutes', 'Tokens read', 'Tokens written', 'Cost (US$)', '% of plan minutes'])
            ->and(collect($rows)->firstWhere(0, 'Provider'))->toBe(['Provider', 'Groq', '', 10.0, 0, 0, 1.0, ''])
            ->and(collect($rows)->firstWhere(0, 'Use case')[2])->toBe('Final transcripts')
            ->and(collect($rows)->firstWhere(0, 'Person')[1])->toBe('Csv Person')
            ->and(collect($rows)->firstWhere(0, 'Workspace'))->not->toBeNull()
            ->and(collect($rows)->last())->toBe(['Total', '', '', 10.0, 0, 0, 1.0, '']);
    });

    it('keeps a name a spreadsheet would run as a formula as text', function () {
        $user = Fx::user(['name' => '=HYPERLINK("http://evil.example")']);
        ledger($user, ['billed_ms' => 60000]);

        [$from, $to] = monthRange();
        expect(collect(app(UsageBreakdown::class)->csv($from, $to))->firstWhere(0, 'Person')[1])->toStartWith("'=");
    });

    it('downloads from the server page and the Usage page, a member\'s with only their own numbers', function () {
        $t = usageTeam();
        ledger($t['member'], ['billed_ms' => 5 * 60000], $t['ws']->id);
        ledger($t['admin'], ['billed_ms' => 70 * 60000], $t['ws']->id);

        $this->actingAs(Fx::superAdmin(), 'web');
        Livewire::test(ServerUsage::class)->callAction('exportCsv')->assertFileDownloaded();

        $this->actingAs($t['member'], 'web');
        Livewire::test(Usage::class)->callAction('exportCsv')->assertFileDownloaded();
        [$from, $to] = monthRange();
        $mine = app(UsageBreakdown::class)->csv($from, $to, $t['ws']->id, $t['member']->id);
        expect(collect($mine)->firstWhere(0, 'Person')[1])->toBe('Member Person')
            ->and(collect($mine)->where(0, 'Person')->count())->toBe(1);
    });
});

describe('alerts to owners', function () {
    function limitNotices(User $user): array
    {
        return $user->fresh()->notifications->filter(fn ($n) => str_starts_with((string) ActivityFeed::kindOf($n), 'budget'))
            ->map(fn ($n) => ActivityFeed::kindOf($n).':'.($n->data['viewData']['limit'] ?? 'minutes'))->values()->sort()->values()->all();
    }

    it('tells the owners at 80 % and 100 % of the plan\'s AI credits, once each, and nobody else', function () {
        $t = usageTeam();
        Plan::personal()->update(['ai_credits_usd' => 10]);
        $spend = fn (float $usd) => ledger($t['member'], ['provider' => 'claude', 'use_case' => 'client_ask', 'cost_usd' => $usd], $t['ws']->id);

        $spend(5.0);
        expect(limitNotices($t['owner']))->toBe([]);
        $spend(3.5);
        $spend(0.1);
        expect(limitNotices($t['owner']))->toBe(['budget_warn:ai_credits'])
            ->and(limitNotices($t['admin']))->toBe([])
            ->and(limitNotices($t['member']))->toBe([]);
        $spend(2.0);
        $spend(1.0);
        expect(limitNotices($t['owner']))->toBe(['budget_reached:ai_credits', 'budget_warn:ai_credits']);
    });

    it('tells the owners at 80 % and 100 % of the plan\'s storage, screens included', function () {
        $t = usageTeam();
        Plan::personal()->update(['storage_bytes' => 1000]);
        $session = Fx::session($t['owner'], null);
        $screen = fn (string $sha, int $bytes) => Screen::query()->create(['workspace_id' => $t['ws']->id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1,
            'trigger' => 'manual', 'sha256' => str_repeat($sha, 64), 'mime' => 'image/png', 'bytes' => $bytes, 'stored_bytes' => $bytes]);

        $screen('a', 700);
        expect(limitNotices($t['owner']))->toBe([]);
        $screen('b', 100);
        expect(limitNotices($t['owner']))->toBe(['budget_warn:storage']);
        $screen('c', 300);
        expect(limitNotices($t['owner']))->toBe(['budget_reached:storage', 'budget_warn:storage']);
        // In the same notification list the apps read, with the kinds they know.
        $session2 = Fx::session($t['owner'], null);
        Screen::query()->create(['workspace_id' => $t['ws']->id, 'capture_session_id' => $session2->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual',
            'sha256' => str_repeat('d', 64), 'mime' => 'image/png', 'bytes' => 1, 'stored_bytes' => 1]);
        expect(limitNotices($t['owner']))->toHaveCount(2);
    });

    it('tells nobody when the plan has no limit', function () {
        $t = usageTeam();
        ledger($t['member'], ['provider' => 'claude', 'use_case' => 'client_ask', 'cost_usd' => 500.0], $t['ws']->id);
        $session = Fx::session($t['owner'], null);
        Screen::query()->create(['workspace_id' => $t['ws']->id, 'capture_session_id' => $session->id, 'at_ms' => 1, 'width' => 1, 'height' => 1, 'trigger' => 'manual',
            'sha256' => str_repeat('a', 64), 'mime' => 'image/png', 'bytes' => 1, 'stored_bytes' => 1]);

        expect(limitNotices($t['owner']))->toBe([]);
    });

    it('tells the owners about minutes as before, apart from storage and credits', function () {
        $t = usageTeam();
        Plan::personal()->update(['monthly_minutes' => 10, 'ai_credits_usd' => 10]);

        ledger($t['member'], ['billed_ms' => 9 * 60000], $t['ws']->id);
        ledger($t['member'], ['provider' => 'claude', 'use_case' => 'client_ask', 'cost_usd' => 9.0], $t['ws']->id);

        expect(limitNotices($t['owner']))->toBe(['budget_warn:ai_credits', 'budget_warn:minutes']);
    });
});
