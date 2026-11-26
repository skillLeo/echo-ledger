<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\SavedView;
use App\Models\User;
use App\Services\Workspaces\CurrentWorkspace;

/**
 * Saved views (contract 2.0.0-p) are their maker's alone, in the workspace they were made in: nobody else reads,
 * renames, changes or deletes them, an owner or the super admin included. Any role may keep views (a viewer's change
 * nothing of the workspace).
 */
final class SavedViewPolicy
{
    public function view(User $user, SavedView $view): bool
    {
        return self::mine($user, $view);
    }

    public function update(User $user, SavedView $view): bool
    {
        return self::mine($user, $view);
    }

    public function delete(User $user, SavedView $view): bool
    {
        return self::mine($user, $view);
    }

    private static function mine(User $user, SavedView $view): bool
    {
        $workspace = CurrentWorkspace::id();

        return (int) $view->user_id === (int) $user->id && $workspace !== null && (int) $view->workspace_id === $workspace;
    }
}
