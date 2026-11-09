<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\Workspaces\CurrentWorkspace;
use Filament\Actions\Action;
use Illuminate\Support\HtmlString;

/**
 * Plan-locked features in the console (since v1.6; since v1.7 without colours, capitals or sparkles): the tag of the
 * lowest plan that has the feature ("Pro", the plan's name in the neutral tag style) and a link to the upgrade flow
 * with that plan chosen, or to the request while one is open.
 */
final class PlanLock
{
    public static function person(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function allows(string $feature): bool
    {
        $user = self::person();

        return $user !== null && app(Entitlements::class)->allows((int) CurrentWorkspace::id(), $user->id, $feature);
    }

    /** The tag of the lowest plan that has [$feature], or null when the person has it (or no plan on offer does). */
    public static function badge(string $feature): ?HtmlString
    {
        return self::allows($feature) ? null : self::lowestBadge($feature);
    }

    /** The tag of the lowest plan that has [$feature], whoever has it (a recording's owner's plan decides there). */
    public static function lowestBadge(string $feature): ?HtmlString
    {
        $plan = app(Entitlements::class)->lowestPlan($feature);

        return $plan === null ? null : self::badgeFor($plan);
    }

    /** A plan's tag: its name, in the neutral tag style. */
    public static function badgeFor(Plan $plan, bool $locked = true): HtmlString
    {
        return new HtmlString('<span class="el-tag el-plan-badge">'.e($plan->name).'</span>');
    }

    /** The name of the lowest plan that has [$feature] when the person lacks it ("Pro"), else null. */
    public static function tagFor(string $feature): ?string
    {
        return self::allows($feature) ? null : app(Entitlements::class)->lowestPlan($feature)?->name;
    }

    /** "Show as… Pro": the action's label followed by the tag of the plan that has the feature. */
    public static function label(string $label, string $feature): HtmlString|string
    {
        $badge = self::badge($feature);

        return $badge === null ? $label : new HtmlString(e($label).' '.$badge->toHtml());
    }

    /**
     * A locked feature's button: opens the upgrade flow with the lowest plan that has [$feature] chosen (Compare plans
     * without a feature), or the request still open.
     */
    public static function sheet(string $name, ?string $feature = null, ?string $label = null): Action
    {
        $label ??= $feature !== null ? Features::name($feature) : 'Compare plans';

        return Action::make($name)
            ->label(fn (): HtmlString|string => $feature !== null ? self::label($label, $feature) : $label)
            ->color('gray')
            ->url(fn (): string => self::url($feature))
            // Mounted from code or a view (a locked switch, "See plans"): the same address.
            ->action(fn ($livewire) => $livewire->redirect(self::url($feature)));
    }

    /** Where a locked feature leads: the flow on its plan, or the request still open. */
    public static function url(?string $feature): string
    {
        $plan = $feature !== null ? app(Entitlements::class)->lowestPlan($feature)?->key : null;

        return UpgradeFlow::entryUrl(SettingsContext::current(), $plan, $feature);
    }

    /**
     * Every feature of the registry in its order: its name and line, whether the person's plan has it, and otherwise
     * the tag of the lowest plan that has it.
     *
     * @return list<array{key: string, name: string, description: string, has: bool, badge: HtmlString|null}>
     */
    public static function features(): array
    {
        $user = self::person();
        $has = $user === null ? [] : app(Entitlements::class)->features((int) CurrentWorkspace::id(), $user->id);
        $rows = [];
        foreach (Features::ALL as $key => $feature) {
            $on = $has[$key] ?? false;
            $plan = $on ? null : app(Entitlements::class)->lowestPlan($key);
            $rows[] = ['key' => $key, 'name' => $feature['name'], 'description' => $feature['description'], 'has' => $on, 'badge' => $plan === null ? null : self::badgeFor($plan)];
        }

        return $rows;
    }
}
