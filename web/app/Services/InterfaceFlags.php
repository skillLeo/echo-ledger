<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InterfaceFlag;
use App\Models\User;
use App\Support\FlagScope;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;

/**
 * The interface flags (since 2.0.0-a, docs/API.md "Interface flags"): the redesigned screens and Live Assist, off by
 * default. The super admin turns one on for everyone, for a share of accounts (the same accounts every time), for a
 * workspace or for a person (Admin → Interface flags). A person's "Switch back to classic" turns every ui_v2.* flag off
 * for them; live_assist is not a screen and stays as it is. Decided with Laravel Pennant (in memory, per request); the
 * rules are the interface_flags rows. A row for a flag not listed here is ignored.
 */
final class InterfaceFlags
{
    /** The redesigned screens, in the contract's order. */
    public const UI_V2 = [
        'ui_v2.shell', 'ui_v2.home', 'ui_v2.library', 'ui_v2.recording_controls', 'ui_v2.after_call', 'ui_v2.live',
        'ui_v2.ask', 'ui_v2.settings', 'ui_v2.setup_health', 'ui_v2.admin', 'ui_v2.website',
    ];

    public const LIVE_ASSIST = 'live_assist';

    /** What each flag turns on, for the admin page. */
    public const LABELS = [
        'ui_v2.shell' => 'New frame: navigation, header, search',
        'ui_v2.home' => 'New Home',
        'ui_v2.library' => 'New Library',
        'ui_v2.recording_controls' => 'New recording controls',
        'ui_v2.after_call' => 'New after-call screen',
        'ui_v2.live' => 'New live captions and translate screens',
        'ui_v2.ask' => 'New Ask',
        'ui_v2.settings' => 'New Settings',
        'ui_v2.setup_health' => 'New setup and health checks',
        'ui_v2.admin' => 'New admin panel',
        'ui_v2.website' => 'New website',
        'live_assist' => 'Live Assist',
    ];

    /** @var array<string, InterfaceFlag>|null */
    private ?array $rules = null;

    /** @return list<string> every flag, in the contract's order */
    public static function names(): array
    {
        return [...self::UI_V2, self::LIVE_ASSIST];
    }

    /** Defines every flag with Pennant; called once at boot. */
    public static function define(): void
    {
        foreach (self::names() as $name) {
            Feature::define($name, fn (FlagScope $scope): bool => app(self::class)->decide($name, $scope));
        }
    }

    /**
     * Every flag's answer for [$user] in [$workspaceId] (their current workspace when null), as `ui_flags` gives it.
     *
     * @return array<string, bool>
     */
    public function for(User $user, ?int $workspaceId = null): array
    {
        $scope = self::scope($user, $workspaceId);
        $answers = [];
        foreach (self::names() as $name) {
            $answers[$name] = Feature::for($scope)->active($name);
        }

        return $answers;
    }

    public function active(string $flag, User $user, ?int $workspaceId = null): bool
    {
        return in_array($flag, self::names(), true) && Feature::for(self::scope($user, $workspaceId))->active($flag);
    }

    /** Whether any ui_v2.* flag is on for [$user], classic aside: the "Switch back to classic" shows only then. */
    public function anyUiV2(User $user, ?int $workspaceId = null): bool
    {
        $scope = new FlagScope((int) $user->id, $workspaceId ?? $user->current_workspace_id, false);
        foreach (self::UI_V2 as $name) {
            if (Feature::for($scope)->active($name)) {
                return true;
            }
        }

        return false;
    }

    /** The rule of one flag, for [$scope]. */
    public function decide(string $flag, FlagScope $scope): bool
    {
        if ($scope->classic && in_array($flag, self::UI_V2, true)) {
            return false;
        }
        $rule = $this->rules()[$flag] ?? null;
        if ($rule === null) {
            return false;
        }

        return $rule->everyone
            || in_array($scope->userId, array_map('intval', $rule->user_ids ?? []), true)
            || ($scope->workspaceId !== null && in_array($scope->workspaceId, array_map('intval', $rule->workspace_ids ?? []), true))
            || self::bucket($flag, $scope->userId) < max(0, min(100, $rule->percent));
    }

    /** The account's place, 0–99, for one flag: the same every time, and not the same accounts for every flag. */
    public static function bucket(string $flag, int $userId): int
    {
        return (int) (crc32($flag.':'.$userId) % 100);
    }

    /**
     * Saves who [$flag] is on for; an unknown flag is ignored.
     *
     * @param  array<int|string>  $workspaceIds
     * @param  array<int|string>  $userIds
     */
    public function set(string $flag, bool $everyone, int $percent, array $workspaceIds, array $userIds): void
    {
        if (! in_array($flag, self::names(), true)) {
            return;
        }
        InterfaceFlag::query()->updateOrCreate(['flag' => $flag], [
            'everyone' => $everyone,
            'percent' => max(0, min(100, $percent)),
            'workspace_ids' => array_values(array_unique(array_map('intval', $workspaceIds))),
            'user_ids' => array_values(array_unique(array_map('intval', $userIds))),
        ]);
        $this->forget();
    }

    /** Sets [$user]'s "Switch back to classic". */
    public function setClassic(User $user, bool $classic): void
    {
        $user->forceFill(['ui_classic' => $classic])->save();
        $this->forget();
    }

    /** @return array<string, InterfaceFlag> the saved rules, by flag */
    public function rules(): array
    {
        if ($this->rules === null) {
            $this->rules = Schema::hasTable('interface_flags')
                ? InterfaceFlag::query()->whereIn('flag', self::names())->get()->keyBy('flag')->all()
                : [];
        }

        return $this->rules;
    }

    /** Drops what this request already decided, after a change. */
    public function forget(): void
    {
        $this->rules = null;
        Feature::purge();
        Feature::flushCache();
    }

    private static function scope(User $user, ?int $workspaceId): FlagScope
    {
        return new FlagScope((int) $user->id, $workspaceId ?? $user->current_workspace_id, (bool) $user->ui_classic);
    }
}
