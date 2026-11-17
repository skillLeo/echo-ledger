<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInviteLink;
use App\Services\AuditLog;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Invite links to a team (v1.8 P6.3): made by an owner or an admin, for members or viewers only (never admin or
 * owner), open for 1, 7 or 30 days, for a number of people or any, until revoked. The address is shown once, when the
 * link is made; only its hash is kept. A link takes no seat; each person who joins with it takes one then
 * (`403 seat_limit_reached` when none is left). It leads to /join/{token}: "Join Acme on EchoLedger".
 */
final class InviteLinks
{
    /** The roles a link may give. */
    public const ROLES = [WorkspaceRole::Member, WorkspaceRole::Viewer];

    /** How long a link (and an email invitation) may stay open, in days. */
    public const DAYS = [1, 7, 30];

    public const MAX_USES = 500;

    public function __construct(private readonly Workspaces $workspaces, private readonly PlanLimits $limits, private readonly AuditLog $audit) {}

    /**
     * A new link to [$workspace] for [$role]; returns the link and its address (shown once).
     *
     * @return array{link: WorkspaceInviteLink, url: string}
     */
    public function create(Workspace $workspace, User $creator, WorkspaceRole $role, int $days, ?int $maxUses): array
    {
        if (! in_array($role, self::ROLES, true)) {
            throw new ApiError(422, 'validation_failed', 'An invite link makes people members or viewers.', ['errors' => ['role' => ['An invite link makes people members or viewers.']]]);
        }
        // A team is a paid feature of the workspace's plan (the creator's overrides count), as for an email invitation.
        $this->limits->assertFeature($workspace->id, 'team', $creator->id);
        $token = Str::random(40);
        $link = WorkspaceInviteLink::query()->create([
            'workspace_id' => $workspace->id, 'role' => $role->value, 'token_hash' => hash('sha256', $token), 'created_by' => $creator->id,
            'expires_at' => now()->addDays(in_array($days, self::DAYS, true) ? $days : 7), 'max_uses' => $maxUses,
        ]);
        $this->audit->record('invite_link_created', $creator->id, $workspace, 'Made an invite link to '.$workspace->name.' for '.Str::lower($role->label()).'s'
            .($maxUses !== null ? " ({$maxUses} people)" : ''));

        return ['link' => $link, 'url' => self::url($token)];
    }

    public static function url(string $token): string
    {
        return route('join.show', ['token' => $token]);
    }

    /** The open link for [$token], or null. */
    public function find(?string $token): ?WorkspaceInviteLink
    {
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            return null;
        }
        $link = WorkspaceInviteLink::query()->where('token_hash', hash('sha256', $token))->first();

        return $link instanceof WorkspaceInviteLink && $link->usable() ? $link : null;
    }

    public function revoke(WorkspaceInviteLink $link, User $by): void
    {
        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => now()])->save();
            $this->audit->record('invite_link_revoked', $by->id, $link->workspace, 'Revoked an invite link to '.$link->workspace->name);
        }
    }

    /**
     * [$user] joins with [$link] (once; someone in the workspace already just switches to it): `410 link_closed` when
     * it is not open any more, `403 seat_limit_reached` when the plan has no seat left.
     */
    public function join(WorkspaceInviteLink $link, User $user): Workspace
    {
        return DB::transaction(function () use ($link, $user): Workspace {
            // The workspace's row first, as an invitation takes its seat, then the link's: two people cannot both
            // take the last seat or the last use.
            $workspace = Workspace::query()->whereKey($link->workspace_id)->lockForUpdate()->firstOrFail();
            $locked = WorkspaceInviteLink::query()->whereKey($link->id)->lockForUpdate()->firstOrFail();
            if ($workspace->roleOf($user) === null) {
                if (! $locked->usable()) {
                    throw new ApiError(410, 'link_closed', 'This invite link is no longer open. Ask for a new one.');
                }
                $this->limits->assertSeat($workspace->id);
                $this->workspaces->addMember($workspace, $user, $locked->role);
                $locked->forceFill(['uses' => $locked->uses + 1])->save();
                $this->audit->record('member_added', $user->id, $workspace, "Joined {$workspace->name} with an invite link");
            }
            // The team they came for.
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();

            return $workspace;
        });
    }
}
