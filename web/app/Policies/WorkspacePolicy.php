<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\WorkspaceRole;
use App\Models\Device;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;

/**
 * What each role may do in a workspace: the table of docs/API.md "Workspaces, roles and privacy", one method per row
 * (since v1.5). Content is private to its maker whatever the role, so none of these opens another person's
 * recordings or projects. The super admin's server pages are not here: they check `is_super_admin`.
 *
 * | Per workspace                                              | Owner | Admin               | Member    | Viewer    |
 * | record: record, upload, import; pair or sign in devices    | yes   | yes                 | yes       | no        |
 * | ownContent: their own recordings and projects              | yes   | yes                 | yes       | (has none)|
 * | openShared / saveShared: Shared with me, Save to my library| yes   | yes                 | yes       | open only |
 * | manageMember / assignRole: invite, remove, change roles    | yes   | members and viewers | no        | no        |
 * | viewUsage / viewMembersUsage: minutes and costs per person | yes   | yes                 | their own | no        |
 * | revokeDevice: another person's device                      | yes   | yes                 | no        | no        |
 * | manage: plan, name, transfer ownership, delete             | yes   | no                  | no        | no        |
 */
final class WorkspacePolicy
{
    public function __construct(private readonly Workspaces $workspaces) {}

    public function record(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->records() === true;
    }

    public function ownContent(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->records() === true;
    }

    public function openShared(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace) !== null;
    }

    public function saveShared(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->records() === true;
    }

    /** Invite people at all (the roles they may give are assignRole()). */
    public function invite(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->manages() === true;
    }

    /** Remove [$member] or change their role: the owner anyone but themselves, an admin members and viewers. */
    public function manageMember(User $user, Workspace $workspace, User $member): bool
    {
        if ((int) $user->getKey() === (int) $member->getKey()) {
            return false;
        }
        $target = $this->role($member, $workspace);

        return $target !== null && $this->mayHandle($this->role($user, $workspace), $target);
    }

    /** Give [$role] to someone (an invitation or a role change). Ownership moves only by transfer. */
    public function assignRole(User $user, Workspace $workspace, WorkspaceRole $role): bool
    {
        return $role !== WorkspaceRole::Owner && $this->mayHandle($this->role($user, $workspace), $role);
    }

    /** The usage page at all: everyone but a viewer (a member sees their own numbers). */
    public function viewUsage(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->records() === true;
    }

    /** Minutes and costs of every person of the workspace (never content). */
    public function viewMembersUsage(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace)?->manages() === true;
    }

    /** Revoke a device: one's own always, someone else's as owner or admin. */
    public function revokeDevice(User $user, Workspace $workspace, Device $device): bool
    {
        $role = $this->role($user, $workspace);
        if ($role === null || (int) $device->workspace_id !== (int) $workspace->getKey()) {
            return false;
        }

        return (int) $device->user_id === (int) $user->getKey() || $role->manages();
    }

    /** The plan, the workspace's name, transferring ownership and deleting the workspace. */
    public function manage(User $user, Workspace $workspace): bool
    {
        return $this->role($user, $workspace) === WorkspaceRole::Owner;
    }

    /** Leaving: anyone but an owner, who hands the workspace over first. */
    public function leave(User $user, Workspace $workspace): bool
    {
        $role = $this->role($user, $workspace);

        return $role !== null && $role !== WorkspaceRole::Owner;
    }

    private function mayHandle(?WorkspaceRole $actor, WorkspaceRole $target): bool
    {
        return match ($actor) {
            WorkspaceRole::Owner => true,
            WorkspaceRole::Admin => in_array($target, [WorkspaceRole::Member, WorkspaceRole::Viewer], true),
            default => false,
        };
    }

    private function role(User $user, Workspace $workspace): ?WorkspaceRole
    {
        return $this->workspaces->roleOf($user, (int) $workspace->getKey());
    }
}
