<?php

declare(strict_types=1);

namespace App\Models;

use App\Mail\ResetPasswordMail;
use App\Mail\VerifyEmailMail;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Laravel\Passport\Contracts\OAuthenticatable;
use Laravel\Passport\HasApiTokens;

/**
 * A console account. Its email is confirmed (`email_verified_at`) once its owner opened the link the console sent to
 * it; people-only share links (v1.5) open only for a confirmed email, and a changed email must be confirmed again.
 * Since v1.5 Claude and ChatGPT read its recordings with Passport tokens (the connector, routes/ai.php); Passport's
 * deprecated `clients()` is this model's projects, as before.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property bool $is_super_admin
 * @property int|null $current_workspace_id
 * @property string|null $google_id the linked Google account (its stable `sub`), since v1.5
 * @property Carbon|null $deletion_requested_at
 * @property Carbon|null $deletes_at when the account is erased (7 days after it asked); null = not being deleted
 * @property Carbon|null $consent_seen_at when the recording-consent notice was shown after sign-up
 * @property bool $notify_transcript_ready an email when a transcript of theirs is ready (off by default)
 * @property array<string, bool>|null $email_preferences Profile → Emails per category (since v1.5, Phase 1C; App\Services\Accounts\EmailPreferences)
 * @property array<string, mixed>|null $console_preferences the console's Library group and sort per view (since v1.5, Phase 1C)
 * @property bool $has_password whether its owner chose a password (since v1.6: an account made with a code or Google has none)
 * @property Carbon|null $terms_accepted_at when the Terms and the Privacy Policy were accepted at sign-up (since v1.6)
 * @property Carbon|null $created_at
 * @property-read Workspace|null $currentWorkspace
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail, OAuthenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected static function booted(): void
    {
        // What was confirmed is the old address (the profile page changes it at once).
        static::updating(function (User $user): void {
            if ($user->isDirty('email') && ! $user->isDirty('email_verified_at')) {
                $user->email_verified_at = null;
            }
            // Since v1.6: a password set later (the reset link, the profile) is one the owner knows.
            if ($user->isDirty('password') && ! $user->isDirty('has_password')) {
                $user->has_password = true;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Super admins (the server operator) and members of a workspace reach /admin; `is_super_admin` and
        // `current_workspace_id` are never mass-assignable. Content is always that of the current workspace.
        return $panel->getId() === 'admin' && ($this->is_super_admin || $this->workspaces()->exists());
    }

    /** In the 7-day deletion grace (v1.5): signing in only offers "Keep my account". */
    public function isBeingDeleted(): bool
    {
        return $this->deletes_at !== null;
    }

    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }

    /** The console's confirmation link, as a queued email of our own (Mail::fake() sees it in tests). */
    public function sendEmailVerificationNotification(): void
    {
        Mail::to($this->email)->queue(new VerifyEmailMail(Filament::getPanel('admin')->getVerifyEmailUrl($this)));
    }

    /** @param  string  $token */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        Mail::to($this->email)->queue(new ResetPasswordMail(Filament::getPanel('admin')->getResetPasswordUrl($token, $this)));
    }

    /** @return BelongsToMany<Workspace, $this> */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_user')->withPivot('role')->withTimestamps();
    }

    /** @return BelongsTo<Workspace, $this> */
    public function currentWorkspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<Client, $this> */
    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    /** @return HasMany<CaptureSession, $this> */
    public function captureSessions(): HasMany
    {
        return $this->hasMany(CaptureSession::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
            'current_workspace_id' => 'integer',
            'deletion_requested_at' => 'datetime',
            'deletes_at' => 'datetime',
            'consent_seen_at' => 'datetime',
            'notify_transcript_ready' => 'boolean',
            'email_preferences' => 'array',
            'console_preferences' => 'array',
            'has_password' => 'boolean',
            'terms_accepted_at' => 'datetime',
        ];
    }
}
