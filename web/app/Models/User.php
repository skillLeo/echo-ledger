<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
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

/**
 * A console account. Its email is confirmed (`email_verified_at`) once its owner opened the link the console sent to
 * it; people-only share links (v1.5) open only for a confirmed email, and a changed email must be confirmed again.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property bool $is_super_admin
 * @property int|null $current_workspace_id
 * @property-read Workspace|null $currentWorkspace
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery, MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    protected static function booted(): void
    {
        // What was confirmed is the old address (the profile page changes it at once).
        static::updating(function (User $user): void {
            if ($user->isDirty('email') && ! $user->isDirty('email_verified_at')) {
                $user->email_verified_at = null;
            }
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        // Super admins (the server operator) and members of a workspace reach /admin; `is_super_admin` and
        // `current_workspace_id` are never mass-assignable. Content is always that of the current workspace.
        return $panel->getId() === 'admin' && ($this->is_super_admin || $this->workspaces()->exists());
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
        ];
    }
}
