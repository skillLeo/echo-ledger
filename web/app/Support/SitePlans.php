<?php

declare(strict_types=1);

namespace App\Support;

use App\Filament\Support\UpgradeFlow;
use App\Models\Plan;
use App\Services\Entitlements\Features;
use Illuminate\Support\Number;

/**
 * The website's Pricing (v2.0): each plan on offer as the console's plan comparison words it, its name and price, what
 * it adds to the plan before it (UpgradeFlow::highlights) and its limits. Nothing here is written for the site alone.
 */
final class SitePlans
{
    /**
     * @param  list<Plan>  $plans  cheapest first
     * @return list<array{key: string, name: string, price: string|null, description: string|null, everything: string|null, lines: list<string>, limits: list<string>}>
     */
    public static function cards(array $plans): array
    {
        $cards = [];
        $below = null;
        foreach ($plans as $plan) {
            $price = $plan->priceText();
            $description = self::description($plan);
            $lines = $below === null ? self::freeLines($plan) : UpgradeFlow::highlights($plan, $below);
            // Said once: a price that is the plan's name ("Free"), the description's own "Everything in …", and the
            // minutes when a line already gives them.
            $minutesSaid = collect($lines)->contains(fn (string $line): bool => str_contains($line, 'transcription minutes'));
            $cards[] = [
                'key' => $plan->key,
                'name' => $plan->name,
                'price' => mb_strtolower(trim($price)) === mb_strtolower(trim($plan->name)) ? null : $price,
                'description' => $description,
                'everything' => $below !== null && $below->key !== Plan::FREE && ! str_starts_with((string) $description, 'Everything in') ? $below->name : null,
                'lines' => $lines,
                'limits' => self::limits($plan, ! $minutesSaid),
            ];
            $below = $plan;
        }

        return $cards;
    }

    /**
     * The first plan's own features: what is in it, in the features registry's words.
     *
     * @return list<string>
     */
    private static function freeLines(Plan $plan): array
    {
        return array_values(array_map(fn (string $f): string => Features::all()[$f]['name'],
            array_filter(array_keys(Features::all()), fn (string $f): bool => $plan->allows($f))));
    }

    /**
     * The plan's description from Admin › Plans, free text on a public page: left out when it names a provider, model
     * or vendor (the plain-words rule), except the Free plan's own Groq key that people make themselves.
     */
    public static function description(Plan $plan): ?string
    {
        if (blank($plan->description)) {
            return null;
        }
        $text = (string) $plan->description;

        return ProviderText::names((string) preg_replace(['/\bGroq key\b/', '/\bconsole\.groq\.com\b/'], '', $text)) ? null : $text;
    }

    /** @return list<string> the plan's limits, as the console's plan page states them */
    private static function limits(Plan $plan, bool $withMinutes = true): array
    {
        $minutes = $withMinutes ? $plan->limit('monthly_minutes') : 0;
        $storage = $plan->limit('storage_bytes');
        $devices = $plan->limit('devices');
        $days = $plan->limit('retention_days');

        return array_values(array_filter([
            $minutes === null ? 'Transcription minutes: no limit' : ($minutes > 0 ? number_format((int) $minutes).' transcription minutes a month' : null),
            $storage === null ? 'Storage: no limit' : Number::fileSize((int) $storage).' of storage',
            $devices === null ? 'Devices: no limit' : number_format((int) $devices).' '.((int) $devices === 1 ? 'device' : 'devices'),
            $days === null ? 'Recordings kept for good' : 'Recordings kept '.number_format((int) $days).' days',
        ]));
    }
}
