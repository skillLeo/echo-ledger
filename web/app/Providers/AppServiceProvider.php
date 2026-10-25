<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\Recording;
use App\Models\Setting;
use App\Models\Transcript;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\CredentialVault;
use App\Services\RecordingCipher;
use App\Services\SettingsRepository;
use App\Stt\SttManager;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped = fresh per request and per queued job (no stale settings/secrets across jobs).
        $this->app->scoped(SettingsRepository::class);
        $this->app->scoped(CredentialVault::class);
        $this->app->scoped(SttManager::class);
        $this->app->bind(RecordingCipher::class, fn (): RecordingCipher => RecordingCipher::fromConfig());
    }

    public function boot(): void
    {
        // docs/API.md returns single resources unwrapped ({"id": …}); Laravel would otherwise wrap them in {"data": …}.
        JsonResource::withoutWrapping();

        // Times are stored in UTC and shown in the zone chosen under Settings (read lazily: no query at boot).
        FilamentTimezone::set(fn (): string => app(SettingsRepository::class)->displayTimezone());

        // Console passwords follow the same 12-character minimum as `echoledger:make-admin` (Filament's profile
        // page validates new passwords with Password::default()).
        Password::defaults(static fn (): Password => Password::min(12));

        // Short, stable morph names: tokens and Livewire payloads never carry PHP class names.
        Relation::enforceMorphMap([
            'user' => User::class,
            'device' => Device::class,
            'device_pairing_code' => DevicePairingCode::class,
            'client' => Client::class,
            'client_keyterm' => ClientKeyterm::class,
            'capture_session' => CaptureSession::class,
            'recording' => Recording::class,
            'transcript' => Transcript::class,
            'setting' => Setting::class,
            'credential' => Credential::class,
            'webhook_event' => WebhookEvent::class,
        ]);

        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(240)->by('device:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(600)->by('upload:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('stt-token', fn (Request $request) => Limit::perMinute(30)->by('stt:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('pairing', fn (Request $request) => Limit::perMinute(5)->by('pair:'.$request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('hook:'.$request->ip()));
    }
}
