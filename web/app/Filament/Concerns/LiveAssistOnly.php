<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\User;
use App\Services\InterfaceFlags;
use App\Services\Workspaces\ConsoleAccess;
use App\Services\Workspaces\CurrentWorkspace;

/**
 * A Live Assist page of the console (v1.9 L8): only for a person the `live_assist` flag is on for in this workspace,
 * like the device routes; for anyone else it is not there (403, and no menu item). Each person sees only their own
 * preps and sessions, whatever their role; the small read-only console reads them and changes nothing.
 */
trait LiveAssistOnly
{
    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && ConsoleAccess::open()
            && app(InterfaceFlags::class)->active(InterfaceFlags::LIVE_ASSIST, $user, CurrentWorkspace::id());
    }

    /** Whether this person may change things here (not in the small read-only console). */
    protected static function canChange(): bool
    {
        return ! ConsoleAccess::readOnly();
    }

    protected static function personId(): int
    {
        return (int) auth()->id();
    }
}
