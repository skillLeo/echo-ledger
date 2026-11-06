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
use App\Services\Workspaces\Workspaces;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Invitations to a workspace (since v1.5). An existing account is added at once and told by email; an address
 * without an account gets a link to the sign-up page that works for 7 days and only for that address. Accepting it
 * makes the account a member with the invited role.
 */
final class Invitations
{
    public const DAYS = 7;

    public function __construct(private readonly Workspaces $workspaces, private readonly AuditLog $audit) {}

    /** A new invitation for [$email], replacing an unused one to the same workspace. Returns the sign-up link. */
    public function invite(Workspace $workspace, User $inviter, string $email, WorkspaceRole $role): string
    {
        $email = Str::lower(trim($email));
        if (User::query()->where('email', $email)->exists()) {
            throw new InvalidArgumentException('That address already has an account: add it as a member.');
        }
        WorkspaceInvitation::query()->where('workspace_id', $workspace->id)->where('email', $email)->whereNull('accepted_at')->delete();
        $token = Str::random(48);
        $invitation = WorkspaceInvitation::query()->create([
            'workspace_id' => $workspace->id, 'email' => $email, 'role' => $role->value, 'token_hash' => hash('sha256', $token),
            'invited_by' => $inviter->id, 'expires_at' => now()->addDays(self::DAYS),
        ]);
        $url = Filament::getPanel('admin')->getRegistrationUrl(['invite' => $token]) ?? url('/admin/register?invite='.$token);
        $expires = $invitation->expires_at->setTimezone(app(SettingsRepository::class)->displayTimezone())->format('M j, Y');
        Mail::to($email)->queue(new InvitationMail($workspace->name, $inviter->name, $url, signUp: true, expires: $expires));
        $this->audit->record('member_invited', $inviter->id, $workspace, "Invited {$email} to {$workspace->name}");

        return $url;
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

    public function accept(WorkspaceInvitation $invitation, User $user): void
    {
        $workspace = $invitation->workspace;
        if ($workspace->roleOf($user) === null) {
            $this->workspaces->addMember($workspace, $user, $invitation->role);
        }
        // The team they were invited to is what they came for.
        $user->forceFill(['current_workspace_id' => $workspace->id])->save();
        $invitation->forceFill(['accepted_at' => now()])->save();
        $this->audit->record('member_added', $user->id, $workspace, "Joined {$workspace->name}");
    }
}
