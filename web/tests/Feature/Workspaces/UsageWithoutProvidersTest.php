<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\ServerUsage;
use App\Filament\Pages\Usage;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Services\SettingsRepository;
use App\Services\Usage\UsageReport;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
| W-001 (v1.8.1): the console's Usage page shows a month's totals for every engine together, and its CSV one line per
| use; which provider did what (and what it cost) is in the admin panel's Server usage only.
*/

/** The provider and model names the console's Usage page must not show. */
const USAGE_PROVIDERS = ['Groq', 'Whisper', 'AssemblyAI', 'ElevenLabs', 'Scribe', 'Soniox', 'Gemini', 'Azure', 'Claude', 'Anthropic', 'whisper.cpp'];

function usageProvidersIn(string $text): array
{
    return array_values(array_filter(USAGE_PROVIDERS, fn (string $name): bool => preg_match('/\b'.preg_quote($name, '/').'\b/i', $text) === 1));
}

/** One ledger row of [$user]'s current workspace, this month. */
function providerlessUsageRow(User $user, array $attributes): UsageLedgerEntry
{
    return UsageLedgerEntry::query()->withoutGlobalScopes()->create([
        'workspace_id' => $user->current_workspace_id, 'user_id' => $user->id, 'source' => 'server', 'report_id' => 'final/'.Str::ulid().'/a1',
        'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => 60_000, 'channels' => 1, 'billed_ms' => 60_000,
        'unit_price_usd' => 0.15, 'cost_usd' => 0.0025, 'billable' => true, 'occurred_at' => now(), ...$attributes,
    ]);
}

/** A month of one workspace on four providers: two engines on the same call, Double-check, live captions and the views. */
function usageMonth(User $owner): void
{
    $phone = Fx::device($owner, ['name' => 'Pixel']);
    [$a, $b] = [Fx::session($owner, $phone), Fx::session($owner, $phone)];
    providerlessUsageRow($owner, ['provider' => 'groq', 'capture_session_id' => $a->id, 'duration_ms' => 1_800_000, 'billed_ms' => 3_600_000, 'cost_usd' => 0.111]);
    providerlessUsageRow($owner, ['provider' => 'assemblyai', 'capture_session_id' => $a->id, 'duration_ms' => 1_800_000, 'billed_ms' => 1_800_000, 'cost_usd' => 0.075]);
    providerlessUsageRow($owner, ['provider' => 'groq', 'capture_session_id' => $b->id, 'duration_ms' => 1_800_000, 'billed_ms' => 3_600_000, 'cost_usd' => 0.111]);
    providerlessUsageRow($owner, ['provider' => 'groq', 'capture_session_id' => $a->id, 'report_id' => 'check/'.Str::ulid().'/1', 'model' => 'double-check',
        'duration_ms' => 30_000, 'billed_ms' => 30_000, 'cost_usd' => 0.001]);
    providerlessUsageRow($owner, ['provider' => 'elevenlabs', 'source' => 'device', 'use_case' => 'live_captions', 'report_id' => 'r-'.Str::random(10), 'device_id' => $phone->id,
        'billed_ms' => 300_000, 'cost_usd' => 0.02]);
    providerlessUsageRow($owner, ['provider' => 'claude', 'use_case' => 'show_as', 'report_id' => 'views/'.Str::ulid(), 'capture_session_id' => $b->id, 'duration_ms' => 0,
        'billed_ms' => 0, 'cost_usd' => 0.04, 'input_tokens' => 1000, 'output_tokens' => 2000, 'model' => 'claude-sonnet-5-5']);
}

beforeEach(function () {
    Carbon::setTestNow('2026-10-20 12:00:00');
    app(SettingsRepository::class)->set('display.timezone', 'Asia/Karachi');
    Filament::setCurrentPanel('app');
});

afterEach(fn () => Carbon::setTestNow());

it('adds every engine up: minutes, the recordings any engine wrote, a recorded hour and the Double-check part', function () {
    $owner = Fx::user();
    usageMonth($owner);

    $all = app(UsageReport::class)->forMonth($owner->current_workspace_id, '2026-10')['all'];

    expect($all['streaming_minutes'])->toEqualWithDelta(5.0, 1e-9)
        ->and($all['final_minutes'])->toEqualWithDelta(60 + 30 + 60 + 0.5, 1e-9)
        // Two calls, one of them written by two engines.
        ->and($all['recordings'])->toBe(2)
        // Final transcripts only: (0.111 + 0.075 + 0.111) for 1.5 hours of audio; Double-check and the views apart.
        ->and($all['per_hour_usd'])->toEqualWithDelta(0.198, 1e-9)
        ->and($all['check_usd'])->toEqualWithDelta(0.001, 1e-9)
        ->and($all['cost_usd'])->toEqualWithDelta(0.111 + 0.075 + 0.111 + 0.001 + 0.02 + 0.04, 1e-9);
});

it('shows a workspace\'s owner the totals, and no provider or model, on the Usage page', function () {
    $owner = Fx::user();
    usageMonth($owner);
    $this->actingAs($owner, 'web');

    $html = (string) $this->get('/app/usage')->assertOk()->assertSee('Totals')->assertSee('data-usage-totals', false)
        ->assertSee('Per recorded hour')->assertSee('$0.198')->assertDontSee('By provider')->getContent();

    expect(usageProvidersIn($html))->toBe([]);
});

it('shows one cost on the Usage page, and names the Double-check part only when it is at least a cent', function () {
    $owner = Fx::user();
    usageMonth($owner);
    // A use that does not count against the plan still cost something: the tile and Totals say the same.
    providerlessUsageRow($owner, ['provider' => 'groq', 'billable' => false, 'duration_ms' => 600_000, 'billed_ms' => 600_000, 'cost_usd' => 0.5]);
    $this->actingAs($owner, 'web');
    $costs = function (): array {
        $html = (string) $this->get('/app/usage')->assertOk()->getContent();
        preg_match('/Estimated cost<\/div>\s*<div[^>]*>\s*(\$[\d.,]+)/', $html, $tile);
        preg_match('/>Cost<\/td><td[^>]*>\s*(\$[\d.,]+)/', $html, $totals);

        return [$tile[1] ?? null, $totals[1] ?? null, $html];
    };

    [$tile, $totals, $html] = $costs();
    expect($tile)->toBe('$0.86')->and($totals)->toBe($tile)
        // $0.001 of Double-check shows as $0.00: not named.
        ->and($html)->not->toContain('Double-check');

    providerlessUsageRow($owner, ['provider' => 'groq', 'report_id' => 'check/'.Str::ulid().'/2', 'model' => 'double-check', 'duration_ms' => 30_000, 'billed_ms' => 30_000, 'cost_usd' => 0.01]);
    [$tile, $totals, $html] = $costs();
    expect($tile)->toBe('$0.87')->and($totals)->toBe($tile)->and($html)->toContain('incl. $0.01 Double-check');
});

it('downloads the owner\'s month one line per use, never per provider', function () {
    $owner = Fx::user();
    usageMonth($owner);
    $this->actingAs($owner, 'web');

    $csv = base64_decode((string) data_get(Livewire::test(Usage::class)->callAction('exportCsv')->assertFileDownloaded()->effects, 'download.content'));
    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), array_filter(explode("\n", trim($csv))));
    $sections = array_count_values(array_column($rows, 0));

    expect(usageProvidersIn($csv))->toBe([])
        ->and($sections)->not->toHaveKey('Provider')->not->toHaveKey('Use case')
        ->and(collect($rows)->where(0, 'Use')->pluck(1)->sort()->values()->all())->toBe(['Final transcripts', 'Live captions', 'Show as (transcript views)'])
        // Every engine's final transcripts together (Double-check included): 60 + 30 + 60 + 0.5 minutes.
        ->and(collect($rows)->firstWhere(1, 'Final transcripts')[3])->toBe('150.5')
        ->and(collect($rows)->firstWhere(1, 'Show as (transcript views)')[4])->toBe('1000')
        // The uses add up to the total.
        ->and(round(collect($rows)->where(0, 'Use')->sum(fn (array $r): float => (float) $r[6]), 4))->toEqualWithDelta((float) end($rows)[6], 1e-3)
        ->and(end($rows)[0])->toBe('Total');
});

it('keeps the provider breakdown for the server\'s operator in the admin panel, and only there', function () {
    $owner = Fx::user();
    usageMonth($owner);
    $operator = Fx::superAdmin();
    usageMonth($operator);
    $this->actingAs($operator, 'web');

    // The console's Usage page is the operator's own workspace there too: totals, and a way to the whole server.
    Livewire::test(Usage::class)->assertActionVisible('serverUsage');
    expect(usageProvidersIn((string) $this->get('/app/usage')->assertOk()->getContent()))->toBe([]);

    Filament::setCurrentPanel('admin');
    $admin = (string) $this->get('/admin/server-usage')->assertOk()->assertSee('By provider')->getContent();
    expect(usageProvidersIn($admin))->toContain('Groq')->toContain('AssemblyAI')->toContain('Anthropic');
    $csv = base64_decode((string) data_get(Livewire::test(ServerUsage::class)->callAction('exportCsv')->assertFileDownloaded()->effects, 'download.content'));
    expect($csv)->toContain('Provider,Groq')->toContain('Provider,Anthropic');
});
