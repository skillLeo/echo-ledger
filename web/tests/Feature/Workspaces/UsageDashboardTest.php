<?php

declare(strict_types=1);

use App\Filament\Pages\Usage;
use App\Filament\Widgets\Usage\DailyUsageChart;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Usage\UsageReport;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/** One ledger row of the user's current workspace. */
function usageReportRow(User $user, array $attributes): UsageLedgerEntry
{
    return UsageLedgerEntry::query()->create([
        'workspace_id' => $user->current_workspace_id, 'user_id' => $user->id, 'source' => 'server',
        'report_id' => 'final/'.Str::ulid().'/a1', 'use_case' => 'final', 'provider' => 'assemblyai', 'duration_ms' => 60000,
        'channels' => 1, 'billed_ms' => 60000, 'unit_price_usd' => 0.15, 'cost_usd' => 0.0025, 'billable' => true, 'occurred_at' => now(),
        ...$attributes,
    ]);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');
});

afterEach(fn () => Carbon::setTestNow());

it('adds up to the usage ledger exactly: totals, providers, streaming vs final, devices, clients and days', function () {
    $owner = Fx::user();
    $phone = Fx::device($owner, ['name' => 'Pixel']);
    $client = Fx::client($owner, 'Acme');
    $session = Fx::session($owner, $phone, ['client_id' => $client->id]);
    $rows = [
        usageReportRow($owner, ['device_id' => $phone->id, 'capture_session_id' => $session->id, 'billed_ms' => 2 * 123_457, 'duration_ms' => 123_457, 'channels' => 2, 'cost_usd' => 0.010288]),
        usageReportRow($owner, ['device_id' => $phone->id, 'source' => 'device', 'use_case' => 'live_captions', 'report_id' => 'r-'.Str::random(10), 'billed_ms' => 300_000, 'cost_usd' => 0.0125]),
        usageReportRow($owner, ['provider' => 'elevenlabs', 'billed_ms' => 90_000, 'cost_usd' => 0.01]),
        usageReportRow($owner, ['provider' => 'whispercpp_local', 'billed_ms' => 600_000, 'cost_usd' => 0, 'unit_price_usd' => 0, 'billable' => false]),
        // Karachi is UTC+5: 19:30 UTC on Oct 31 is already November there, 19:30 UTC on Sep 30 is still September.
        usageReportRow($owner, ['billed_ms' => 60_000, 'occurred_at' => Carbon::parse('2026-10-31 19:30:00', 'UTC')]),
        usageReportRow($owner, ['billed_ms' => 60_000, 'occurred_at' => Carbon::parse('2026-09-30 18:30:00', 'UTC')]),
    ];
    usageReportRow(Fx::user(), ['billed_ms' => 9_999_000]); // another workspace

    $report = app(UsageReport::class)->forMonth($owner->current_workspace_id, '2026-10');

    $october = collect(array_slice($rows, 0, 4));
    $billable = $october->where('billable', true);
    expect($report['totals']['minutes'])->toEqualWithDelta($billable->sum('billed_ms') / 60000, 1e-9)
        ->and($report['totals']['cost_usd'])->toEqualWithDelta($billable->sum(fn ($r) => (float) $r->cost_usd), 1e-9)
        ->and($report['totals']['streaming_minutes'])->toEqualWithDelta(5.0, 1e-9)
        ->and($report['totals']['final_minutes'])->toEqualWithDelta((2 * 123_457 + 90_000) / 60000, 1e-9)
        ->and($report['totals']['free_minutes'])->toEqualWithDelta(10.0, 1e-9)
        ->and(array_sum($report['daily']['minutes']))->toEqualWithDelta(round($billable->sum('billed_ms') / 60000, 1), 0.11)
        ->and(count($report['daily']['labels']))->toBe(31);
    $assembly = collect($report['providers'])->firstWhere('provider', 'assemblyai');
    expect($assembly['streaming_minutes'])->toEqualWithDelta(5.0, 1e-9)
        ->and($assembly['final_minutes'])->toEqualWithDelta(2 * 123_457 / 60000, 1e-9)
        ->and(collect($report['providers'])->firstWhere('provider', 'whispercpp_local')['cost_usd'])->toBe(0.0);
    expect($report['devices'][0]['name'])->toBe('Pixel')
        ->and($report['devices'][0]['minutes'])->toEqualWithDelta((2 * 123_457 + 300_000) / 60000, 1e-9)
        ->and(collect($report['devices'])->sum('minutes'))->toEqualWithDelta($report['totals']['minutes'], 1e-9)
        ->and($report['clients'])->toHaveCount(1)
        ->and($report['clients'][0]['name'])->toBe('Acme')
        ->and($report['clients'][0]['minutes'])->toEqualWithDelta(2 * 123_457 / 60000, 1e-9);

    $september = app(UsageReport::class)->forMonth($owner->current_workspace_id, '2026-09');
    $november = app(UsageReport::class)->forMonth($owner->current_workspace_id, '2026-11');
    expect($september['totals']['minutes'])->toEqualWithDelta(1.0, 1e-9)
        ->and($november['totals']['minutes'])->toEqualWithDelta(1.0, 1e-9);
});

it('says per engine how many recordings it transcribed, what a recorded hour cost, and the Double-check part', function () {
    $owner = Fx::user();
    $phone = Fx::device($owner, ['name' => 'Pixel']);
    [$a, $b] = [Fx::session($owner, $phone), Fx::session($owner, $phone)];
    // Two stereo calls of 30 minutes each with Groq (US$0.111 per hour of audio, billed per side): $0.111 per call.
    usageReportRow($owner, ['provider' => 'groq', 'capture_session_id' => $a->id, 'duration_ms' => 1_800_000, 'billed_ms' => 3_600_000, 'cost_usd' => 0.111]);
    usageReportRow($owner, ['provider' => 'groq', 'capture_session_id' => $b->id, 'duration_ms' => 1_800_000, 'billed_ms' => 3_600_000, 'cost_usd' => 0.111]);
    usageReportRow($owner, ['provider' => 'groq', 'capture_session_id' => $a->id, 'report_id' => 'check/'.Str::ulid().'/20261020120000', 'model' => 'double-check',
        'duration_ms' => 30_000, 'billed_ms' => 30_000, 'cost_usd' => 0.001]);
    usageReportRow($owner, ['provider' => 'assemblyai', 'source' => 'device', 'use_case' => 'live_captions', 'report_id' => 'r-'.Str::random(10), 'billed_ms' => 300_000, 'cost_usd' => 0.0125]);

    $rows = collect(app(UsageReport::class)->forMonth($owner->current_workspace_id, '2026-10')['providers'])->keyBy('provider');

    expect($rows['groq']['recordings'])->toBe(2)
        ->and($rows['groq']['per_hour_usd'])->toEqualWithDelta(0.222, 1e-9)
        ->and($rows['groq']['check_usd'])->toEqualWithDelta(0.001, 1e-9)
        ->and($rows['groq']['cost_usd'])->toEqualWithDelta(0.223, 1e-9)
        ->and($rows['assemblyai']['recordings'])->toBe(0)
        ->and($rows['assemblyai']['per_hour_usd'])->toBeNull();
});

it('shows the month, the plan meter and the chart to a member, and the server cap only to the server admin', function () {
    $admin = Fx::superAdmin();
    app(SettingsRepository::class)->set('budget.monthly_minutes', 100);
    usageReportRow($admin, ['billed_ms' => 85 * 60000]);
    $member = Fx::user();
    usageReportRow($member, ['billed_ms' => 3 * 60000]);
    Filament::setCurrentPanel('admin');

    $this->actingAs($admin, 'web');
    Livewire::test(Usage::class)
        ->assertSee('Billable minutes')->assertSee('85')
        ->assertSee('Server cap: minutes (all workspaces)')
        ->assertSee('88.0 of 100 min')
        ->assertSee('aria-valuenow="88"', false)
        ->set('filters.month', '2026-09')->assertSee('No usage in September 2026');

    $this->actingAs($member, 'web');
    Livewire::test(Usage::class)->assertSee('Plan &quot;Personal&quot;: minutes', false)->assertSee('3.0 of 30,000 min')->assertDontSee('Server cap');
    $this->get(Usage::getUrl())->assertOk();
});

it('charts billable minutes per day of the chosen month for the signed-in workspace only', function () {
    $owner = Fx::user();
    usageReportRow($owner, ['billed_ms' => 120_000, 'occurred_at' => Carbon::parse('2026-10-03 08:00:00', 'UTC')]);
    usageReportRow(Fx::user(), ['billed_ms' => 9_000_000, 'occurred_at' => Carbon::parse('2026-10-03 08:00:00', 'UTC')]);
    $this->actingAs($owner, 'web');

    $chart = Livewire::test(DailyUsageChart::class, ['month' => '2026-10'])->instance();
    $data = (fn (): array => $this->getCachedData())->call($chart);

    expect($data['datasets'][0]['data'][2])->toBe(2.0)->and(array_sum($data['datasets'][0]['data']))->toBe(2.0);
});

it('never puts the usage chart on the dashboard', function () {
    expect(Filament::getPanel('admin')->getWidgets())->not->toContain(DailyUsageChart::class);
});
