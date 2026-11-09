<?php

declare(strict_types=1);

namespace App\Filament\Admin\Support;

use App\Models\Plan;
use App\Models\User;
use App\Services\Entitlements\Features;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;

/**
 * A person's own features and limits (since v1.6), on top of their workspace's plan: "The plan's" keeps what the plan
 * says, On or Off turns a feature on or off for them alone, a number or "none" sets a limit. Used on Plans and on a
 * customer's page.
 */
final class OverridesForm
{
    /** @return list<Grid> the feature switches and the limits */
    public static function fields(): array
    {
        return [
            Grid::make(['default' => 2])->schema(array_map(fn (string $f): Select => Select::make("override_feature_{$f}")->label(Plan::FEATURE_LABELS[$f])
                ->options(['plan' => 'The plan\'s', 'on' => 'On', 'off' => 'Off'])->default('plan')->selectablePlaceholder(false), Plan::FEATURES)),
            Grid::make(['default' => 2])->schema(array_map(fn (string $l): TextInput => TextInput::make("override_limit_{$l}")->label(Features::LIMIT_LABELS[$l])
                ->placeholder('The plan\'s')->helperText($l === 'storage_bytes' ? 'In GB. "none" for no limit.' : '"none" for no limit.')
                ->regex('/^(none|\d+(\.\d+)?)$/i'), array_keys(Features::LIMITS))),
        ];
    }

    /** @return array<string, string|null> */
    public static function toForm(?User $user): array
    {
        $raw = $user === null ? [] : ($user->entitlement_overrides ?? []);
        $form = [];
        foreach (Plan::FEATURES as $feature) {
            $value = $raw['features'][$feature] ?? null;
            $form["override_feature_{$feature}"] = $value === true ? 'on' : ($value === false ? 'off' : 'plan');
        }
        foreach (array_keys(Features::LIMITS) as $limit) {
            if (! is_array($raw['limits'] ?? null) || ! array_key_exists($limit, $raw['limits'])) {
                $form["override_limit_{$limit}"] = null;

                continue;
            }
            $value = $raw['limits'][$limit];
            $form["override_limit_{$limit}"] = $value === null ? 'none' : (string) ($limit === 'storage_bytes' ? round((int) $value / 1024 ** 3, 2) : $value);
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{features: array<string, bool>, limits: array<string, int|float|null>}|null
     */
    public static function fromForm(array $data): ?array
    {
        $features = [];
        foreach (Plan::FEATURES as $feature) {
            $choice = $data["override_feature_{$feature}"] ?? 'plan';
            if ($choice === 'on' || $choice === 'off') {
                $features[$feature] = $choice === 'on';
            }
        }
        $limits = [];
        foreach (array_keys(Features::LIMITS) as $limit) {
            $value = trim((string) ($data["override_limit_{$limit}"] ?? ''));
            if ($value === '') {
                continue;
            }
            $limits[$limit] = strtolower($value) === 'none' ? null : match ($limit) {
                'storage_bytes' => (int) round((float) $value * 1024 ** 3),
                'ai_usd_month' => (float) $value,
                default => (int) $value,
            };
        }

        return $features === [] && $limits === [] ? null : ['features' => $features, 'limits' => $limits];
    }

    /** "Live Translate on · Devices 5": what is set for the person, for the customer's page. */
    public static function summary(?User $user): string
    {
        $form = self::toForm($user);
        $parts = [];
        foreach (Plan::FEATURES as $feature) {
            $choice = $form["override_feature_{$feature}"];
            if ($choice !== 'plan') {
                $parts[] = Plan::FEATURE_LABELS[$feature].' '.$choice;
            }
        }
        foreach (array_keys(Features::LIMITS) as $limit) {
            $value = $form["override_limit_{$limit}"];
            if ($value !== null) {
                $parts[] = Features::LIMIT_LABELS[$limit].' '.($value === 'none' ? 'no limit' : $value.($limit === 'storage_bytes' ? ' GB' : ''));
            }
        }

        return $parts === [] ? 'None: everything as the plan says.' : implode(' · ', $parts);
    }
}
