<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\AppAuthentication;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\EmailVerificationPrompt;
use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Support\CommandPalette;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\LocalInterFontProvider;
use App\Filament\Support\Palette;
use App\Http\Middleware\EnsureWorkspaceMember;
use Filament\Enums\ThemeMode;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->brandName('EchoLedger')
            // The brand mark (since v1.5, docs/design/brand) next to the name, and as the tab's icon.
            ->brandLogo(fn (): View => view('filament.brand'))
            ->brandLogoHeight('1.75rem')
            ->favicon(asset('favicon.svg'))
            ->login(Login::class)
            // Sign-up (since v1.5): the page exists, but answers 404 until Settings → "Who can sign up" opens it.
            ->registration(Register::class)
            ->passwordReset(RequestPasswordReset::class)
            // The activity centre (B18): the bell shows transcript, upload, device and budget notifications.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            // Passkeys (B16): a second way in, next to the password and the optional two-step code.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): View => view('filament.auth.passkey-login'))
            // Sign in with Google (since v1.5), once API Credentials → Google sign-in is filled in.
            ->renderHook(PanelsRenderHook::AUTH_LOGIN_FORM_AFTER, fn (): View => view('filament.auth.google-login'))
            ->renderHook(PanelsRenderHook::AUTH_REGISTER_FORM_AFTER, fn (): View => view('filament.auth.google-login', ['label' => 'Sign up with Google']))
            // C6: a quiet notice while the browser is offline.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.offline-banner'))
            ->profile(EditProfile::class, isSimple: false)
            // Confirming one's email: required since v1.5 Phase 4 (an unconfirmed account sees only "Confirm your
            // email", and the apps' sign-in answers 403 email_not_verified); people-only share links need it too.
            ->emailVerification(EmailVerificationPrompt::class, isRequired: true)
            // Optional TOTP 2FA with one-time recovery codes: offered in the profile, not forced at login.
            ->multiFactorAuthentication([
                AppAuthentication::make()->recoverable()->brandName('EchoLedger'),
            ], isRequired: false)
            ->revealablePasswords(true)
            ->defaultThemeMode(ThemeMode::System)
            // The phone's design (docs/DESIGN_SYSTEM.md): slate and indigo, Inter, its radii and spacing; built by Vite.
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Palette::INDIGO,
                'gray' => Palette::SLATE,
                'danger' => Palette::RED,
                'warning' => Palette::AMBER,
                'success' => Palette::GREEN,
                'info' => Palette::TEAL,
            ])
            // Narrow screens get the phone's bottom bar; the sidebar stays one tap away behind the menu button.
            ->renderHook(PanelsRenderHook::BODY_END, fn (): View => view('filament.bottom-nav'))
            // Self-hosted font and initials avatars: the console asks no third-party host for anything.
            ->font('Inter', provider: LocalInterFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Ctrl / Cmd + K: pages, recordings (title and transcript) and clients.
            ->globalSearch(CommandPalette::class)
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchFieldSuffix(fn (): string => 'Ctrl K')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth('16rem')
            // The everyday pages sit ungrouped at the top, like the phone's tabs; the rest below.
            ->navigationGroups([
                NavigationGroup::make('More'),
                NavigationGroup::make('System'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([
                AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                // B20: the current workspace is checked on every page and Livewire request.
                EnsureWorkspaceMember::class,
            ], isPersistent: true);
    }
}
