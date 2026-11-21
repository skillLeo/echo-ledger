<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;

/**
 * The v2.0 console's needs-action lines that a person can close with × (the Library's "N calls waiting for a decision"
 * and Home's "Needs action"), the same rule as the desktop's Library banner: a closed line stays hidden until what it
 * says changes, a count going up or a new kind appearing. When a count falls it stays hidden and keeps the lower number,
 * so a later rise shows it again. What was closed is kept per person (`console_preferences.dismissed`), as each kind
 * and its count, so it is the same on every browser and another person still sees their own line.
 */
final class DismissedBanners
{
    public const BANNERS = ['library', 'home'];

    /**
     * Whether the line, with these counts now, stays hidden; it also keeps what was closed up to date (a fall keeps the
     * lower counts, a rise or a new kind forgets it).
     *
     * @param  array<string, int>  $counts  each kind and its count now (0 = not shown)
     */
    public static function hidden(?User $user, string $banner, array $counts): bool
    {
        $dismissed = self::stored($user, $banner);
        if ($user === null || $dismissed === null) {
            return false;
        }
        $shown = self::shown($counts);
        $quiet = true;
        foreach ($shown as $kind => $count) {
            if (! isset($dismissed[$kind]) || $count > $dismissed[$kind]) {
                $quiet = false;
            }
        }
        $lower = [];
        foreach ($shown as $kind => $count) {
            if (isset($dismissed[$kind])) {
                $lower[$kind] = min($dismissed[$kind], $count);
            }
        }
        $next = $quiet && $lower !== [] ? $lower : null;
        if ($next !== $dismissed) {
            self::store($user, $banner, $next);
        }

        return $quiet;
    }

    /**
     * Closes the line as it was drawn: each kind the person saw, at the count they saw (never above the count now, so a
     * rise since the page was drawn still shows), and nothing a kind that appeared since.
     *
     * @param  array<array-key, mixed>  $seen  what the line said when × was pressed
     * @param  array<string, int>  $counts  each kind and its count now
     */
    public static function dismiss(User $user, string $banner, array $seen, array $counts): void
    {
        if (! in_array($banner, self::BANNERS, true)) {
            return;
        }
        $signature = [];
        foreach (self::shown($counts) as $kind => $count) {
            if (isset($seen[$kind]) && is_numeric($seen[$kind])) {
                $signature[$kind] = max(0, min($count, (int) $seen[$kind]));
            }
        }
        self::store($user, $banner, array_filter($signature) !== [] ? array_filter($signature) : null);
    }

    /**
     * What a line says: each kind it shows and its count.
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    public static function shown(array $counts): array
    {
        return array_filter(array_map('intval', $counts), fn (int $count): bool => $count > 0);
    }

    /** @return array<string, int>|null */
    private static function stored(?User $user, string $banner): ?array
    {
        $saved = is_array($user?->console_preferences) ? ($user->console_preferences['dismissed'][$banner] ?? null) : null;
        if (! is_array($saved)) {
            return null;
        }
        $clean = array_filter($saved, fn (mixed $count, mixed $kind): bool => is_string($kind) && is_int($count) && $count > 0, ARRAY_FILTER_USE_BOTH);

        return $clean === [] ? null : $clean;
    }

    /** @param  array<string, int>|null  $signature */
    private static function store(User $user, string $banner, ?array $signature): void
    {
        $preferences = is_array($user->console_preferences) ? $user->console_preferences : [];
        $dismissed = is_array($preferences['dismissed'] ?? null) ? $preferences['dismissed'] : [];
        if ($signature === null) {
            unset($dismissed[$banner]);
        } else {
            $dismissed[$banner] = $signature;
        }
        if ($dismissed === []) {
            unset($preferences['dismissed']);
        } else {
            $preferences['dismissed'] = $dismissed;
        }
        $user->forceFill(['console_preferences' => $preferences === [] ? null : $preferences])->save();
    }
}
