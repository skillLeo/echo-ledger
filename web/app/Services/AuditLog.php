<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Credential;
use App\Models\Device;
use App\Models\ShareLink;
use App\Models\User;
use App\Services\Accounts\SignInGuard;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the audit log (plan B16). Who did it comes from the request: a paired device, a signed-in console user,
 * an app connected through the connector (since v1.5), or the system (scheduled jobs). Recording the event never breaks
 * the action it describes.
 */
final class AuditLog
{
    /** Every event with how the console names it. */
    public const EVENTS = [
        'login' => 'Signed in',
        'passkey_login' => 'Signed in with a passkey',
        'login_failed' => 'Sign-in failed',
        'logout' => 'Signed out',
        'web_session_revoked' => 'Signed out a browser',
        'session_deleted' => 'Recording deleted',
        'transcript_exported' => 'Transcript exported',
        'sessions_exported' => 'Transcripts exported',
        'share_created' => 'Share link made',
        'share_revoked' => 'Share link revoked',
        'device_paired' => 'Device signed in with a code',
        'device_revoked' => 'Device revoked',
        'device_token_rotated' => 'Device key renewed',
        'password_changed' => 'Password changed',
        'mfa_enabled' => 'Two-step sign-in turned on',
        'mfa_disabled' => 'Two-step sign-in turned off',
        'passkey_added' => 'Passkey added',
        'passkey_removed' => 'Passkey removed',
        'credentials_changed' => 'Provider credentials changed',
        // Since v1.5: accounts, sign-in from the apps, teams and the settings.
        'account_created' => 'Account made',
        'google_login' => 'Signed in with Google',
        'google_linked' => 'Google account linked',
        'google_unlinked' => 'Google account unlinked',
        'google_confirmed' => 'Confirmed with Google',
        'device_signed_in' => 'Device signed in',
        'sign_in_locked' => 'Sign-in locked after failures',
        'signed_out_everywhere' => 'Signed out everywhere',
        'account_deletion_requested' => 'Account deletion asked for',
        'account_deletion_cancelled' => 'Account kept',
        'account_erased' => 'Account erased',
        'account_exported' => 'Data exported',
        'sent_to_teammate' => 'Sent to a teammate',
        'member_invited' => 'Invitation sent',
        'member_added' => 'Member added',
        'member_removed' => 'Member removed',
        'role_changed' => 'Role changed',
        'workspace_renamed' => 'Workspace renamed',
        'settings_changed' => 'Settings changed',
        'test_email_sent' => 'Test email sent',
        // Since v1.6: plans and a person's own features and limits.
        'entitlements_changed' => 'Features and limits changed for a person',
        'transcription_key_set' => 'Own transcription key saved',
        'transcription_key_removed' => 'Own transcription key removed',
        'upgrade_requested' => 'Upgrade asked for',
        'upgrade_approved' => 'Upgrade approved',
        'upgrade_declined' => 'Upgrade declined',
        'unusual_usage' => 'Unusual use',
        'device_signed_in_again' => 'Signed in again',
        'device_signed_out' => 'Device signed out',
        'device_removed' => 'Device removed from the list',
        'device_renamed' => 'Device renamed',
        'account_suspended' => 'Account suspended',
        'account_unsuspended' => 'Account suspension lifted',
        // Since v1.5: the Claude and ChatGPT connector.
        'connector_connected' => 'App connected',
        'connector_disconnected' => 'App disconnected',
        'connector_token_reused' => 'App disconnected: used token came back',
        'connector_tool_call' => 'Read by a connected app',
    ];

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(string $event, ?int $userId = null, ?Model $subject = null, string $summary = '', array $meta = []): ?AuditEvent
    {
        try {
            [$actor, $deviceId, $owner] = $this->actor();

            return AuditEvent::query()->create([
                'user_id' => $userId ?? $owner,
                'event' => $event,
                'actor' => $actor,
                'device_id' => $deviceId,
                'subject_type' => $subject === null ? null : Str::snake(class_basename($subject)),
                'subject_id' => $subject === null ? null : Str::limit((string) $subject->getKey(), 40, ''),
                'summary' => Str::limit($summary !== '' ? $summary : (self::EVENTS[$event] ?? $event), 255, '…'),
                'ip' => app()->runningInConsole() && ! app()->runningUnitTests() ? null : request()->ip(),
                'user_agent' => Str::limit((string) request()->userAgent(), 255, ''),
                'meta' => $meta === [] ? null : $meta,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Only users the request has already authenticated count: resolving a guard here would sign someone in as a side
     * effect of writing the log.
     *
     * @return array{0: string, 1: string|null, 2: int|null}
     */
    private function actor(): array
    {
        // The connector's guard is only asked once /mcp has made it the guard of the request: making it needs the keys.
        if (auth()->getDefaultDriver() === 'api' && auth()->guard('api')->hasUser()) {
            $person = auth()->guard('api')->user();

            return ['connector', null, $person instanceof User ? (int) $person->getAuthIdentifier() : null];
        }
        $device = auth()->guard('sanctum');
        if ($device->hasUser() && $device->user() instanceof Device) {
            return ['device', $device->user()->id, $device->user()->user_id];
        }
        $web = auth()->guard('web');
        if ($web->hasUser() && $web->user() instanceof User) {
            return ['web', null, (int) $web->user()->getAuthIdentifier()];
        }

        return ['system', null, null];
    }

    /**
     * Records sign-ins and the model changes worth auditing. Called once at boot; exports are recorded where they
     * happen.
     */
    public static function listen(): void
    {
        $log = fn (): self => app(self::class);
        Event::listen(Login::class, function (Login $e) use ($log): void {
            // Passkey and Google sign-ins record their own event.
            $own = session()->pull('audit.passkey_login', false) || session()->pull('audit.google_login', false);
            if ($e->guard === 'web' && $e->user instanceof User && ! $own) {
                $log()->record('login', (int) $e->user->getAuthIdentifier());
            }
        });
        Event::listen(Failed::class, function (Failed $e) use ($log): void {
            if ($e->guard === 'web') {
                $email = Str::limit((string) ($e->credentials['email'] ?? ''), 120, '');
                $userId = $e->user instanceof User ? (int) $e->user->getAuthIdentifier() : null;
                $log()->record('login_failed', $userId, null, "Sign-in failed for {$email}");
                // Since v1.5 the console's failures count with the apps' sign-ins: a burst locks the email for a while.
                if ($email !== '') {
                    app(SignInGuard::class)->fail($email, $userId);
                }
            }
        });
        Event::listen(Logout::class, function (Logout $e) use ($log): void {
            if ($e->guard === 'web' && $e->user instanceof User) {
                $log()->record('logout', (int) $e->user->getAuthIdentifier());
            }
        });

        CaptureSession::deleted(fn (CaptureSession $s) => $log()->record('session_deleted', $s->user_id, $s, 'Recording deleted: '.$s->displayTitle()));
        Device::created(fn (Device $d) => $d->signedInWith === null
            ? $log()->record('device_paired', $d->user_id, $d, "Device signed in with a code: {$d->name}")
            : $log()->record('device_signed_in', $d->user_id, $d, "Signed in on {$d->name} ({$d->platform->getLabel()}) ".($d->signedInWith === 'browser' ? 'through the browser' : 'with a password'), ['via' => $d->signedInWith]));
        // Since v1.6 a revoke signs the device out (Device::signOut); deleting the row is "Remove from list".
        Device::deleted(fn (Device $d) => $log()->record($d->signed_out_at !== null ? 'device_removed' : 'device_revoked', $d->user_id, $d, ($d->signed_out_at !== null ? 'Device removed from the list: ' : 'Device revoked: ').$d->name));
        // A link opens a recording or (since v1.5) a project: the log names whichever it is.
        $shared = fn (ShareLink $l): array => $l->client_id !== null
            ? [$l->client?->user_id, $l->client, 'project '.$l->client?->name]
            : [$l->captureSession?->user_id, $l->captureSession, (string) $l->captureSession?->displayTitle()];
        ShareLink::created(function (ShareLink $l) use ($log, $shared): void {
            [$owner, $subject, $what] = $shared($l);
            $log()->record('share_created', $owner, $subject, 'Share link made for '.$what);
        });
        ShareLink::updated(function (ShareLink $l) use ($log, $shared): void {
            if ($l->wasChanged('revoked_at') && $l->revoked_at !== null) {
                [$owner, $subject, $what] = $shared($l);
                $log()->record('share_revoked', $owner, $subject, 'Share link revoked for '.$what);
            }
        });
        User::updated(function (User $u) use ($log): void {
            if ($u->wasChanged('password')) {
                $log()->record('password_changed', $u->id, $u);
            }
            if ($u->wasChanged('app_authentication_secret')) {
                $log()->record($u->app_authentication_secret ? 'mfa_enabled' : 'mfa_disabled', $u->id, $u);
            }
        });
        $credential = fn (Credential $c) => $log()->record('credentials_changed', $c->updated_by, null, "Provider credentials changed: {$c->provider}", ['provider' => $c->provider, 'key' => $c->key]);
        Credential::saved($credential);
        Credential::deleted($credential);
    }

    public static function label(string $event): string
    {
        return self::EVENTS[$event] ?? Str::headline($event);
    }
}
