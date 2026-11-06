<?php

declare(strict_types=1);

namespace App\Services\Accounts;

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
     * Makes the account with its personal workspace and joins the workspace it was invited to. An invitation or a
     * verified Google address proves the email, so it starts confirmed; otherwise the confirmation email goes out.
     *
     * @param  array{name: string, email: string, password: string, google_id?: string|null}  $data  `password` already hashed or plain (the cast hashes)
     */
    public function create(array $data, ?WorkspaceInvitation $invitation = null, bool $emailVerified = false, string $via = 'password'): User
    {
        $email = Str::lower(trim($data['email']));
        $invitation ??= $this->invitations->pendingFor($email);

        return DB::transaction(function () use ($data, $email, $invitation, $emailVerified, $via): User {
            $user = new User(['name' => mb_substr(trim($data['name']) ?: Str::before($email, '@'), 0, 255), 'email' => $email, 'password' => $data['password']]);
            $user->forceFill([
                'google_id' => $data['google_id'] ?? null,
                'email_verified_at' => $emailVerified || ($invitation !== null && $this->invitations->matches($invitation, $email)) ? now() : null,
            ])->save();
            $this->workspaces->createFor($user);
            if ($invitation !== null && $this->invitations->matches($invitation, $email)) {
                $this->invitations->accept($invitation, $user);
            }
            $this->audit->record('account_created', $user->id, $user, $via === 'google' ? 'Account made with Google' : 'Account made', ['via' => $via]);

            return $user;
        });
    }
}
