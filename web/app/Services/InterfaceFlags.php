<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\InterfaceFlag;
use App\Models\User;
use App\Support\FlagScope;
use Illuminate\Support\Facades\Schema;
use Laravel\Pennant\Feature;

/**
 * The interface flags (since 2.0.0-a, docs/API.md "Interface flags" and, since 2.0.0-g, "The design switch"): the
 * redesigned screens and Live Assist, off by default. Since 2.0.0-g each flag has an availability (off: the admin's
 * lists only; opt_in: whoever chose the new design, and the lists; everyone) and each person a design choice (classic,
 * v2 or none), decided in decide()'s order. The super admin turns one on for everyone, for a share of accounts (the same accounts every time), for a
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

    /** The nine console flags: "the new design" a person can try, and what `ui_design.current` reads. */
    public const CONSOLE = [
        'ui_v2.shell', 'ui_v2.home', 'ui_v2.library', 'ui_v2.recording_controls', 'ui_v2.after_call', 'ui_v2.live',
        'ui_v2.ask', 'ui_v2.settings', 'ui_v2.setup_health',
    ];

    /** Since 2.0.0-g: who a flag is open to. live_assist has no opt_in. */
    public const AVAILABILITIES = ['off', 'opt_in', 'everyone'];

    /** A person's own design choice (null: never chose). */
    public const DESIGNS = ['classic', 'v2'];

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
        $choice = self::choiceOf($user);
        $scope = new FlagScope((int) $user->id, $workspaceId ?? $user->current_workspace_id, false, $choice === 'classic' ? null : $choice);
        foreach (self::UI_V2 as $name) {
            if (Feature::for($scope)->active($name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The rule of one flag, for [$scope] (contract 2.0.0-g), the first that applies: the person chose classic → off;
     * on for everyone → on; the person chose v2 and the flag is opt-in → on; the admin's lists → on; else off.
     */
    public function decide(string $flag, FlagScope $scope): bool
    {
        $design = in_array($flag, self::UI_V2, true);
        if ($design && $scope->classic) {
            return false;
        }
        $rule = $this->rules()[$flag] ?? null;
        if ($rule === null) {
            return false;
        }
        $availability = self::availabilityOf($rule);
        if ($availability === 'everyone') {
            return true;
        }
        if ($design && $availability === 'opt_in' && $scope->design === 'v2') {
            return true;
        }

        return in_array($scope->userId, array_map('intval', $rule->user_ids ?? []), true)
            || ($scope->workspaceId !== null && in_array($scope->workspaceId, array_map('intval', $rule->workspace_ids ?? []), true))
            || self::bucket($flag, $scope->userId) < max(0, min(100, $rule->percent));
    }

    /**
     * A row's availability. Null (a row not filled by the 2.0.0-g migration, or made without it) reads as before
     * 2.0.0-g: everyone when its "everyone" is on, else off. live_assist has no opt_in.
     */
    public static function availabilityOf(InterfaceFlag $rule): string
    {
        $availability = in_array($rule->availability ?? null, self::AVAILABILITIES, true) ? $rule->availability : ($rule->everyone ? 'everyone' : 'off');
        if ($rule->everyone) {
            return 'everyone';
        }

        return $availability === 'opt_in' && $rule->flag === self::LIVE_ASSIST ? 'off' : $availability;
    }

    /** The account's place, 0–99, for one flag: the same every time, and not the same accounts for every flag. */
    public static function bucket(string $flag, int $userId): int
    {
        return (int) (crc32($flag.':'.$userId) % 100);
    }

    /**
     * Saves who [$flag] is on for; an unknown flag is ignored. [$availability] null keeps the flag's own (2.0.0-a callers):
     * `$everyone` true is "everyone", false leaves an opt_in as it is.
     *
     * @param  array<int|string>  $workspaceIds
     * @param  array<int|string>  $userIds
     */
    public function set(string $flag, bool $everyone, int $percent, array $workspaceIds, array $userIds, ?string $availability = null): void
    {
        if (! in_array($flag, self::names(), true)) {
            return;
        }
        if ($availability === null || ! in_array($availability, self::AVAILABILITIES, true)) {
            $current = isset($this->rules()[$flag]) ? self::availabilityOf($this->rules()[$flag]) : 'off';
            $availability = $everyone ? 'everyone' : ($current === 'everyone' ? 'off' : $current);
        }
        if ($flag === self::LIVE_ASSIST && $availability === 'opt_in') {
            $availability = 'off';
        }
        InterfaceFlag::query()->updateOrCreate(['flag' => $flag], [
            'everyone' => $availability === 'everyone',
            'availability' => $availability,
            'percent' => max(0, min(100, $percent)),
            'workspace_ids' => array_values(array_unique(array_map('intval', $workspaceIds))),
            'user_ids' => array_values(array_unique(array_map('intval', $userIds))),
        ]);
        $this->forget();
    }

    /** Sets [$user]'s "Switch back to classic" (2.0.0-a): true is the design "classic", false no choice. */
    public function setClassic(User $user, bool $classic): void
    {
        $this->setDesign($user, $classic ? 'classic' : null);
    }

    /** Sets [$user]'s own design choice (since 2.0.0-g); ui_classic is kept equal to "classic". */
    public function setDesign(User $user, ?string $design): void
    {
        $design = in_array($design, self::DESIGNS, true) ? $design : null;
        $user->forceFill(['ui_design' => $design, 'ui_classic' => $design === 'classic'])->save();
        $this->forget();
    }

    /**
     * `ui_design` (contract 2.0.0-g): the person's choice, the design they see now, and which of the two switches to show.
     *
     * @return array{choice: string|null, current: string, can_try: bool, can_switch_back: bool}
     */
    public function design(User $user, ?int $workspaceId = null): array
    {
        $workspaceId ??= $user->current_workspace_id;
        $current = $this->anyConsole(self::scope($user, $workspaceId)) ? 'v2' : 'classic';
        $wouldTry = $this->anyConsole(new FlagScope((int) $user->id, $workspaceId, false, 'v2'));

        return [
            'choice' => self::choiceOf($user),
            'current' => $current,
            'can_try' => $current === 'classic' && $wouldTry,
            'can_switch_back' => $current === 'v2',
        ];
    }

    private function anyConsole(FlagScope $scope): bool
    {
        foreach (self::CONSOLE as $name) {
            if (Feature::for($scope)->active($name)) {
                return true;
            }
        }

        return false;
    }

    private static function choiceOf(User $user): ?string
    {
        $design = $user->ui_design ?? null;
        if (in_array($design, self::DESIGNS, true)) {
            return $design;
        }

        return $user->ui_classic ? 'classic' : null;
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

    /** Whether [$flag] is on for everyone (the public website, whose visitors are not signed in, follows only this). */
    public function forEveryone(string $flag): bool
    {
        return (bool) ($this->rules()[$flag]->everyone ?? false);
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
        $choice = self::choiceOf($user);

        return new FlagScope((int) $user->id, $workspaceId ?? $user->current_workspace_id, $choice === 'classic', $choice);
    }
}
