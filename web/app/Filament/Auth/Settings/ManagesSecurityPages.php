<?php

declare(strict_types=1);

namespace App\Filament\Auth\Settings;

use App\Filament\Auth\AppAuthentication;
use App\Filament\Pages\Security;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SettingsContext;
use App\Models\KnownBrowser;
use App\Models\Passkey;
use App\Services\Accounts\AccountSessions;
use App\Services\Accounts\GoogleSignIn;
use App\Services\AuditLog;
use App\Services\Passkeys;
use App\Services\WebSessions;
use App\Support\ClientCountry;
use App\Support\Formats;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\Actions\DisableAppAuthenticationAction;
use Filament\Auth\MultiFactor\App\Actions\RegenerateAppAuthenticationRecoveryCodesAction;
use Filament\Auth\MultiFactor\App\Actions\SetUpAppAuthenticationAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password as PasswordRule;
use SensitiveParameter;

/**
 * Settings › Sign-in & security: the password, two-step sign-in, recovery codes, passkeys, Google, the browsers
 * signed in, the sign-in activity and Sign out everywhere.
 */
trait ManagesSecurityPages
{
    /** @return array<string, mixed> */
    public function securityData(): array
    {
        $user = $this->account();

        return [
            'twoStep' => $user->hasTwoFactor(),
            'hasPassword' => $user->has_password !== false,
            'google' => app(GoogleSignIn::class)->configured() || $user->google_id !== null,
            'googleLinked' => $user->google_id !== null,
            'googleUrl' => route('auth.google.redirect', ['intent' => 'link']),
            'googleConfigured' => app(GoogleSignIn::class)->configured(),
            'passkeysOn' => $this->context()->flag('full_console'),
            'passkeys' => Passkey::query()->where('user_id', $user->id)->latest()->get()->map(fn (Passkey $p): array => [
                'id' => $p->id, 'name' => $p->name,
                'value' => $p->last_used_at !== null ? 'Used '.$p->last_used_at->diffForHumans(short: true) : 'Added '.Formats::shortDate($p->created_at),
            ])->all(),
            'maxPasskeys' => Passkeys::MAX_PER_USER,
            'browsersOn' => WebSessions::available(),
            'browsers' => $this->browsersWithCountry(),
            'activityUrl' => $this->context()->flag('full_console') ? Security::getUrl(panel: 'app') : null,
        ];
    }

    /** Password: a new one twice, and the current one when the account has one. */
    public function changePasswordAction(): Action
    {
        return Action::make('changePassword')
            ->label('Password')
            ->modalHeading(fn (): string => $this->account()->has_password !== false ? 'Change password' : 'Choose a password')
            ->modalWidth('md')
            ->schema([
                TextInput::make('currentPassword')->label('Current password')
                    ->password()->revealable(filament()->arePasswordsRevealable())->autocomplete('current-password')
                    ->currentPassword(guard: Filament::getAuthGuard())->required()
                    ->visible(fn (): bool => $this->account()->has_password !== false),
                TextInput::make('password')->label('New password')
                    ->password()->revealable(filament()->arePasswordsRevealable())->autocomplete('new-password')
                    ->rule(PasswordRule::default())->required()->same('passwordConfirmation'),
                TextInput::make('passwordConfirmation')->label('Confirm the new password')
                    ->password()->revealable(filament()->arePasswordsRevealable())->autocomplete('new-password')->required(),
            ])
            ->modalSubmitActionLabel(fn (): string => $this->account()->has_password !== false ? 'Change password' : 'Set password')
            ->action(function (#[SensitiveParameter] array $data): void {
                $hash = Hash::make((string) $data['password']);
                $this->account()->forceFill(['password' => $hash])->save();
                if (request()->hasSession()) {
                    request()->session()->put(['password_hash_'.Filament::getAuthGuard() => $hash]);
                }
                Notification::make()->title('Password saved')->success()->send();
            });
    }

    private function appAuthentication(): AppAuthentication
    {
        foreach (Filament::getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider instanceof AppAuthentication) {
                return $provider;
            }
        }

        return AppAuthentication::make()->recoverable()->brandName('EchoLedger');
    }

    /** Two-step sign-in, set up with an authenticator app. */
    public function setUpAppAuthenticationAction(): Action
    {
        return SetUpAppAuthenticationAction::make($this->appAuthentication())->after(fn () => SettingsContext::forget());
    }

    public function regenerateAppAuthenticationRecoveryCodesAction(): Action
    {
        return RegenerateAppAuthenticationRecoveryCodesAction::make($this->appAuthentication());
    }

    public function disableAppAuthenticationAction(): Action
    {
        return DisableAppAuthenticationAction::make($this->appAuthentication())->after(fn () => SettingsContext::forget());
    }

    /** Google sign-in › Unlink, confirmed. */
    public function unlinkGoogleAction(): Action
    {
        return Action::make('unlinkGoogle')->label('Unlink')->color('danger')
            ->requiresConfirmation()->modalIcon(null)->modalHeading('Unlink Google?')
            ->modalDescription('You then sign in with your email and password. Never set one? Use "Forgot password?" on the sign-in page.')
            ->modalSubmitActionLabel('Unlink')
            ->action(function (): void {
                $this->account()->forceFill(['google_id' => null])->save();
                app(AuditLog::class)->record('google_unlinked', $this->account()->id, $this->account());
                Notification::make()->title('Google unlinked')->success()->send();
            });
    }

    /** Options for `navigator.credentials.create()`, kept in the session until the answer comes back. */
    public function passkeyOptions(): string
    {
        return app(Passkeys::class)->registrationOptions($this->account());
    }

    /** @param array<string, mixed> $credential */
    public function savePasskey(array $credential, string $name = ''): void
    {
        $passkey = app(Passkeys::class)->register($this->account(), (string) json_encode($credential), $name);
        $passkey === null
            ? Notification::make()->title("Couldn't add that passkey")->body('Try again, or use another device.')->danger()->send()
            : Notification::make()->title('Passkey added')->body("“{$passkey->name}” can now sign you in.")->success()->send();
    }

    /** Passkeys › Remove, confirmed. */
    public function removePasskeyAction(): Action
    {
        return Action::make('removePasskey')->label('Remove')->color('danger')
            ->requiresConfirmation()->modalIcon(null)->modalHeading('Remove this passkey?')
            ->modalDescription('It will no longer sign you in.')->modalSubmitActionLabel('Remove')
            ->action(fn (array $arguments) => $this->removePasskey((string) ($arguments['passkey'] ?? '')));
    }

    public function removePasskey(string $id): void
    {
        if (app(Passkeys::class)->remove($this->account(), $id)) {
            Notification::make()->title('Passkey removed')->success()->send();
        }
    }

    /** Signed-in browsers › Sign out, confirmed. */
    public function signOutBrowserAction(): Action
    {
        return Action::make('signOutBrowser')->label('Sign out')->color('danger')
            ->requiresConfirmation()->modalIcon(null)->modalHeading('Sign out this browser?')
            ->modalDescription('It will need to sign in again.')->modalSubmitActionLabel('Sign out')
            ->action(fn (array $arguments) => $this->signOutBrowser((string) ($arguments['browser'] ?? '')));
    }

    public function signOutBrowser(string $id): void
    {
        $done = app(WebSessions::class)->revoke($this->account()->id, $id, session()->getId());
        Notification::make()->title($done ? 'Browser signed out' : 'That browser was already signed out')->success()->send();
    }

    /** Sign out everywhere: every phone, computer, browser and connected app, this browser too. */
    public function signOutEverywhereAction(): Action
    {
        return Action::make('signOutEverywhere')->label('Sign out everywhere')->color('danger')
            ->requiresConfirmation()->modalIcon(null)->modalHeading('Sign out everywhere?')
            ->modalDescription("You'll be signed out on every phone, computer and browser, including this one.")
            ->modalSubmitActionLabel('Sign out everywhere')
            ->action(function (): void {
                app(AccountSessions::class)->signOutEverywhere($this->account());
                Filament::auth()->logout();
                session()->invalidate();
                session()->regenerateToken();
                $this->redirect(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
            });
    }

    /**
     * The console browsers with the country of their address when the server's proxy named it.
     *
     * @return list<array<string, mixed>>
     */
    private function browsersWithCountry(): array
    {
        if (! WebSessions::available()) {
            return [];
        }
        $browsers = app(WebSessions::class)->list($this->account()->id, session()->getId());
        $countries = KnownBrowser::query()->where('user_id', $this->account()->id)->whereNotNull('country')
            ->whereIn('ip', array_values(array_filter(array_column($browsers, 'ip'))))
            ->orderByDesc('last_used_at')->get(['ip', 'country'])->unique('ip')->pluck('country', 'ip');

        return array_map(fn (array $browser): array => [
            ...$browser,
            'country' => $browser['current'] ? (ClientCountry::of(request()) ?? $countries[$browser['ip']] ?? null) : ($countries[$browser['ip']] ?? null),
        ], $browsers);
    }
}
