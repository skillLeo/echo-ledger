<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\Device;
use App\Models\User;
use Closure;

/**
 * Which workspace the running code acts for (docs/HANDOFF.md "B20 design" → Current workspace):
 * an explicit `runAs()` id, else the user or device that a guard has already authenticated (`hasUser()` only:
 * resolving a guard here could recurse into the token lookup). Webhooks, public share links, pairing, console
 * commands and queue workers have none and work on explicit ids.
 *
 * Since v1.5 also for whom: content is private to the person who made it (docs/API.md "Workspaces, roles and
 * privacy"), so `userId()` is the signed-in person (a device's owner), which the OwnerScope adds to every query.
 */
final class CurrentWorkspace
{
    /** A signed-in user without a current workspace sees nothing (no row has this id). */
    public const NONE = 0;

    private static ?int $forced = null;

    private static bool $bypassed = false;

    /** The person runAs() acts for; null with runAs() means nobody in particular (a public page of the workspace). */
    private static ?int $forcedUser = null;

    public static function id(): ?int
    {
        if (self::$bypassed) {
            return null;
        }
        if (self::$forced !== null) {
            return self::$forced;
        }
        if (! app()->bound('auth')) {
            return null;
        }
        $auth = app('auth');
        if (! $auth->hasUser()) {
            return null;
        }
        $user = $auth->user();

        return match (true) {
            $user instanceof Device => (int) $user->workspace_id,
            $user instanceof User => $user->current_workspace_id !== null ? (int) $user->current_workspace_id : self::NONE,
            default => null,
        };
    }

    /**
     * The person whose content the running code may see (since v1.5): runAs()'s person, else the signed-in user or the
     * device's owner. Null without one (webhooks, jobs, public pages): those work on explicit ids.
     */
    public static function userId(): ?int
    {
        if (self::$bypassed) {
            return null;
        }
        if (self::$forced !== null) {
            return self::$forcedUser;
        }
        if (! app()->bound('auth')) {
            return null;
        }
        $auth = app('auth');
        if (! $auth->hasUser()) {
            return null;
        }
        $user = $auth->user();

        return match (true) {
            $user instanceof Device => (int) $user->user_id,
            $user instanceof User => (int) $user->getKey(),
            default => null,
        };
    }

    /**
     * Runs the callback as the given workspace (console commands and jobs that act for one workspace), and as the
     * given person when there is one (since v1.5).
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function runAs(int $workspaceId, Closure $callback, ?int $userId = null): mixed
    {
        [$forced, $forcedUser, $bypassed] = [self::$forced, self::$forcedUser, self::$bypassed];
        [self::$forced, self::$forcedUser, self::$bypassed] = [$workspaceId, $userId, false];
        try {
            return $callback();
        } finally {
            [self::$forced, self::$forcedUser, self::$bypassed] = [$forced, $forcedUser, $bypassed];
        }
    }

    /**
     * Runs the callback with no current workspace, so the global scope adds nothing. Only for code that must see
     * every workspace on purpose (the super admin's workspaces list) and filters by explicit ids itself.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function bypass(Closure $callback): mixed
    {
        $bypassed = self::$bypassed;
        self::$bypassed = true;
        try {
            return $callback();
        } finally {
            self::$bypassed = $bypassed;
        }
    }
}
