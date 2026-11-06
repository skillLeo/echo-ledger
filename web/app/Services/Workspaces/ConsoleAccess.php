<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Models\User;
use App\Services\SettingsRepository;

/**
 * How much of the console a person gets (since v1.5): the super admin every page (still only their own content),
 * owners and admins their workspace's pages, members and viewers a small read-only console (their own library,
 * Shared with me, the profile with its devices, the Workspace page to switch or leave, and a member's own usage)
 * while Settings → "Members can use the console" is on, else only a page that points to the apps.
 */
final class ConsoleAccess
{
    public const SETTING = 'console.members';

    public const FULL = 'full';

    public const SMALL = 'small';

    public const CLOSED = 'closed';

    public function __construct(private readonly Workspaces $workspaces, private readonly SettingsRepository $settings) {}

    public function level(?User $user = null): string
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return self::CLOSED;
        }
        if ($user->is_super_admin || $this->workspaces->roleOf($user, (int) CurrentWorkspace::id())?->manages() === true) {
            return self::FULL;
        }

        return $this->membersMayUse() ? self::SMALL : self::CLOSED;
    }

    public function membersMayUse(): bool
    {
        return (bool) $this->settings->get(self::SETTING, true);
    }

    /** The whole console: pages of the workspace, editing, uploading. */
    public static function full(): bool
    {
        return app(self::class)->level() === self::FULL;
    }

    /** The small console's pages (the library to read, Shared with me, the Workspace page, a member's usage). */
    public static function open(): bool
    {
        return app(self::class)->level() !== self::CLOSED;
    }

    /** True for the small console: things open there are read, not changed. */
    public static function readOnly(): bool
    {
        return app(self::class)->level() !== self::FULL;
    }
}
