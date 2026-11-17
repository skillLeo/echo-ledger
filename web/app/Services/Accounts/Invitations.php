<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Mail\InvitationMail;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceInvitation;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Workspaces\PlanLimits;
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Invitations to a workspace (since v1.5). Every invitation is an email with a link that works for 7 days (since v1.8
 * P6.3: 1, 7 or 30, InviteLinks::DAYS), once, and
 * only for that address: an address without an account gets the sign-up page with the invitation, an account the
 * signed page that asks it to join (InvitationController). Accepting makes the account a member with the invited
 * role. An open invitation takes a seat of the workspace's plan (`403 seat_limit_reached` past them), so does accepting.
 */
final class Invitations
{
    public const DAYS = 7;

    public function __construct(private readonly Workspaces $workspaces, private readonly AuditLog $audit, private readonly PlanLimits $limits) {}

    /**
     * A new invitation for [$email] with [$role], replacing an unused one to the same workspace. Returns the link in
     * the email. Someone already in the workspace is refused; `403 seat_limit_reached` when no seat is left.
     */
    public function invite(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role, int $days = self::DAYS): string
    {
        $days = in_array($days, InviteLinks::DAYS, true) ? $days : self::DAYS;
        $email = Str::lower(trim($email));
        if ($role === WorkspaceRole::Owner) {
            throw new InvalidArgumentException('A workspace has one owner: invite them as an admin and hand it over later.');
        }
        $existing = User::query()->where('email', $email)->first();
        if ($existing instanceof User && $workspace->roleOf($existing) !== null) {
            throw new InvalidArgumentException('That person is in this workspace already.');
        }

        // Since v1.6 a team is a paid feature of the workspace's plan (the inviter's overrides count).
        $this->limits->assertFeature($workspace->id, 'team', $inviter->id);

        return DB::transaction(function () use ($workspace, $inviter, $email, $role, $existing, $days): string {
            // One invitation of the workspace at a time, so two cannot both take its last seat.
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();
            WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->where('email', $email)->whereNull('accepted_at')->delete();
            $this->limits->assertSeat($workspace->id, null, $inviter->id);
            $token = Str::random(48);
            $invitation = WorkspaceInvitation::query()->create([
                'workspace_id' => $workspace->id, 'email' => $email, 'role' => $role->value, 'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id, 'expires_at' => now()->addDays($days),
            ]);
            $url = $existing instanceof User ? $this->acceptUrl($invitation, $token) : $this->signUpUrl($token);
            $expires = $invitation->expires_at->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, Y');
            Mail::to($email)->queue(new InvitationMail($workspace->name, $inviter->name, $url, signUp: ! $existing instanceof User, expires: $expires, join: $existing instanceof User));
            $this->audit->record('member_invited', $inviter->id, $workspace, "Invited {$email} to {$workspace->name} as ".Str::lower($role->label()));

            return $url;
        });
    }

    /** The sign-up page with the invitation, for an address without an account. */
    public function signUpUrl(string $token): string
    {
        return Filament::getPanel('app')->getRegistrationUrl(['invite' => $token]) ?? url('/app/register?invite='.$token);
    }

    /** The signed page that asks an account to join; the signature ends with the invitation. */
    private function acceptUrl(WorkspaceInvitation $invitation, string $token): string
    {
        return URL::temporarySignedRoute('invitations.show', $invitation->expires_at, ['token' => $token]);
    }

    /** Tells an existing account it was added to a workspace (no link to accept: it is a member already). */
    public function tellAdded(Workspace $workspace, User $inviter, User $member): void
    {
        Mail::to($member->email)->queue(new InvitationMail($workspace->name, $inviter->name, Filament::getPanel('app')->getUrl() ?? url('/app'), signUp: false));
    }

    public function find(?string $token): ?WorkspaceInvitation
    {
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            return null;
        }
        $invitation = WorkspaceInvitation::query()->where('token_hash', hash('sha256', $token))->first();

        return $invitation instanceof WorkspaceInvitation && $invitation->usable() && self::inviterMay($invitation, $invitation->workspace) ? $invitation : null;
    }

    /** The newest usable invitation for an address (a Google sign-up while only invited people may sign up). */
    public function pendingFor(string $email): ?WorkspaceInvitation
    {
        return WorkspaceInvitation::query()->where('email', Str::lower(trim($email)))->whereNull('accepted_at')
            ->where('expires_at', '>', now())->latest('id')->first();
    }

    public function matches(WorkspaceInvitation $invitation, string $email): bool
    {
        return $invitation->usable() && hash_equals($invitation->email, Str::lower(trim($email)));
    }

    /**
     * Joins the workspace with the invited role, once; `403 seat_limit_reached` when its plan has no seat left, and
     * since v1.8 P6.3 `410 invitation_closed` when whoever invited may no longer invite there or give that role
     * (removed, demoted or deleted since). The workspace's row is locked, so two cannot take its last seat.
     */
    public function accept(WorkspaceInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user): void {
            $workspace = Workspace::query()->whereKey($invitation->workspace_id)->lockForUpdate()->firstOrFail();
            if ($workspace->roleOf($user) === null) {
                if (! self::inviterMay($invitation, $workspace)) {
                    throw new ApiError(410, 'invitation_closed', self::CLOSED);
                }
                $this->limits->assertSeat($workspace->id, $invitation->id);
                $this->workspaces->addMember($workspace, $user, $invitation->role);
            }
            // The team they were invited to is what they came for.
            $user->forceFill(['current_workspace_id' => $workspace->id])->save();
            $invitation->forceFill(['accepted_at' => now()])->save();
            $this->audit->record('member_added', $user->id, $workspace, "Joined {$workspace->name}");
        });
    }

    /** What an invitation that is no longer valid says (InvitationController's plain words). */
    public const CLOSED = 'It was used already, it ended, it was taken back, or the person who sent it can no longer invite. Ask for a new one.';

    /** Whether whoever sent [$invitation] may still invite to [$workspace] and give its role. */
    public static function inviterMay(WorkspaceInvitation $invitation, Workspace $workspace): bool
    {
        $inviter = $invitation->invited_by !== null ? User::query()->find($invitation->invited_by) : null;

        return $inviter instanceof User && Gate::forUser($inviter)->allows('invite', [$workspace]) && Gate::forUser($inviter)->allows('assignRole', [$workspace, $invitation->role]);
    }
}
