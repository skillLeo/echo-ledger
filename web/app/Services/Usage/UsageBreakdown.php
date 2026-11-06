<?php

declare(strict_types=1);

namespace App\Services\Usage;

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ClientAi\ClaudeClientAi;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Support\Carbon;

/**
 * Who used what and what it cost (since v1.5): minutes, tokens and US dollars per person, per workspace and per provider
 * in a date range, from the usage ledger and an editable price table per provider and unit. It reads numbers only,
 * never content, so owners and admins see their workspace's people here and the super admin the whole server. The
 * ledger's `claude` (Show as, client AI) and `anthropic` (screen descriptions) are one provider, Anthropic, with its use
 * cases apart. A row with neither minutes nor tokens (Claude before tokens were kept) counts what it stored.
 */
final class UsageBreakdown
{
    /** The providers the page names first, in this order. */
    public const PROVIDERS = ['groq' => 'Groq', 'assemblyai' => 'AssemblyAI', 'elevenlabs' => 'ElevenLabs', 'soniox' => 'Soniox', 'anthropic' => 'Anthropic', 'gemini' => 'Gemini'];

    /** @var array<string, string> ledger key => provider */
    private const SAME_PROVIDER = ['claude' => 'anthropic'];

    /** US$: per hour of billed audio (streaming and final) and per million tokens read and written. */
    public const UNITS = ['live_hour' => 'Live, per audio hour', 'final_hour' => 'Final, per audio hour', 'input_mtok' => 'Per million tokens read', 'output_mtok' => 'Per million tokens written'];

    private const USE_CASE_LABELS = [
        'show_as' => 'Show as (transcript views)',
        'screen_describe' => 'Screen descriptions',
        'client_notes' => 'Call notes',
        'client_brief' => 'Client brief',
        'client_ask' => 'Ask',
        'client_fixes' => 'Suggested fixes',
        'client_file' => 'Reading files',
        'live_captions' => 'Live captions',
        'dictation' => 'Dictation',
        'final_transcript' => 'Final transcripts',
        'voice_note' => 'Voice notes',
        'game_translate' => 'Game translate',
        'watch_translate' => 'Watch translate',
    ];

    public function __construct(private readonly SettingsRepository $settings, private readonly PlanLimits $limits) {}

    /**
     * The price table: the list prices with the super admin's changes over them.
     *
     * @return array<string, array<string, float>>
     */
    public function prices(): array
    {
        $defaults = [];
        foreach (array_keys(self::PROVIDERS) as $provider) {
            $engine = SttProviderKey::tryFrom($provider);
            // The rates in Settings are what new rows are priced with: the table starts from them.
            $live = $engine === null ? 0.0 : $this->settings->priceFor($engine, SttUseCase::LiveCaptions);
            $defaults[$provider] = [
                'live_hour' => $live > 0.0 || $engine === null ? $live : $this->settings->priceFor($engine, SttUseCase::GameTranslate),
                'final_hour' => $engine === null ? 0.0 : $this->settings->priceFor($engine, SttUseCase::FinalTranscript),
                'input_mtok' => 0.0,
                'output_mtok' => 0.0,
            ];
        }
        [$in, $out] = ClaudeClientAi::PRICES[ClaudeClientAi::MODEL];
        $defaults['anthropic']['input_mtok'] = $in;
        $defaults['anthropic']['output_mtok'] = $out;

        $stored = $this->settings->get('usage.unit_prices');
        foreach ($defaults as $provider => $units) {
            foreach (array_keys($units) as $unit) {
                $value = is_array($stored) && is_array($stored[$provider] ?? null) ? ($stored[$provider][$unit] ?? null) : null;
                if ((is_int($value) || is_float($value)) && $value >= 0) {
                    $defaults[$provider][$unit] = (float) $value;
                }
            }
        }

        return $defaults;
    }

    /** @param  array<string, array<string, float|int>>  $prices */
    public function savePrices(array $prices): void
    {
        $this->settings->set('usage.unit_prices', $prices);
    }

    /**
     * One date range, for the whole server (no ids), a workspace, or one person in it.
     *
     * @return array{totals: array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float},
     *     providers: list<array{key: string, label: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float, use_cases: list<array{key: string, label: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}>}>,
     *     people: list<array{id: int, name: string, email: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float, plan_percent: float|null}>,
     *     workspaces: list<array{id: int, name: string, plan: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float, minutes_percent: float|null, storage_percent: float|null, ai_percent: float|null}>}
     */
    public function report(Carbon $from, Carbon $to, ?int $workspaceId = null, ?int $userId = null): array
    {
        $prices = $this->prices();
        $empty = ['minutes' => 0.0, 'input_tokens' => 0, 'output_tokens' => 0, 'cost_usd' => 0.0];
        $totals = $empty;
        $providers = [];
        $useCases = [];
        $people = [];
        $workspaces = [];
        foreach ($this->rows($from, $to, $workspaceId, $userId) as $row) {
            $key = self::providerOf((string) $row->provider);
            $line = $this->line($row, $key, $prices);
            $useCase = (string) $row->use_case;
            $totals = self::add($totals, $line);
            $providers[$key] = self::add($providers[$key] ?? $empty, $line);
            $useCases[$key][$useCase] = self::add($useCases[$key][$useCase] ?? $empty, $line);
            $people[(int) $row->user_id] = self::add($people[(int) $row->user_id] ?? $empty, $line);
            $workspaces[(int) $row->workspace_id] = self::add($workspaces[(int) $row->workspace_id] ?? $empty, $line);
        }

        return [
            'totals' => self::rounded($totals),
            'providers' => $this->providerRows($providers, $useCases),
            'people' => $this->peopleRows($people, $workspaceId),
            'workspaces' => $this->workspaceRows($workspaces),
        ];
    }

    /**
     * The last six months with this one, oldest first.
     *
     * @return list<array{month: string, label: string, minutes: float, cost_usd: float}>
     */
    public function trend(?int $workspaceId = null, ?int $userId = null, int $months = 6): array
    {
        $zone = $this->settings->displayTimezone();
        $start = Carbon::now($zone)->startOfMonth();
        $rows = [];
        for ($i = $months - 1; $i >= 0; $i--) {
            $month = $start->copy()->subMonthsNoOverflow($i);
            $totals = $this->report($month, $month->copy()->addMonthNoOverflow(), $workspaceId, $userId)['totals'];
            $rows[] = ['month' => $month->format('Y-m'), 'label' => $month->format('M Y'), 'minutes' => $totals['minutes'], 'cost_usd' => $totals['cost_usd']];
        }

        return $rows;
    }

    /**
     * The same numbers as CSV rows: one line per person, workspace and provider (with its use cases).
     *
     * @return list<list<string|int|float>>
     */
    public function csv(Carbon $from, Carbon $to, ?int $workspaceId = null, ?int $userId = null): array
    {
        $report = $this->report($from, $to, $workspaceId, $userId);
        $rows = [['Section', 'Name', 'Detail', 'Minutes', 'Tokens read', 'Tokens written', 'Cost (US$)', '% of plan minutes']];
        foreach ($report['providers'] as $provider) {
            $rows[] = ['Provider', self::cell($provider['label']), '', $provider['minutes'], $provider['input_tokens'], $provider['output_tokens'], $provider['cost_usd'], ''];
            foreach ($provider['use_cases'] as $useCase) {
                $rows[] = ['Use case', self::cell($provider['label']), self::cell($useCase['label']), $useCase['minutes'], $useCase['input_tokens'], $useCase['output_tokens'], $useCase['cost_usd'], ''];
            }
        }
        foreach ($report['workspaces'] as $workspace) {
            $rows[] = ['Workspace', self::cell($workspace['name']), self::cell($workspace['plan']), $workspace['minutes'], $workspace['input_tokens'], $workspace['output_tokens'], $workspace['cost_usd'], $workspace['minutes_percent'] ?? ''];
        }
        foreach ($report['people'] as $person) {
            $rows[] = ['Person', self::cell($person['name']), self::cell($person['email']), $person['minutes'], $person['input_tokens'], $person['output_tokens'], $person['cost_usd'], $person['plan_percent'] ?? ''];
        }
        $rows[] = ['Total', '', '', $report['totals']['minutes'], $report['totals']['input_tokens'], $report['totals']['output_tokens'], $report['totals']['cost_usd'], ''];

        return $rows;
    }

    public static function providerOf(string $ledgerKey): string
    {
        return self::SAME_PROVIDER[$ledgerKey] ?? $ledgerKey;
    }

    /** A value a spreadsheet would run as a formula is kept as text: names come from people. */
    private static function cell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }

    /** @return iterable<object> grouped so a long range stays a short read */
    private function rows(Carbon $from, Carbon $to, ?int $workspaceId, ?int $userId): iterable
    {
        return UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)
            ->when($workspaceId !== null, fn ($q) => $q->where('workspace_id', $workspaceId))
            ->when($userId !== null, fn ($q) => $q->where('user_id', $userId))
            ->where('occurred_at', '>=', $from->copy()->utc())->where('occurred_at', '<', $to->copy()->utc())
            ->toBase()
            ->selectRaw('provider, use_case, source, billable, user_id, workspace_id, SUM(billed_ms) AS billed_ms, SUM(COALESCE(input_tokens, 0)) AS input_tokens, SUM(COALESCE(output_tokens, 0)) AS output_tokens, SUM(cost_usd) AS cost_usd, SUM(CASE WHEN billed_ms = 0 AND input_tokens IS NULL AND output_tokens IS NULL THEN cost_usd ELSE 0 END) AS stored_only_usd')
            ->groupBy('provider', 'use_case', 'source', 'billable', 'user_id', 'workspace_id')
            ->get();
    }

    /**
     * @param  array<string, array<string, float>>  $prices
     * @return array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}
     */
    private function line(object $row, string $provider, array $prices): array
    {
        $billed = (int) $row->billed_ms;
        $in = (int) $row->input_tokens;
        $out = (int) $row->output_tokens;
        $table = $prices[$provider] ?? null;
        if (! (bool) $row->billable) {
            $cost = 0.0;
        } elseif ($table === null) {
            // A provider the table does not list (self-hosted, Azure): what the ledger stored.
            $cost = (float) $row->cost_usd;
        } else {
            $hour = $row->source === UsageLedgerEntry::SOURCE_DEVICE ? $table['live_hour'] : $table['final_hour'];
            $cost = $billed / 3_600_000 * $hour + $in / 1_000_000 * $table['input_mtok'] + $out / 1_000_000 * $table['output_mtok'] + (float) $row->stored_only_usd;
        }

        return ['minutes' => (bool) $row->billable ? $billed / 60000 : 0.0, 'input_tokens' => $in, 'output_tokens' => $out, 'cost_usd' => $cost];
    }

    /**
     * @param  array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}  $a
     * @param  array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}  $b
     * @return array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}
     */
    private static function add(array $a, array $b): array
    {
        return array_replace($a, [
            'minutes' => $a['minutes'] + $b['minutes'],
            'input_tokens' => $a['input_tokens'] + $b['input_tokens'],
            'output_tokens' => $a['output_tokens'] + $b['output_tokens'],
            'cost_usd' => $a['cost_usd'] + $b['cost_usd'],
        ]);
    }

    /**
     * @param  array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}  $row
     * @return array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}
     */
    private static function rounded(array $row): array
    {
        return array_replace($row, ['minutes' => round($row['minutes'], 1), 'cost_usd' => round($row['cost_usd'], 4)]);
    }

    /**
     * Named providers first in their order, then anything else the ledger has, each with its use cases by cost.
     *
     * @param  array<string, array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}>  $providers
     * @param  array<string, array<string, array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}>>  $useCases
     * @return list<array{key: string, label: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float, use_cases: list<array{key: string, label: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}>}>
     */
    private function providerRows(array $providers, array $useCases): array
    {
        $order = array_keys(self::PROVIDERS);
        uksort($providers, function (string $a, string $b) use ($order): int {
            $x = array_search($a, $order, true);
            $y = array_search($b, $order, true);

            return ($x === false ? 99 : $x) <=> ($y === false ? 99 : $y) ?: $a <=> $b;
        });
        $rows = [];
        foreach ($providers as $key => $provider) {
            $cases = [];
            foreach ($useCases[$key] ?? [] as $useCase => $sum) {
                $cases[] = ['key' => (string) $useCase, 'label' => self::USE_CASE_LABELS[$useCase] ?? (string) $useCase, ...self::rounded($sum)];
            }
            usort($cases, fn (array $a, array $b): int => $b['cost_usd'] <=> $a['cost_usd'] ?: $a['label'] <=> $b['label']);
            $rows[] = ['key' => $key, 'label' => self::PROVIDERS[$key] ?? SttProviderKey::tryFrom($key)?->getLabel() ?? $key, ...self::rounded($provider), 'use_cases' => $cases];
        }

        return $rows;
    }

    /**
     * @param  array<int, array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}>  $people
     * @return list<array{id: int, name: string, email: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float, plan_percent: float|null}>
     */
    private function peopleRows(array $people, ?int $workspaceId): array
    {
        $users = User::query()->whereIn('id', array_keys($people))->get(['id', 'name', 'email'])->keyBy('id');
        $limit = $workspaceId !== null ? $this->limits->plan($workspaceId)->monthly_minutes : null;
        $rows = [];
        foreach ($people as $id => $sum) {
            $user = $users[$id] ?? null;
            $rows[] = [
                'id' => $id,
                'name' => $user instanceof User ? $user->name : 'A deleted account',
                'email' => $user instanceof User ? $user->email : '',
                ...self::rounded($sum),
                'plan_percent' => $limit !== null && $limit > 0 ? round($sum['minutes'] / $limit * 100, 1) : null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes'] ?: $b['cost_usd'] <=> $a['cost_usd']);

        return $rows;
    }

    /**
     * What a workspace used in the range, and how much of its plan it has used this month.
     *
     * @param  array<int, array{minutes: float, input_tokens: int, output_tokens: int, cost_usd: float}>  $workspaces
     * @return list<array{id: int, name: string, plan: string, minutes: float, input_tokens: int, output_tokens: int, cost_usd: float, minutes_percent: float|null, storage_percent: float|null, ai_percent: float|null}>
     */
    private function workspaceRows(array $workspaces): array
    {
        $found = Workspace::query()->with('plan')->whereIn('id', array_keys($workspaces))->get()->keyBy('id');
        $rows = [];
        foreach ($workspaces as $id => $sum) {
            $workspace = $found[$id] ?? null;
            if (! $workspace instanceof Workspace) {
                continue;
            }
            $plan = $workspace->plan;
            $rows[] = [
                'id' => $id,
                'name' => $workspace->name,
                'plan' => $plan->name,
                ...self::rounded($sum),
                'minutes_percent' => self::percent($this->limits->minutesUsed($id), $plan->monthly_minutes),
                'storage_percent' => self::percent((float) $this->limits->storageUsed($id), $plan->storage_bytes),
                'ai_percent' => self::percent(app(ClientWorkspace::class)->budget($id)['spent_usd'], $plan->aiCredits()),
            ];
        }
        usort($rows, fn (array $a, array $b): int => $b['minutes'] <=> $a['minutes'] ?: $b['cost_usd'] <=> $a['cost_usd']);

        return $rows;
    }

    private static function percent(float $used, float|int|null $limit): ?float
    {
        return $limit !== null && $limit > 0 ? round($used / $limit * 100, 1) : null;
    }
}
