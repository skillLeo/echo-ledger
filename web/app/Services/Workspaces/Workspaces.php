<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Models\CaptureSession;
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
    /** @var array<string, WorkspaceRole|null> Roles looked up in this request, by "workspace:user". */
    private array $roles = [];

    /** A new workspace on the Personal plan with the user as owner; it becomes their current one if they had none. */
    public function createFor(User $user, string $name = 'Personal'): Workspace
    {
        return DB::transaction(function () use ($user, $name): Workspace {
            $workspace = Workspace::query()->create(['name' => mb_substr($name, 0, 120), 'plan_id' => Plan::personal()->id]);
            $workspace->members()->attach($user->getKey(), ['role' => WorkspaceRole::Owner->value]);
            $this->roles = [];
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
        // C9: lists ask once per row (delete permission); one lookup per request is enough.
        $key = $workspaceId.':'.$user->getKey();
        if (! array_key_exists($key, $this->roles)) {
            $role = DB::table('workspace_user')->where('workspace_id', $workspaceId)->where('user_id', $user->getKey())->value('role');
            $this->roles[$key] = is_string($role) ? WorkspaceRole::tryFrom($role) : null;
        }

        return $this->roles[$key];
    }

    public function isOwner(User $user, ?int $workspaceId = null): bool
    {
        return $this->roleOf($user, $workspaceId) === WorkspaceRole::Owner;
    }

    /**
     * Roles (docs/HANDOFF.md "B20 design"): a member deletes, restores and purges only recordings they made; an
     * owner any recording of the workspace. Never true across workspaces.
     */
    public function canDeleteSession(User $actor, CaptureSession $session): bool
    {
        $role = $this->roleOf($actor, $session->workspace_id);

        return $role === WorkspaceRole::Owner || ($role !== null && (int) $session->user_id === (int) $actor->getKey());
    }

    /** API form of canDeleteSession(): `403 not_allowed` (the recording is visible to them, so this leaks nothing). */
    public function assertCanDeleteSession(User $actor, CaptureSession $session): void
    {
        if (! $this->canDeleteSession($actor, $session)) {
            throw new ApiError(403, 'not_allowed', 'Only the workspace owner can delete recordings made by someone else.');
        }
    }

    /** A member renames and revokes only their own devices; an owner any device of the workspace. */
    public function canManageDevice(User $actor, Device $device): bool
    {
        $role = $this->roleOf($actor, $device->workspace_id);

        return $role === WorkspaceRole::Owner || ($role !== null && (int) $device->user_id === (int) $actor->getKey());
    }

    public function addMember(Workspace $workspace, User $user, WorkspaceRole $role = WorkspaceRole::Member): void
    {
        if ($workspace->roleOf($user) !== null) {
            throw new InvalidArgumentException('Already a member.');
        }
        $workspace->members()->attach($user->getKey(), ['role' => $role->value]);
        $this->roles = [];
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
        $this->roles = [];
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
            $this->roles = [];
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
