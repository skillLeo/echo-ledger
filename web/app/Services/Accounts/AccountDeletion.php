<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Enums\WorkspaceRole;
use App\Exceptions\ApiError;
use App\Mail\DeletionScheduledMail;
use App\Models\User;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

/**
 * Deleting one's account (since v1.5, docs/API.md "Your account"): confirmed with the password or a two-step code,
 * refused for the only owner of a workspace with other members (`409 owner_of_team`), then a 7-day grace in which the
 * account is signed out everywhere and signing in only offers "Keep my account". `echoledger:erase-accounts` erases
 * it afterwards (AccountEraser).
 */
final class AccountDeletion
{
    public const GRACE_DAYS = 7;

    public function __construct(
        private readonly SignInGuard $guard,
        private readonly TwoFactorCodes $codes,
        private readonly AccountSessions $sessions,
        private readonly AuditLog $audit,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * "Confirm it is you": the password, or a two-step code (only an account with two-step sign-in has one). Failures
     * count against the email like sign-ins (`429 too_many_attempts` after five).
     */
    public function confirm(User $user, #[\SensitiveParameter] ?string $password, #[\SensitiveParameter] ?string $otp): void
    {
        $this->guard->assertNotLocked($user->email);
        if (filled($password)) {
            if (! Hash::check((string) $password, $user->password)) {
                $this->guard->fail($user->email, $user->id);

                // An account made with Google has a password nobody saw: the public page confirms with Google instead.
                throw new ApiError(422, 'invalid_credentials', $user->google_id !== null && ! $user->hasTwoFactor()
                    ? 'That password is wrong. Made your account with Google? Open '.route('account.delete').' and confirm with Google there.'
                    : 'That password is wrong.');
            }
        } elseif (! filled($otp) || ! $this->codes->verify($user, (string) $otp)) {
            $this->guard->fail($user->email, $user->id);

            throw new ApiError(422, 'invalid_otp', 'That code is wrong or was used already.');
        }
        $this->guard->clear($user->email);
    }

    /** `409 owner_of_team` while the account is the only owner of a workspace that has other members. */
    public function assertNotOwnerOfTeam(User $user): void
    {
        $teams = DB::table('workspace_user as mine')
            ->where('mine.user_id', $user->id)->where('mine.role', WorkspaceRole::Owner->value)
            ->whereExists(fn ($q) => $q->from('workspace_user as others')->whereColumn('others.workspace_id', 'mine.workspace_id')->where('others.user_id', '!=', $user->id))
            ->whereNotExists(fn ($q) => $q->from('workspace_user as owners')->whereColumn('owners.workspace_id', 'mine.workspace_id')
                ->where('owners.user_id', '!=', $user->id)->where('owners.role', WorkspaceRole::Owner->value))
            ->exists();
        if ($teams) {
            throw new ApiError(409, 'owner_of_team', 'You own a workspace with other members. Make one of them an owner on the Workspace page first.');
        }
    }

    /** Starts the grace: signed out everywhere, the email with the date, the audit log. Returns when it is erased. */
    public function schedule(User $user): Carbon
    {
        $this->assertNotOwnerOfTeam($user);
        $deletesAt = $user->deletes_at ?? now()->addDays(self::GRACE_DAYS);
        $user->forceFill(['deletion_requested_at' => $user->deletion_requested_at ?? now(), 'deletes_at' => $deletesAt])->save();
        $this->sessions->signOutEverywhere($user);
        $date = $deletesAt->copy()->setTimezone($this->settings->displayTimezone())->format('M j, Y');
        Mail::to($user->email)->queue(new DeletionScheduledMail($date, Filament::getPanel('admin')->getLoginUrl() ?? url('/admin/login')));
        $this->audit->record('account_deletion_requested', $user->id, $user, "Account deletion asked for: erased on {$date}");

        return $deletesAt;
    }

    public function cancel(User $user): void
    {
        if (! $user->isBeingDeleted()) {
            return;
        }
        $user->forceFill(['deletion_requested_at' => null, 'deletes_at' => null])->save();
        $this->audit->record('account_deletion_cancelled', $user->id, $user);
    }
}
