<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Enums\WorkspaceRole;
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
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Invitations to a workspace (since v1.5). Every invitation is an email with a link that works for 7 days, once, and
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
    public function invite(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role): string
    {
        $email = Str::lower(trim($email));
        if ($role === WorkspaceRole::Owner) {
            throw new InvalidArgumentException('A workspace has one owner: invite them as an admin and hand it over later.');
        }
        $existing = User::query()->where('email', $email)->first();
        if ($existing instanceof User && $workspace->roleOf($existing) !== null) {
            throw new InvalidArgumentException('That person is in this workspace already.');
        }

        return DB::transaction(function () use ($workspace, $inviter, $email, $role, $existing): string {
            // One invitation of the workspace at a time, so two cannot both take its last seat.
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->first();
            WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->where('email', $email)->whereNull('accepted_at')->delete();
            $this->limits->assertSeat($workspace->id);
            $token = Str::random(48);
            $invitation = WorkspaceInvitation::query()->create([
                'workspace_id' => $workspace->id, 'email' => $email, 'role' => $role->value, 'token_hash' => hash('sha256', $token),
                'invited_by' => $inviter->id, 'expires_at' => now()->addDays(self::DAYS),
            ]);
            $url = $existing instanceof User ? $this->acceptUrl($invitation, $token) : $this->signUpUrl($token);
            $expires = $invitation->expires_at->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, Y');
            Mail::to($email)->queue(new InvitationMail($workspace->name, $inviter->name, $url, signUp: ! $existing instanceof User, expires: $expires));
            $this->audit->record('member_invited', $inviter->id, $workspace, "Invited {$email} to {$workspace->name} as ".Str::lower($role->label()));

            return $url;
        });
    }

    /** The sign-up page with the invitation, for an address without an account. */
    public function signUpUrl(string $token): string
    {
        return Filament::getPanel('admin')->getRegistrationUrl(['invite' => $token]) ?? url('/admin/register?invite='.$token);
    }

    /** The signed page that asks an account to join; the signature ends with the invitation. */
    private function acceptUrl(WorkspaceInvitation $invitation, string $token): string
    {
        return URL::temporarySignedRoute('invitations.show', $invitation->expires_at, ['token' => $token]);
    }

    /** Tells an existing account it was added to a workspace (no link to accept: it is a member already). */
    public function tellAdded(Workspace $workspace, User $inviter, User $member): void
    {
        Mail::to($member->email)->queue(new InvitationMail($workspace->name, $inviter->name, Filament::getPanel('admin')->getUrl() ?? url('/admin'), signUp: false));
    }

    public function find(?string $token): ?WorkspaceInvitation
    {
        if (! is_string($token) || $token === '' || strlen($token) > 128) {
            return null;
        }
        $invitation = WorkspaceInvitation::query()->where('token_hash', hash('sha256', $token))->first();

        return $invitation instanceof WorkspaceInvitation && $invitation->usable() ? $invitation : null;
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

    /** Joins the workspace with the invited role, once; `403 seat_limit_reached` when its plan has no seat left. */
    public function accept(WorkspaceInvitation $invitation, User $user): void
    {
        $workspace = $invitation->workspace;
        if ($workspace->roleOf($user) === null) {
            $this->limits->assertSeat($workspace->id, $invitation->id);
            $this->workspaces->addMember($workspace, $user, $invitation->role);
        }
        // The team they were invited to is what they came for.
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        $invitation->forceFill(['accepted_at' => now()])->save();
        $this->audit->record('member_added', $user->id, $workspace, "Joined {$workspace->name}");
    }
}
