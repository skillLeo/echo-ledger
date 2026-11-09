<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Enums\SttProviderKey;
use App\Exceptions\ApiError;
use App\Models\Plan;
use App\Models\Scopes\WorkspaceScope;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Usage\UsageLedger;
use App\Services\Workspaces\PlanLimits;

/**
 * What a person may use (since v1.6, P1, docs/API.md "Entitlements"): the server alone decides. A person acting in a
 * workspace has its plan's features and limits (the default plan once the plan's end date has passed), with the super
 * admin's overrides for that person on top (a feature on or off, a limit higher, lower or none). Every route that uses
 * something paid asks here (through App\Services\Workspaces\PlanLimits for the limits); the apps only show the answer
 * of `GET /api/me/entitlements`.
 */
final class Entitlements
{
    /** What `403 feature_not_in_plan` says for each feature. */
    public const LOCKED = [
        'platform_transcription' => 'Transcription on EchoLedger is not in your plan: add your free Groq key in Account → Transcription key, or upgrade',
        'views' => 'Roman Urdu, English and Urdu views are not in your plan',
        'double_check' => 'Double-check is not in your plan',
        'screen_moments' => 'Screen moments are not in your plan',
        'connector' => 'The Claude and ChatGPT connector is not in your plan',
        'team' => 'Team workspaces are not in your plan',
        'premium_engines' => 'Premium engines are not in your plan',
        'live_translate' => 'Live Translate is not in your plan',
        'ai_notes' => 'AI notes are not in your plan',
    ];

    /** @var array<int, Plan> */
    private array $plans = [];

    /** The workspace's plan as it applies now: past its end date, the default plan. */
    public function plan(int $workspaceId): Plan
    {
        if (isset($this->plans[$workspaceId])) {
            return $this->plans[$workspaceId];
        }
        $workspace = Workspace::query()->with('plan')->find($workspaceId);
        $plan = match (true) {
            ! $workspace instanceof Workspace => Plan::personal(),
            $workspace->plan_expires_at !== null && $workspace->plan_expires_at->isPast() => Plan::defaultPlan(),
            default => $workspace->plan ?? Plan::personal(),
        };

        // Kept for this request only (the service is made again for the next one).
        return $this->plans[$workspaceId] = $plan;
    }

    /** Forgets what was read, after a plan or an override changed. */
    public function forget(): void
    {
        $this->plans = [];
    }

    /** @return array{features: array<string, bool>, limits: array<string, int|float|null>} the person's own overrides */
    public function overrides(?int $userId): array
    {
        $raw = $userId === null ? null : User::query()->whereKey($userId)->value('entitlement_overrides');
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;
        $features = is_array($raw['features'] ?? null) ? array_filter($raw['features'], fn ($v, $k): bool => is_bool($v) && isset(Features::ALL[$k]), ARRAY_FILTER_USE_BOTH) : [];
        $limits = [];
        foreach (is_array($raw['limits'] ?? null) ? $raw['limits'] : [] as $key => $value) {
            if (isset(Features::LIMITS[$key]) && $key !== 'retention_days' && ($value === null || is_numeric($value))) {
                $limits[$key] = $value === null ? null : ($key === 'ai_usd_month' ? (float) $value : (int) $value);
            }
        }

        return ['features' => $features, 'limits' => $limits];
    }

    public function allows(int $workspaceId, ?int $userId, string $feature): bool
    {
        if (Features::isFree($feature)) {
            return true;
        }
        $overrides = $this->overrides($userId)['features'];

        return $overrides[$feature] ?? $this->plan($workspaceId)->allows($feature);
    }

    /** @return array<string, bool> every feature of the registry, on or off */
    public function features(int $workspaceId, ?int $userId): array
    {
        $map = [];
        foreach (Features::keys() as $feature) {
            $map[$feature] = $this->allows($workspaceId, $userId, $feature);
        }

        return $map;
    }

    /** A limit of the registry for this person in this workspace; null = no limit. */
    public function limit(int $workspaceId, ?int $userId, string $key): int|float|null
    {
        $overrides = $this->overrides($userId)['limits'];

        return array_key_exists($key, $overrides) ? $overrides[$key] : $this->plan($workspaceId)->limit($key);
    }

    /** `403 feature_not_in_plan` with `feature` and `lowest_plan` (the plan whose badge the apps show). */
    public function assertFeature(int $workspaceId, ?int $userId, string $feature): void
    {
        if (! $this->allows($workspaceId, $userId, $feature)) {
            throw self::locked($feature);
        }
    }

    public static function locked(string $feature): ApiError
    {
        return new ApiError(403, 'feature_not_in_plan', self::LOCKED[$feature] ?? 'This is not in your plan', [
            'feature' => $feature,
            'lowest_plan' => app(self::class)->lowestPlan($feature)?->key,
        ]);
    }

    /**
     * The plans on offer, cheapest first.
     *
     * @return list<Plan>
     */
    public function offered(): array
    {
        return Plan::query()->where('on_offer', true)->orderBy('sort_order')->orderBy('price_usd')->orderBy('id')->get()->all();
    }

    /** The cheapest plan on offer that grants [$feature]. */
    public function lowestPlan(string $feature): ?Plan
    {
        foreach ($this->offered() as $plan) {
            if ($plan->allows($feature)) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * Trial minutes of transcription on EchoLedger left for a person whose plan leaves it out (the plan's trial minutes,
     * set by the admin, less what the person's recordings ever used on the server's engines); 0 with the feature.
     */
    public function trialMinutesLeft(int $workspaceId, ?int $userId): int
    {
        $trial = $this->plan($workspaceId)->trial_minutes;
        if ($userId === null || $trial <= 0 || $this->allows($workspaceId, $userId, 'platform_transcription')) {
            return 0;
        }
        $used = (int) UsageLedgerEntry::query()->withoutGlobalScope(WorkspaceScope::class)
            ->where('user_id', $userId)->where('billable', true)->sum('billed_ms') / 60000;

        return max(0, $trial - (int) ceil($used));
    }

    /** Whether the server's own engines may transcribe this person's recording: the feature, or trial minutes left. */
    public function platformTranscription(int $workspaceId, ?int $userId): bool
    {
        return $this->allows($workspaceId, $userId, 'platform_transcription') || $this->trialMinutesLeft($workspaceId, $userId) > 0;
    }

    /** @return list<string> the engines this person may use now */
    public function engines(int $workspaceId, ?int $userId): array
    {
        $platform = $this->platformTranscription($workspaceId, $userId);
        $premium = $this->allows($workspaceId, $userId, 'premium_engines');

        return array_values(array_filter($this->plan($workspaceId)->engineList(), fn (string $engine): bool => in_array($engine, Features::OWN_ENGINES, true)
            || ($platform && (! in_array($engine, Features::PREMIUM_ENGINES, true) || $premium))));
    }

    /** Whether the server's engine [$provider] may transcribe this person's recording (the speech provider key). */
    public function allowsEngine(int $workspaceId, ?int $userId, SttProviderKey $provider): bool
    {
        if (! UsageLedger::isBillable($provider)) {
            return true; // on-device and self-hosted engines cost nothing
        }

        return in_array($provider->value, $this->engines($workspaceId, $userId), true);
    }

    /**
     * `GET /api/me/entitlements` (docs/API.md "Entitlements"): the plan, every feature, every limit with its use, the
     * engines, the person's own transcription key, the newest upgrade request and the plans on offer.
     *
     * @return array<string, mixed>
     */
    public function payload(User $user, int $workspaceId): array
    {
        $plan = $this->plan($workspaceId);
        $workspace = Workspace::query()->find($workspaceId);
        $limits = app(PlanLimits::class);
        $features = [];
        foreach (Features::ALL as $key => $feature) {
            $features[$key] = [
                'enabled' => $this->allows($workspaceId, $user->id, $key),
                'name' => $feature['name'],
                'description' => $feature['description'],
                'cost' => $feature['cost'],
                'lowest_plan' => $feature['cost'] === Features::FREE ? null : $this->lowestPlan($key)?->key,
            ];
        }
        $limit = fn (string $key): int|float|null => $this->limit($workspaceId, $user->id, $key);

        return [
            'plan' => [
                'key' => $plan->key,
                'name' => $plan->name,
                'badge' => $plan->badgeText(),
                'color' => $plan->colorOrDefault(),
                'expires_at' => $workspace?->plan_expires_at?->toAtomString(),
                'trial_minutes_left' => $this->trialMinutesLeft($workspaceId, $user->id),
            ],
            'features' => $features,
            'limits' => [
                'monthly_minutes' => ['limit' => $limit('monthly_minutes'), 'used' => (int) round($limits->minutesUsed($workspaceId))],
                'devices' => ['limit' => $limit('devices'), 'used' => $limits->devicesUsed($workspaceId, $user->id)],
                'storage_bytes' => ['limit' => $limit('storage_bytes'), 'used' => $limits->storageUsed($workspaceId)],
                'retention_days' => ['limit' => $limit('retention_days')],
                'seats' => ['limit' => $limit('seats'), 'used' => $limits->seatsUsed($workspaceId)],
                'max_file_minutes' => ['limit' => $limit('max_file_minutes')],
                'uploads_per_day' => ['limit' => $limit('uploads_per_day'), 'used' => $limits->uploadsToday($workspaceId, $user->id)],
                'ai_usd_month' => ['limit' => $limit('ai_usd_month'), 'used' => round($limits->aiSpent($workspaceId), 2)],
            ],
            'engines' => $this->engines($workspaceId, $user->id),
            'transcription_key' => app(TranscriptionKeys::class)->describe($user),
            'upgrade_request' => app(UpgradeRequests::class)->latestPayload($user),
            'plans' => array_map(fn (Plan $p): array => self::planCard($p), $this->offered()),
        ];
    }

    /** @return array<string, mixed> a plan as the comparison sheet shows it */
    public static function planCard(Plan $plan): array
    {
        $limits = [];
        foreach (array_keys(Features::LIMITS) as $key) {
            $limits[$key] = $plan->limit($key);
        }

        return [
            'key' => $plan->key,
            'name' => $plan->name,
            'badge' => $plan->badgeText(),
            'color' => $plan->colorOrDefault(),
            'price' => filled($plan->price_label) ? $plan->price_label : ((float) $plan->price_usd > 0 ? '$'.number_format((float) $plan->price_usd, 2).' a month' : 'Free'),
            'description' => $plan->description,
            'features' => array_values(array_filter(Features::paid(), fn (string $f): bool => $plan->allows($f))),
            'limits' => $limits,
        ];
    }
}
