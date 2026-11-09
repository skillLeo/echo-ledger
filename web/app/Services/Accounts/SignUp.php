<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Exceptions\ApiError;
use App\Models\Plan;
use App\Models\User;
use App\Models\WorkspaceInvitation;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who may make an account on this server (Settings → Privacy & security → "Who can sign up", since v1.5): nobody (the
 * default: accounts are made by the operator or added on the Workspace page), anyone, or only people with an
 * invitation. Every new account gets its own personal workspace, as `echoledger:make-admin` does.
 */
final class SignUp
{
    public const NOBODY = 'nobody';

    public const ANYONE = 'anyone';

    public const INVITED = 'invited';

    /** @var array<string, string> */
    public const MODES = [
        self::NOBODY => 'Nobody (accounts are added by you)',
        self::ANYONE => 'Anyone',
        self::INVITED => 'Only people with an invitation',
    ];

    /** How Settings → Who can sign up names the three choices (since v1.5, Phase 1C): On, Off and invite only. */
    public const SETTING_LABELS = [
        self::ANYONE => 'On: anyone can make a free account (family and friends too)',
        self::NOBODY => 'Off: only people you add on the Workspace page',
        self::INVITED => 'Invite only: people you invite by email',
    ];

    public const SETTING_HELP = 'On: family and friends open the sign-up page (or Create account in the apps) and make their own free account with its own private workspace. Nobody sees anyone else\'s recordings unless they are shared, you included. Off: there is no sign-up page; you add people on the Workspace page and they join your workspace as teammates. Invite only: the Workspace page\'s Invite emails a sign-up link to an address without an account.';

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Workspaces $workspaces,
        private readonly Invitations $invitations,
        private readonly AuditLog $audit,
    ) {}

    public function mode(): string
    {
        $mode = $this->settings->get('signup.mode', self::NOBODY);

        return is_string($mode) && isset(self::MODES[$mode]) ? $mode : self::NOBODY;
    }

    /** Whether the sign-up page is offered at all (an invitation is still needed when the mode is "invited"). */
    public function open(): bool
    {
        return $this->mode() !== self::NOBODY;
    }

    /** Whether [$email] may make an account now, with or without [$invitation]. */
    public function allows(string $email, ?WorkspaceInvitation $invitation = null): bool
    {
        return match ($this->mode()) {
            self::ANYONE => true,
            self::INVITED => $invitation !== null ? $this->invitations->matches($invitation, $email) : $this->invitations->pendingFor($email) !== null,
            default => false,
        };
    }

    /**
     * Makes the account with its personal workspace and joins the workspace it was invited to. An invitation's link or
     * a verified Google address proves the email, so it starts confirmed; otherwise the confirmation email goes out.
     * Without the link, a pending invitation for the address is used only when the address is proven (Google): typing
     * an invited address on the sign-up page proves nothing, so that account neither starts confirmed nor joins.
     *
     * Since v1.6 an account made with an email code or with Google has no password its owner knows (`has_password` false:
     * the sign-in offers a code instead) and records when the Terms were accepted.
     *
     * @param  array{name: string, email: string, password: string, google_id?: string|null}  $data  `password` already hashed or plain (the cast hashes)
     */
    public function create(array $data, ?WorkspaceInvitation $invitation = null, bool $emailVerified = false, string $via = 'password', bool $acceptedTerms = false): User
    {
        $email = Str::lower(trim($data['email']));
        if ($invitation === null && $emailVerified) {
            $invitation = $this->invitations->pendingFor($email);
        }

        return DB::transaction(function () use ($data, $email, $invitation, $emailVerified, $via, $acceptedTerms): User {
            $user = new User(['name' => mb_substr(trim($data['name']) ?: Str::before($email, '@'), 0, 255), 'email' => $email, 'password' => $data['password']]);
            $user->forceFill([
                'google_id' => $data['google_id'] ?? null,
                'has_password' => $via === 'password',
                'terms_accepted_at' => $acceptedTerms ? now() : null,
                'email_verified_at' => $emailVerified || ($invitation !== null && $this->invitations->matches($invitation, $email)) ? now() : null,
            ])->save();
            // Since v1.5 a new account's own workspace is on the Free plan.
            $this->workspaces->createFor($user, 'Personal', Plan::free());
            if ($invitation !== null && $this->invitations->matches($invitation, $email)) {
                try {
                    $this->invitations->accept($invitation, $user);
                } catch (ApiError) {
                    // The team filled its seats meanwhile: the account is made all the same, without joining it.
                }
            }
            $this->audit->record('account_created', $user->id, $user, match ($via) {
                'google' => 'Account made with Google',
                'code' => 'Account made with an email code',
                default => 'Account made',
            }, ['via' => $via]);

            return $user;
        });
    }
}
