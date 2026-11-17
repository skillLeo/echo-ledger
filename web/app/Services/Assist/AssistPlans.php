<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Exceptions\ApiError;
use App\Models\AssistSession;
use App\Models\AssistUsage;
use App\Models\Plan;
use App\Services\Entitlements\Entitlements;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use Illuminate\Support\Carbon;

/**
 * What Live Assist allows a person (docs/API_ASSIST.md "Plans", config/assist.php): the live modes of their plan, the
 * Practice mode every plan has, and the month's assist time and model budget, less what they used this month. Live
 * sessions and Practice have separate allowances (since 1.9.0-h): each counts only its own sessions and their usage.
 */
final class AssistPlans
{
    /** The relay accepts a token this long past its expiry (services/assist-relay/src/auth.rs leeway). */
    public const RELAY_LEEWAY_SECONDS = 60;

    /** Spending this soon after a claim may predate it (clocks and posts lag): it does not count against the claim. */
    public const CLAIM_SKEW_SECONDS = 5;

    public function __construct(private readonly PlanLimits $limits, private readonly SettingsRepository $settings) {}

    /**
     * The workspace's plan for Live Assist.
     *
     * @return array{key: string, claim: string, live_modes: list<string>, seconds: int, cents: int, practice: array{seconds: int, cents: int}}
     */
    public function for(int $workspaceId): array
    {
        $plan = $this->limits->plan($workspaceId);

        return ['key' => $plan->key, ...self::config($plan->key, $plan)];
    }

    /**
     * The modes a session may run: the plan's live modes, or Practice's on every plan.
     *
     * @return list<string>
     */
    public function modes(int $workspaceId, bool $practice): array
    {
        return $practice ? array_values((array) config('assist.practice_modes', ['fast'])) : $this->for($workspaceId)['live_modes'];
    }

    /**
     * What is left this month of the live allowance, or of Practice's: assist seconds and model budget in US cents
     * (never below 0). Each counts only its own kind of session, and the usage of those sessions (pending reservations
     * too), less what the relay tokens of the sessions still on granted and they have not spent yet (held()).
     *
     * @return array{seconds_left: int, cents_left: int}
     */
    public function left(int $workspaceId, int $userId, bool $practice, ?string $exceptClaimOf = null): array
    {
        $plan = $this->for($workspaceId);
        $allowance = $practice ? $plan['practice'] : ['seconds' => $plan['seconds'], 'cents' => $plan['cents']];
        $from = $this->monthStart();
        $seconds = 0;
        $sessions = fn () => AssistSession::query()->withoutGlobalScopes()->withTrashed()->where('user_id', $userId)->where('practice', $practice);
        $sessions()->where('started_at', '>=', $from)->each(function (AssistSession $session) use (&$seconds): void {
            $seconds += $session->seconds();
        });
        $spent = (float) AssistUsage::query()->where('user_id', $userId)->where('occurred_at', '>=', $from)
            ->whereIn('assist_session_id', $sessions()->select('id'))->sum('cost_usd');

        return [
            'seconds_left' => max(0, $allowance['seconds'] - $seconds),
            // Rounded first: $4.98 in decimals is 497.99999… cents in floats, which floor() would make one cent less.
            'cents_left' => max(0, (int) floor(round($allowance['cents'] - $spent * 100, 6)) - $this->held($userId, $practice, $exceptClaimOf)),
        ];
    }

    /**
     * What the relay may still spend of what its tokens granted, in cents: for each session still on (of this kind),
     * the budget its last token claimed less what the session spent since, never below 0. Both ends lean to holding
     * more, never less: a claim holds for the token's lifetime plus the relay's 60 s of leeway on its expiry, and only
     * spending from 5 s after the claim counts against it (the relay's clock and its posts may lag the server's, so a
     * call stamped just after the claim may have been made under the token before). [$except] is the session whose own
     * claim is being made again (a token refresh).
     */
    private function held(int $userId, bool $practice, ?string $except): int
    {
        $held = 0;
        AssistSession::query()->withoutGlobalScopes()->where('user_id', $userId)->where('practice', $practice)->where('status', 'active')
            ->whereNotNull('claimed_cents')->where('claimed_at', '>', now()->subSeconds((int) config('assist.token_ttl_seconds', 900) + self::RELAY_LEEWAY_SECONDS))
            ->when($except !== null, fn ($q) => $q->whereKeyNot($except))
            ->each(function (AssistSession $session) use (&$held): void {
                $since = (float) AssistUsage::query()->where('assist_session_id', $session->id)
                    ->where('occurred_at', '>', $session->claimed_at->copy()->addSeconds(self::CLAIM_SKEW_SECONDS))->sum('cost_usd');
                $held += max(0, (int) $session->claimed_cents - (int) ceil($since * 100));
            });

        return $held;
    }

    /** `402 budget_exhausted` when the month's assist time or model budget (live, or Practice's) is used up. */
    public function assertLeft(int $workspaceId, int $userId, bool $practice): void
    {
        $left = $this->left($workspaceId, $userId, $practice);
        if ($left['seconds_left'] <= 0 || $left['cents_left'] <= 0) {
            throw new ApiError(402, 'budget_exhausted', $practice
                ? 'This month\'s Practice time is used up. It starts again next month.'
                : 'This month\'s Live Assist time is used up. Captions keep working.', ['fallback' => $practice ? 'none' : 'captions_only']);
        }
    }

    /** `403 feature_not_in_plan` for a live session on a plan without live modes, or a mode the plan leaves out. */
    public function assertMode(int $workspaceId, string $mode, bool $practice): void
    {
        if ($practice) {
            return;
        }
        $live = $this->for($workspaceId)['live_modes'];
        if ($live === []) {
            throw new ApiError(403, 'feature_not_in_plan', 'Live Assist during calls is not in your plan. Practice is.', [
                'feature' => 'live_assist', 'lowest_plan' => $this->lowestPlan(null),
            ]);
        }
        if (! in_array($mode, $live, true)) {
            throw new ApiError(403, 'feature_not_in_plan', 'This Live Assist mode is not in your plan.', [
                'feature' => 'assist_mode', 'mode' => $mode, 'lowest_plan' => $this->lowestPlan($mode),
            ]);
        }
    }

    /** The cheapest plan on offer with [$mode] live (any live mode when null). */
    public function lowestPlan(?string $mode): ?string
    {
        foreach (app(Entitlements::class)->offered() as $plan) {
            $live = self::config($plan->key)['live_modes'];
            if ($mode === null ? $live !== [] : in_array($mode, $live, true)) {
                return $plan->key;
            }
        }

        return null;
    }

    /**
     * The Live Assist values of one plan key; a plan the file does not name gets Free's.
     *
     * @return array{claim: string, live_modes: list<string>, seconds: int, cents: int, practice: array{seconds: int, cents: int}}
     */
    public static function config(string $planKey, ?Plan $row = null): array
    {
        $plans = (array) config('assist.plans', []);
        $plan = (array) ($plans[$planKey] ?? $plans[Plan::FREE] ?? []);
        // Since v2.0 the plan's own allowances (Admin › Plans) win; an empty one is the config's.
        $minutes = fn (?int $value, int $fallback): int => $value !== null ? max(0, $value) * 60 : $fallback;
        $cents = fn (mixed $usd, int $fallback): int => $usd !== null ? max(0, (int) round((float) $usd * 100)) : $fallback;

        return [
            'claim' => in_array($plan['claim'] ?? null, ['free', 'pro', 'ultra'], true) ? (string) $plan['claim'] : 'free',
            // In the plan's own order (the cheapest first, as the contract's example); only modes there are.
            'live_modes' => array_values(array_filter((array) ($plan['live_modes'] ?? []), fn (mixed $m): bool => in_array($m, (array) config('assist.modes_order'), true))),
            'seconds' => $minutes($row?->assist_minutes, (int) ($plan['seconds'] ?? 0)),
            'cents' => $cents($row?->assist_usd, (int) ($plan['cents'] ?? 0)),
            'practice' => [
                'seconds' => $minutes($row?->practice_minutes, (int) ($plan['practice']['seconds'] ?? 0)),
                'cents' => $cents($row?->practice_usd, (int) ($plan['practice']['cents'] ?? 0)),
            ],
        ];
    }

    private function monthStart(): Carbon
    {
        return Carbon::now($this->settings->displayTimezone())->startOfMonth()->utc();
    }
}
