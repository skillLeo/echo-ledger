<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
 */
class Plan extends Model
{
    public const PERSONAL = 'personal';

    /** The plan new sign-ups get (since v1.5). */
    public const FREE = 'free';

    /** Features a plan can leave out. Transcript views, suggested fixes and screen descriptions never are. */
    public const FEATURES = ['live_translate', 'screen_moments', 'ai_notes', 'connector'];

    /** What the features are called in messages and on the Plans page. */
    public const FEATURE_LABELS = [
        'live_translate' => 'Live Translate',
        'screen_moments' => 'Screen moments',
        'ai_notes' => 'AI notes (project notes, memory and Ask)',
        'connector' => 'Claude and ChatGPT connector',
    ];

    /** Personal: every feature and no limits (every workspace made before v1.5 is on it). */
    public const PERSONAL_DEFAULTS = ['name' => 'Personal', 'monthly_minutes' => null, 'storage_bytes' => null, 'max_devices' => null,
        'retention_days' => null, 'seats' => null, 'ai_credits_usd' => null, 'price_usd' => 0];

    /**
     * Free: placeholders until the prices are decided (300 minutes a month, 5 GB, 30 days, one seat, US$1 of AI work,
     * no Live Translate and no AI notes).
     */
    public const FREE_DEFAULTS = ['name' => 'Free', 'monthly_minutes' => 300, 'storage_bytes' => 5 * 1024 ** 3, 'max_devices' => 3,
        'retention_days' => 30, 'seats' => 1, 'ai_credits_usd' => 1, 'price_usd' => 0,
        'features' => ['live_translate' => false, 'screen_moments' => true, 'ai_notes' => false, 'connector' => true]];

    protected $fillable = ['key', 'name', 'price_usd', 'monthly_minutes', 'storage_bytes', 'max_devices', 'retention_days', 'seats', 'ai_credits_usd', 'features'];

    public static function personal(): self
    {
        return self::query()->firstOrCreate(['key' => self::PERSONAL], [...self::PERSONAL_DEFAULTS, 'features' => array_fill_keys(self::FEATURES, true)]);
    }

    public static function free(): self
    {
        return self::query()->firstOrCreate(['key' => self::FREE], self::FREE_DEFAULTS);
    }

    /** Whether the plan includes the feature; a plan that does not name it (or names none) includes it. */
    public function allows(string $feature): bool
    {
        $features = $this->features;

        return ! is_array($features) || ($features[$feature] ?? true) !== false;
    }

    /** @return array<string, bool> every feature, on or off */
    public function featureMap(): array
    {
        return array_combine(self::FEATURES, array_map(fn (string $f): bool => $this->allows($f), self::FEATURES));
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
        ];
    }
}
