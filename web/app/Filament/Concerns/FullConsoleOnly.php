<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Services\Workspaces\ConsoleAccess;

/**
 * A console page or resource of the whole console (since v1.5): owners, admins and the super admin. Members and
 * viewers have the small read-only console and get 403 here.
 */
trait FullConsoleOnly
{
    public static function canAccess(): bool
    {
        return ConsoleAccess::full();
    }
}
