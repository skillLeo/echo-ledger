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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
