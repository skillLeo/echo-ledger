<?php

declare(strict_types=1);

namespace App\Models;

use App\Services\Entitlements\Features;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Limits and features of a workspace (B20, extended in v1.5, docs/API.md "Plans and features"): minutes per month,
 * storage, paired devices, retention days, seats and AI credits per month (`null` means no limit), and the features
 * `live_translate`, `screen_moments`, `ai_notes` and `connector`. Enforced in one place,
 * App\Services\Workspaces\PlanLimits. The price is shown only: there is no billing yet.
 *
 * @property int $id
 * @property string $key
 * @property string $name
 * @property string $price_usd
 * @property int|null $monthly_minutes
 * @property int|null $storage_bytes
 * @property int|null $max_devices
 * @property int|null $retention_days
 * @property int|null $seats
 * @property string|null $ai_credits_usd
 * @property array<string, bool>|null $features
 * @property string|null $badge since v1.6: "PRO", shown with the plan's colour
 * @property string|null $color
 * @property string|null $description
 * @property string|null $price_label the price as shown ("Rs 2,000 a month")
 * @property int $sort_order
 * @property bool $on_offer whether the apps' plan comparison lists it
 * @property bool $is_default the plan new sign-ups get (and an expired plan falls back to)
 * @property int $trial_minutes minutes of transcription on EchoLedger a person may try without the feature
 * @property int|null $max_file_minutes
 * @property int|null $uploads_per_day
 * @property list<string>|null $engines null = every engine
 * @property Carbon|null $retention_from the plan's retention applies to recordings made since; null = to every one
 * @property Carbon|null $archived_at since v1.6 (P2.3): no longer offered or given to anyone new; its workspaces keep it
 * @property string|null $price_amount since v1.6 (P2.3): the display price's amount, with $currency
 * @property string|null $currency ISO 4217, e.g. PKR or USD
 */
class Plan extends Model
{
    public const PERSONAL = 'personal';

    /** The plan new sign-ups get (since v1.5). */
    public const FREE = 'free';

    /**
     * Features a plan can leave out: since v1.6 every paid feature of the registry (App\Services\Entitlements\Features);
     * the free ones are in every plan.
     */
    public const FEATURES = ['platform_transcription', 'views', 'double_check', 'screen_moments', 'connector', 'team', 'premium_engines', 'live_translate', 'ai_notes'];

    /** The four features the v1.5 apps read in `GET /api/config` (`plan.features`). */
    public const V15_FEATURES = ['live_translate', 'screen_moments', 'ai_notes', 'connector'];

    /** What the features are called in messages and on the Plans page. */
    public const FEATURE_LABELS = [
        'platform_transcription' => 'Transcription on EchoLedger',
        'views' => 'Roman Urdu, English and Urdu views',
        'double_check' => 'Double-check',
        'screen_moments' => 'Screen moments',
        'connector' => 'Claude and ChatGPT connector',
        'team' => 'Team workspace',
        'premium_engines' => 'Premium engines (ElevenLabs, Gemini)',
        'live_translate' => 'Live Translate',
        'ai_notes' => 'AI notes (project notes, memory and Ask)',
    ];

    /** Personal: every feature and no limits (every workspace made before v1.5 is on it). */
    public const PERSONAL_DEFAULTS = ['name' => 'Personal', 'monthly_minutes' => null, 'storage_bytes' => null, 'max_devices' => null,
        'retention_days' => null, 'seats' => null, 'ai_credits_usd' => null, 'price_usd' => 0];

    /**
     * Free (since v1.6, the default plan; the migration of the v1.6 plans sets the same): the free features only, 2
     * devices, 1 GB, 30 days, one seat, no minutes of the server's own transcription (the person's own Groq key or the
     * computer), no AI work.
     */
    public const FREE_DEFAULTS = ['name' => 'Free', 'badge' => 'FREE', 'color' => '#64748b', 'price_label' => 'Free', 'monthly_minutes' => 0, 'storage_bytes' => 1024 ** 3,
        'max_devices' => 2, 'retention_days' => 30, 'seats' => 1, 'ai_credits_usd' => 0, 'price_usd' => 0, 'max_file_minutes' => 180, 'uploads_per_day' => 50,
        'on_offer' => true, 'engines' => ['own_groq', 'on_device'],
        'features' => ['platform_transcription' => false, 'views' => false, 'double_check' => false, 'screen_moments' => false, 'connector' => false,
            'team' => false, 'premium_engines' => false, 'live_translate' => false, 'ai_notes' => false]];

    protected $fillable = ['key', 'name', 'price_usd', 'monthly_minutes', 'storage_bytes', 'max_devices', 'retention_days', 'seats', 'ai_credits_usd', 'features',
        'badge', 'color', 'description', 'price_label', 'sort_order', 'on_offer', 'is_default', 'trial_minutes', 'max_file_minutes', 'uploads_per_day', 'engines', 'retention_from', 'archived_at', 'price_amount', 'currency'];

    protected static function booted(): void
    {
        // Since v1.6 a plan's retention applies to recordings made from when it was set, never to older ones.
        static::saving(function (Plan $plan): void {
            if ($plan->retention_days !== null && $plan->retention_from === null && ($plan->exists === false || $plan->isDirty('retention_days'))) {
                $plan->retention_from = now();
            }
        });
    }

    public static function personal(): self
    {
        return self::query()->firstOrCreate(['key' => self::PERSONAL], [...self::PERSONAL_DEFAULTS, 'features' => array_fill_keys(self::FEATURES, true)]);
    }

    public static function free(): self
    {
        return self::query()->firstOrCreate(['key' => self::FREE], self::FREE_DEFAULTS);
    }

    /** The plan new sign-ups get and an expired plan falls back to (since v1.6): the one marked default, else Free. */
    public static function defaultPlan(): self
    {
        return self::query()->where('is_default', true)->whereNull('archived_at')->orderBy('id')->first() ?? self::free();
    }

    /** Currencies the display price may be in (since v1.6, P2.3). */
    public const CURRENCIES = ['PKR' => 'Rs', 'USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED', 'SAR' => 'SAR', 'INR' => '₹'];

    /**
     * The price as the apps show it: the plan's own text, else its amount and currency ("Rs 2,000 a month"), else its US
     * dollars, else Free.
     */
    public function displayPrice(): string
    {
        if (filled($this->price_label)) {
            return (string) $this->price_label;
        }
        if ($this->price_amount !== null && (float) $this->price_amount > 0) {
            $amount = (float) $this->price_amount;
            $formatted = number_format($amount, fmod($amount, 1.0) === 0.0 ? 0 : 2);
            $symbol = self::CURRENCIES[(string) $this->currency] ?? (string) $this->currency;

            return trim($symbol.' '.$formatted).' a month';
        }

        return (float) $this->price_usd > 0 ? '$'.number_format((float) $this->price_usd, 2).' a month' : 'Free';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Whether the plan includes the feature: a free feature always; a paid one unless the plan leaves it out (a plan that
     * does not name it, or names none, includes it, as before v1.6).
     */
    public function allows(string $feature): bool
    {
        if (Features::isFree($feature)) {
            return true;
        }
        $features = $this->features;

        return ! is_array($features) || ($features[$feature] ?? true) !== false;
    }

    /** @return array<string, bool> every feature a plan can leave out, on or off */
    public function featureMap(): array
    {
        return array_combine(self::FEATURES, array_map(fn (string $f): bool => $this->allows($f), self::FEATURES));
    }

    /** The plan's value of a limit of the registry (Features::LIMITS); null = no limit. */
    public function limit(string $key): int|float|null
    {
        $column = Features::LIMITS[$key] ?? null;
        if ($column === null) {
            return null;
        }
        $value = $this->getAttribute($column);

        return $value === null ? null : ($key === 'ai_usd_month' ? (float) $value : (int) $value);
    }

    /** @return list<string> the engines the plan may use */
    public function engineList(): array
    {
        return is_array($this->engines) ? array_values(array_intersect(Features::ENGINES, $this->engines)) : Features::ENGINES;
    }

    /** "PRO", from the name when no badge is set. */
    public function badgeText(): string
    {
        return mb_strtoupper(filled($this->badge) ? (string) $this->badge : mb_substr($this->name, 0, 16));
    }

    public function colorOrDefault(): string
    {
        return is_string($this->color) && preg_match('/^#[0-9a-fA-F]{6}$/', $this->color) === 1 ? $this->color : '#64748b';
    }

    public function aiCredits(): ?float
    {
        return $this->ai_credits_usd === null ? null : (float) $this->ai_credits_usd;
    }

    /** @return HasMany<Workspace, $this> */
    public function workspaces(): HasMany
    {
        return $this->hasMany(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monthly_minutes' => 'integer', 'storage_bytes' => 'integer', 'max_devices' => 'integer', 'retention_days' => 'integer',
            'seats' => 'integer', 'price_usd' => 'decimal:2', 'ai_credits_usd' => 'decimal:2', 'features' => 'array',
            'sort_order' => 'integer', 'on_offer' => 'boolean', 'is_default' => 'boolean', 'trial_minutes' => 'integer',
            'max_file_minutes' => 'integer', 'uploads_per_day' => 'integer', 'engines' => 'array', 'retention_from' => 'datetime',
            'archived_at' => 'datetime', 'price_amount' => 'decimal:2',
        ];
    }
}
