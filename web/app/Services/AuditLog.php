<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Credential;
use App\Models\Device;
use App\Models\ShareLink;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes the audit log (plan B16). Who did it comes from the request: a paired device, a signed-in console user,
 * or the system (scheduled jobs). Recording the event never breaks the action it describes.
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
        'device_paired' => 'Device paired',
        'device_revoked' => 'Device revoked',
        'device_token_rotated' => 'Device key renewed',
        'password_changed' => 'Password changed',
        'mfa_enabled' => 'Two-step sign-in turned on',
        'mfa_disabled' => 'Two-step sign-in turned off',
        'passkey_added' => 'Passkey added',
        'passkey_removed' => 'Passkey removed',
        'credentials_changed' => 'Provider credentials changed',
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
            if ($e->guard === 'web' && $e->user instanceof User && ! session()->pull('audit.passkey_login', false)) {
                $log()->record('login', (int) $e->user->getAuthIdentifier());
            }
        });
        Event::listen(Failed::class, function (Failed $e) use ($log): void {
            if ($e->guard === 'web') {
                $email = Str::limit((string) ($e->credentials['email'] ?? ''), 120, '');
                $log()->record('login_failed', $e->user instanceof User ? (int) $e->user->getAuthIdentifier() : null, null, "Sign-in failed for {$email}");
            }
        });
        Event::listen(Logout::class, function (Logout $e) use ($log): void {
            if ($e->guard === 'web' && $e->user instanceof User) {
                $log()->record('logout', (int) $e->user->getAuthIdentifier());
            }
        });

        CaptureSession::deleted(fn (CaptureSession $s) => $log()->record('session_deleted', $s->user_id, $s, 'Recording deleted: '.$s->displayTitle()));
        Device::created(fn (Device $d) => $log()->record('device_paired', $d->user_id, $d, "Device paired: {$d->name}"));
        Device::deleted(fn (Device $d) => $log()->record('device_revoked', $d->user_id, $d, "Device revoked: {$d->name}"));
        ShareLink::created(fn (ShareLink $l) => $log()->record('share_created', $l->captureSession->user_id, $l->captureSession, 'Share link made for '.$l->captureSession->displayTitle()));
        ShareLink::updated(function (ShareLink $l) use ($log): void {
            if ($l->wasChanged('revoked_at') && $l->revoked_at !== null) {
                $log()->record('share_revoked', $l->captureSession->user_id, $l->captureSession, 'Share link revoked for '.$l->captureSession->displayTitle());
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
