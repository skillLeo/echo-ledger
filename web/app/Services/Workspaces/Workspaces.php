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
 * Workspaces and memberships (B20; admin and viewer, transfer and leave since v1.5). Every change that removes someone
 * from a workspace also moves their current workspace and revokes their devices there, so nothing keeps acting for a
 * workspace it no longer belongs to. What a removed person made stays in the workspace, theirs and seen by nobody
 * else (it is theirs again if they come back, and goes with their account).
 */
final class Workspaces
{
    /** @var array<string, WorkspaceRole|null> Roles looked up in this request, by "workspace:user". */
    private array $roles = [];

    /**
     * A new workspace with the user as owner, on the given plan (Personal when none: since v1.5 sign-ups pass Free);
     * it becomes their current one if they had none.
     */
    public function createFor(User $user, string $name = 'Personal', ?Plan $plan = null): Workspace
    {
        return DB::transaction(function () use ($user, $name, $plan): Workspace {
            $workspace = Workspace::query()->create(['name' => mb_substr($name, 0, 120), 'plan_id' => ($plan ?? Plan::personal())->id]);
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
     * Since v1.5 a recording is its maker's alone (docs/API.md "Workspaces, roles and privacy"): only they delete,
     * restore and purge it, while they belong to its workspace, owners included. Never true across workspaces.
     */
    public function canDeleteSession(User $actor, CaptureSession $session): bool
    {
        return $this->roleOf($actor, $session->workspace_id)?->records() === true && (int) $session->user_id === (int) $actor->getKey();
    }

    /**
     * API form of canDeleteSession(): `403 not_allowed`. Other people's recordings answer 404 before this; it is left
     * for a viewer, who has none, and for someone no longer in the workspace.
     */
    public function assertCanDeleteSession(User $actor, CaptureSession $session): void
    {
        if (! $this->canDeleteSession($actor, $session)) {
            throw new ApiError(403, 'not_allowed', 'Only the person who made a recording can delete it.');
        }
    }

    /** Everyone renames and revokes their own devices; an owner or admin any device of the workspace. */
    public function canManageDevice(User $actor, Device $device): bool
    {
        $role = $this->roleOf($actor, $device->workspace_id);

        return $role !== null && ($role->manages() || (int) $device->user_id === (int) $actor->getKey());
    }

    /** `403 not_allowed` for a viewer: they open what is shared with them, they do not record (since v1.5). */
    public function assertCanRecord(User $actor, int $workspaceId): void
    {
        if ($this->roleOf($actor, $workspaceId) === WorkspaceRole::Viewer) {
            $name = (string) Workspace::query()->whereKey($workspaceId)->value('name');
            throw new ApiError(403, 'not_allowed', "You are a viewer in {$name}: you can open what is shared with you, not record.");
        }
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

    /**
     * Hands the workspace to [$to] (since v1.5): they become its owner and [$from], the owner, an admin. Only a member
     * or an admin can take it over (a viewer does not record).
     */
    public function transfer(Workspace $workspace, User $from, User $to): void
    {
        if ($workspace->roleOf($from) !== WorkspaceRole::Owner) {
            throw new InvalidArgumentException('Only the owner can hand the workspace over.');
        }
        if ((int) $from->getKey() === (int) $to->getKey() || ! in_array($workspace->roleOf($to), [WorkspaceRole::Admin, WorkspaceRole::Member], true)) {
            throw new InvalidArgumentException('Pick a member or an admin of this workspace.');
        }
        DB::transaction(function () use ($workspace, $from, $to): void {
            $workspace->members()->updateExistingPivot($to->getKey(), ['role' => WorkspaceRole::Owner->value]);
            $workspace->members()->updateExistingPivot($from->getKey(), ['role' => WorkspaceRole::Admin->value]);
        });
        $this->roles = [];
    }

    /**
     * Leaves a workspace (since v1.5): an owner hands it over first, and nobody leaves their only workspace. What they
     * made stays in it, theirs (see removeMember()).
     */
    public function leave(Workspace $workspace, User $user): void
    {
        $role = $workspace->roleOf($user);
        if ($role === null) {
            return;
        }
        if ($role === WorkspaceRole::Owner) {
            throw new InvalidArgumentException('Hand the workspace over to someone first: a workspace always keeps its owner.');
        }
        if ($user->workspaces()->count() <= 1) {
            throw new InvalidArgumentException('This is your only workspace.');
        }
        $this->removeMember($workspace, $user);
    }

    private function ownerCount(Workspace $workspace): int
    {
        return $workspace->members()->wherePivot('role', WorkspaceRole::Owner->value)->count();
    }
}
