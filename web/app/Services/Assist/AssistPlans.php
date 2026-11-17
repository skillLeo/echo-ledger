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
 * Practice mode every plan has, and the month's assist time and model budget, less what they used this month.
 */
final class AssistPlans
{
    public function __construct(private readonly PlanLimits $limits, private readonly SettingsRepository $settings) {}

    /**
     * The workspace's plan for Live Assist.
     *
     * @return array{key: string, claim: string, live_modes: list<string>, seconds: int, cents: int}
     */
    public function for(int $workspaceId): array
    {
        $plan = $this->limits->plan($workspaceId);

        return ['key' => $plan->key, ...self::config($plan->key)];
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
     * What is left this month: assist seconds and model budget in US cents (never below 0).
     *
     * @return array{seconds_left: int, cents_left: int}
     */
    public function left(int $workspaceId, int $userId): array
    {
        $plan = $this->for($workspaceId);
        $from = $this->monthStart();
        $seconds = 0;
        AssistSession::query()->withoutGlobalScopes()->withTrashed()->where('user_id', $userId)->where('started_at', '>=', $from)
            ->each(function (AssistSession $session) use (&$seconds): void {
                $seconds += $session->seconds();
            });
        $spent = (float) AssistUsage::query()->where('user_id', $userId)->where('occurred_at', '>=', $from)->sum('cost_usd');

        return [
            'seconds_left' => max(0, $plan['seconds'] - $seconds),
            'cents_left' => max(0, (int) floor($plan['cents'] - $spent * 100)),
        ];
    }

    /** `402 budget_exhausted` when the month's assist time or model budget is used up. */
    public function assertLeft(int $workspaceId, int $userId, bool $practice): void
    {
        $left = $this->left($workspaceId, $userId);
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
     * @return array{claim: string, live_modes: list<string>, seconds: int, cents: int}
     */
    public static function config(string $planKey): array
    {
        $plans = (array) config('assist.plans', []);
        $plan = (array) ($plans[$planKey] ?? $plans[Plan::FREE] ?? []);

        return [
            'claim' => in_array($plan['claim'] ?? null, ['free', 'pro', 'ultra'], true) ? (string) $plan['claim'] : 'free',
            // In the plan's own order (the cheapest first, as the contract's example); only modes there are.
            'live_modes' => array_values(array_filter((array) ($plan['live_modes'] ?? []), fn (mixed $m): bool => in_array($m, (array) config('assist.modes_order'), true))),
            'seconds' => (int) ($plan['seconds'] ?? 0),
            'cents' => (int) ($plan['cents'] ?? 0),
        ];
    }

    private function monthStart(): Carbon
    {
        return Carbon::now($this->settings->displayTimezone())->startOfMonth()->utc();
    }
}
