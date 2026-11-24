<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The v2.0 console's needs-action lines that a person can close with × (the Library's "N calls waiting for a decision"
 * and Home's "Needs action"): a closed line stays hidden until a count rises above what it was when it was closed, or
 * a new kind appears. What was closed is kept per person and per workspace (`console_preferences.dismissed.<workspace
 * id>.<line>`), as each kind and its count, so it is the same on every browser, another person still sees their own
 * line and another workspace of the same person its own. The counts are always the
 * server's own, read when × is pressed (never what the page sent), and reading the rule writes nothing: only closing
 * and a rise (which forgets the closed line) are saved.
 */
final class V2DismissedBanners
{
    public const BANNERS = ['library', 'home'];

    /**
     * Whether the line, with these counts now, stays hidden. A rise above a closed count, or a new kind, shows it and
     * forgets what was closed (the only write here); a fall changes nothing.
     *
     * @param  array<string, int>  $counts  each kind and its count now (0 = not shown)
     */
    public static function hidden(?User $user, int $workspaceId, string $banner, array $counts): bool
    {
        $dismissed = self::stored($user, $workspaceId, $banner);
        if ($user === null || $dismissed === null) {
            return false;
        }
        foreach (self::shown($counts) as $kind => $count) {
            if (! isset($dismissed[$kind]) || $count > $dismissed[$kind]) {
                self::store($user, $workspaceId, $banner, null);

                return false;
            }
        }

        return true;
    }

    /**
     * Closes the line at the counts the server has now (what the line shows on a fresh draw), for each kind with any.
     *
     * @param  array<string, int>  $counts  each kind and its count now, from the server
     */
    public static function dismiss(User $user, int $workspaceId, string $banner, array $counts): void
    {
        if (! in_array($banner, self::BANNERS, true)) {
            return;
        }
        $shown = self::shown($counts);
        self::store($user, $workspaceId, $banner, $shown !== [] ? $shown : null);
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
    private static function stored(?User $user, int $workspaceId, string $banner): ?array
    {
        $saved = is_array($user?->console_preferences) ? ($user->console_preferences['dismissed'][(string) $workspaceId][$banner] ?? null) : null;
        if (! is_array($saved)) {
            return null;
        }
        $clean = array_filter($saved, fn (mixed $count, mixed $kind): bool => is_string($kind) && is_int($count) && $count > 0, ARRAY_FILTER_USE_BOTH);

        return $clean === [] ? null : $clean;
    }

    /**
     * Writes only this line of this workspace into `console_preferences`, read again under a lock of the person's row,
     * so a theme or a sort saved by another request at the same moment is never written over.
     *
     * @param  array<string, int>|null  $signature
     */
    private static function store(User $user, int $workspaceId, string $banner, ?array $signature): void
    {
        $saved = DB::transaction(function () use ($user, $workspaceId, $banner, $signature): mixed {
            $row = User::query()->whereKey($user->id)->lockForUpdate()->first(['id', 'console_preferences']);
            $preferences = is_array($row?->console_preferences) ? $row->console_preferences : [];
            $dismissed = is_array($preferences['dismissed'] ?? null) ? $preferences['dismissed'] : [];
            $workspace = is_array($dismissed[(string) $workspaceId] ?? null) ? $dismissed[(string) $workspaceId] : [];
            if ($signature === null) {
                unset($workspace[$banner]);
            } else {
                $workspace[$banner] = $signature;
            }
            if ($workspace === []) {
                unset($dismissed[(string) $workspaceId]);
            } else {
                $dismissed[(string) $workspaceId] = $workspace;
            }
            if ($dismissed === []) {
                unset($preferences['dismissed']);
            } else {
                $preferences['dismissed'] = $dismissed;
            }
            $preferences = $preferences === [] ? null : $preferences;
            User::query()->whereKey($user->id)->update(['console_preferences' => $preferences === null ? null : json_encode($preferences)]);

            return $preferences;
        });
        $user->console_preferences = $saved;
        $user->syncOriginalAttribute('console_preferences');
    }
}
