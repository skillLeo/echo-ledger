<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Device;
use App\Models\Plan;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Workspaces and memberships (B20). Every change that removes someone from a workspace also moves their current
 * workspace and revokes their devices there, so nothing keeps acting for a workspace it no longer belongs to.
 */
final class Workspaces
{
    /** A new workspace on the Personal plan with the user as owner; it becomes their current one if they had none. */
    public function createFor(User $user, string $name = 'Personal'): Workspace
    {
        return DB::transaction(function () use ($user, $name): Workspace {
            $workspace = Workspace::query()->create(['name' => mb_substr($name, 0, 120), 'plan_id' => Plan::personal()->id]);
            $workspace->members()->attach($user->getKey(), ['role' => WorkspaceRole::Owner->value]);
            if ($user->current_workspace_id === null) {
                $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            }

            return $workspace;
        });
    }

    /** The user's current workspace, repaired to their first membership when it is missing or no longer theirs. */
    public function current(User $user): ?Workspace
    {
        $current = $user->current_workspace_id !== null
            ? $user->workspaces()->whereKey($user->current_workspace_id)->first()
            : null;
        if ($current instanceof Workspace) {
            return $current;
        }
        $first = $user->workspaces()->orderBy('workspaces.id')->first();
        $user->forceFill(['current_workspace_id' => $first?->id])->save();

        return $first;
    }

    public function switchTo(User $user, Workspace $workspace): void
    {
        if ($workspace->roleOf($user) === null) {
            throw new InvalidArgumentException('Not a member of this workspace.');
        }
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
    }

    public function roleOf(User $user, ?int $workspaceId = null): ?WorkspaceRole
    {
        $workspaceId ??= $user->current_workspace_id;
        if ($workspaceId === null) {
            return null;
        }
        $role = DB::table('workspace_user')->where('workspace_id', $workspaceId)->where('user_id', $user->getKey())->value('role');

        return is_string($role) ? WorkspaceRole::tryFrom($role) : null;
    }

    public function isOwner(User $user, ?int $workspaceId = null): bool
    {
        return $this->roleOf($user, $workspaceId) === WorkspaceRole::Owner;
    }

    public function addMember(Workspace $workspace, User $user, WorkspaceRole $role = WorkspaceRole::Member): void
    {
        if ($workspace->roleOf($user) !== null) {
            throw new InvalidArgumentException('Already a member.');
        }
        $workspace->members()->attach($user->getKey(), ['role' => $role->value]);
        if ($user->current_workspace_id === null) {
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        }
    }

    public function setRole(Workspace $workspace, User $user, WorkspaceRole $role): void
    {
        $current = $workspace->roleOf($user);
        if ($current === null) {
            throw new InvalidArgumentException('Not a member of this workspace.');
        }
        if ($current === WorkspaceRole::Owner && $role !== WorkspaceRole::Owner && $this->ownerCount($workspace) <= 1) {
            throw new InvalidArgumentException('A workspace needs at least one owner.');
        }
        $workspace->members()->updateExistingPivot($user->getKey(), ['role' => $role->value]);
    }

    public function removeMember(Workspace $workspace, User $user): void
    {
        $role = $workspace->roleOf($user);
        if ($role === null) {
            return;
        }
        if ($role === WorkspaceRole::Owner && $this->ownerCount($workspace) <= 1) {
            throw new InvalidArgumentException('A workspace needs at least one owner.');
        }
        DB::transaction(function () use ($workspace, $user): void {
            $workspace->members()->detach($user->getKey());
            // Their devices stop syncing into this workspace at once (tokens go with the device).
            Device::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('user_id', $user->getKey())
                ->get()->each(function (Device $device): void {
                    $device->tokens()->delete();
                    $device->delete();
                });
            if ((int) $user->current_workspace_id === $workspace->id) {
                $user->forceFill(['current_workspace_id' => null])->save();
                $this->current($user);
            }
        });
    }

    private function ownerCount(Workspace $workspace): int
    {
        return $workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->count();
    }
}
