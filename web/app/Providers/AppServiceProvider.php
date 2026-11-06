<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ClientKeyterm;
use App\Models\Credential;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\ProviderDeletion;
use App\Models\Recording;
use App\Models\Screen;
use App\Models\Setting;
use App\Models\Transcript;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\Accounts\KnownBrowsers;
use App\Services\ActivityFeed;
use App\Services\AuditLog;
use App\Services\ClientAi\ClaudeClientAi;
use App\Services\ClientAi\ClientAi;
use App\Services\ClientAi\ClientWorkspace;
use App\Services\CredentialVault;
use App\Services\MailSettings;
use App\Services\RecordingCipher;
use App\Services\Screens\ClaudeScreenDescriber;
use App\Services\Screens\ScreenDescriber;
use App\Services\SettingsRepository;
use App\Services\Views\ClaudeTranscriptConverter;
use App\Services\Views\TranscriptConverter;
use App\Services\Workspaces\Workspaces;
use App\Stt\SttManager;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped = fresh per request and per queued job (no stale settings/secrets across jobs).
        $this->app->scoped(SettingsRepository::class);
        $this->app->scoped(CredentialVault::class);
        // Settings → Email (since v1.5): the stored SMTP server replaces the .env one once the mailer is made, and
        // before each queued email, so a long-running worker sends with the latest save.
        $this->app->singleton(MailSettings::class);
        $this->app->afterResolving('mail.manager', function (mixed $manager): void {
            if ($manager instanceof MailManager) {
                $this->app->make(MailSettings::class)->apply($manager);
            }
        });
        // "Show as" views come from the Claude API; tests swap in a fake.
        $this->app->bind(TranscriptConverter::class, ClaudeTranscriptConverter::class);
        // Client workspaces (v1.4) ask Claude through one interface; tests swap in a fake.
        $this->app->bind(ClientAi::class, ClaudeClientAi::class);
        // Screen moments (v1.5) are read by Claude Haiku through one interface; tests swap in a fake.
        $this->app->bind(ScreenDescriber::class, ClaudeScreenDescriber::class);
        // Caches each member's role for one request (C9: no query per table row).
        $this->app->scoped(Workspaces::class);
        // Passport is here for the Claude/ChatGPT connector (v1.5 Phase 6), which registers the OAuth routes it needs
        // itself; none of Passport's own routes are served before then.
        Passport::ignoreRoutes();
        $this->app->scoped(SttManager::class);
        $this->app->bind(RecordingCipher::class, fn (): RecordingCipher => RecordingCipher::fromConfig());
    }

    public function boot(): void
    {
        // C9: outside production a relation loaded lazily on a model from a list of several (an N+1 query) throws,
        // so every test that renders a list catches a missing eager load. Production only loads lazily.
        Model::preventLazyLoading(! $this->app->isProduction());

        // docs/API.md returns single resources unwrapped ({"id": …}); Laravel would otherwise wrap them in {"data": …}.
        JsonResource::withoutWrapping();

        // A client's documents may be up to 20 MB on the console too; Livewire's own uploads stop at 12 MB.
        config(['livewire.temporary_file_upload.rules' => ['required', 'file', 'max:'.(ClientWorkspace::MAX_FILE_BYTES / 1024)]]);

        // The security centre's audit log (B16).
        AuditLog::listen();
        // The activity centre (B18).
        ActivityFeed::listen();
        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            if (($event->job->payload()['data']['commandName'] ?? null) === SendQueuedMailable::class) {
                app(MailSettings::class)->apply();
            }
        });
        // Since v1.5: a console sign-in from a browser the account has not used before emails the account.
        Event::listen(Login::class, [KnownBrowsers::class, 'handle']);
        // The account emails carry text people typed (a note, a title, a workspace or device name): with secured
        // encoding a "[link](…)" in it stays text, so nobody can put their own link in an email this server signs.
        Markdown::withSecuredEncoding();

        // Times are stored in UTC and shown in the zone chosen under Settings (read lazily: no query at boot).
        FilamentTimezone::set(fn (): string => app(SettingsRepository::class)->displayTimezone());

        // Console passwords: 12 characters with letters and numbers (sign-up, profile and reset all validate with
        // Password::default(); `echoledger:make-admin` keeps its 12-character minimum). No "uncompromised" check:
        // it would send part of each password's hash to a third party.
        Password::defaults(static fn (): Password => Password::min(12)->letters()->numbers());

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
            'usage_ledger_entry' => UsageLedgerEntry::class,
            'provider_deletion' => ProviderDeletion::class,
            'screen' => Screen::class,
        ]);

        RateLimiter::for('device', fn (Request $request) => Limit::perMinute(240)->by('device:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('uploads', fn (Request $request) => Limit::perMinute(600)->by('upload:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('stt-token', fn (Request $request) => Limit::perMinute(30)->by('stt:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('search', fn (Request $request) => Limit::perMinute(60)->by('search:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        // Ask costs money on every question: at most 20 a minute for a device.
        RateLimiter::for('ask', fn (Request $request) => Limit::perMinute(20)->by('ask:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('share', fn (Request $request) => Limit::perMinute(120)->by('share:'.$request->ip()));
        RateLimiter::for('share-password', fn (Request $request) => Limit::perMinute(10)->by('share-pw:'.$request->ip().'|'.(string) $request->route('token')));
        // Request links (v1.5): no account, so every upload call is counted per address (chunks of a few MB each).
        RateLimiter::for('request-upload', fn (Request $request) => Limit::perMinute(300)->by('request:'.$request->ip()));
        RateLimiter::for('pairing', fn (Request $request) => Limit::perMinute(5)->by('pair:'.$request->ip()));
        RateLimiter::for('passkey', fn (Request $request) => Limit::perMinute(20)->by('passkey:'.$request->ip()));
        // Signing in with an account (since v1.5, docs/API.md): the password route answers its own code when throttled,
        // as it does after 5 failures for one email; the browser sign-in's code exchange and its approval page.
        RateLimiter::for('auth-password', fn (Request $request) => Limit::perMinute(5)->by('auth-pw:'.$request->ip())
            ->response(fn (Request $request, array $headers) => response()->json(['message' => 'Too many sign-in attempts. Try again later.', 'code' => 'too_many_attempts'], 429, $headers)));
        RateLimiter::for('auth-token', fn (Request $request) => Limit::perMinute(10)->by('auth-token:'.$request->ip()));
        RateLimiter::for('device-sign-in', fn (Request $request) => Limit::perMinute(30)->by('device-sign-in:'.$request->ip()));
        // The public account pages (since v1.5): /account/delete and the export download.
        RateLimiter::for('account', fn (Request $request) => Limit::perMinute(30)->by('account:'.$request->ip()));
        // Sign in with Google (since v1.5): the redirect, Google's answer and the "confirm it is you" step.
        RateLimiter::for('google', fn (Request $request) => Limit::perMinute(20)->by('google:'.$request->ip()));
        RateLimiter::for('webhooks', fn (Request $request) => Limit::perMinute(300)->by('hook:'.$request->ip()));
        // The public site (since v1.5): the landing and downloads pages and the apps' update manifests.
        RateLimiter::for('site', fn (Request $request) => Limit::perMinute(60)->by('site:'.$request->ip()));
    }
}
