<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\LocalInterFontProvider;
use App\Filament\Support\Palette;
use App\Filament\Support\Shell;
use App\Http\Controllers\AdminPaymentProofController;
use App\Http\Middleware\AccountGate;
use App\Http\Middleware\AuthenticateStaff;
use App\Http\Middleware\EnsureStaffTwoFactor;
use App\Livewire\Shell\Sidebar;
use App\Livewire\Shell\Topbar;
use Closure;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The admin panel (/admin, since v1.6, P2.1): the platform's side, for staff only (App\Services\Staff\StaffAccess).
 * Customers, plans, upgrade requests, usage and costs, provider keys and the server's settings. It has no sign-in of
 * its own: staff sign in to the app (/app) and switch from the avatar menu. Its accent is orange with an ADMIN label,
 * so nobody mistakes it for their own app. It shows metadata and usage only, never anyone's recordings.
 */
class AdminPanelProvider extends PanelProvider
{
    /**
     * @param  Closure(): View  $view
     * @return Closure(): (View|string)
     */
    public static function here(Closure $view): Closure
    {
        return fn (): View|string => Filament::getCurrentPanel()?->getId() === 'admin' ? $view() : '';
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('admin')
            ->path('admin')
            ->brandName('EchoLedger admin')
            ->brandLogo(fn (): View => view('filament.admin.brand'))
            ->brandLogoHeight('1.5rem')
            ->favicon(asset('favicon.svg'))
            ->renderHook(PanelsRenderHook::FOOTER, self::here(fn (): View => view('filament.legal-links')))
            ->renderHook(PanelsRenderHook::PAGE_START, self::here(fn (): View => view('filament.announcement')))
            ->renderHook(PanelsRenderHook::BODY_END, self::here(fn (): View => view('filament.offline-banner')))
            // Since v1.6 (P4): the app's frame (sidebar beside the top bar), with this panel's own navigation.
            ->topbar(false)
            ->sidebarLivewireComponent(Sidebar::class)
            ->topbarLivewireComponent(Topbar::class)
            // (The top bar, the shell's script and Help & shortcuts: registered once for both panels, AppPanelProvider::boot.)
            ->userMenuItems([
                Action::make('openApp')->label('Back to the app')->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->url(fn (): string => Filament::getPanel('app')->getUrl() ?? '/app'),
                Action::make('account')->label('Account')->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (): string => Filament::getPanel('app')->getProfileUrl() ?? '/app/profile'),
                'logout' => fn (Action $action): Action => $action->label('Sign out'),
            ])
            ->defaultThemeMode(ThemeMode::System)
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->colors([
                'primary' => Palette::ORANGE,
                'gray' => Palette::SLATE,
                'danger' => Palette::RED,
                'warning' => Palette::AMBER,
                'success' => Palette::GREEN,
                'info' => Palette::TEAL,
            ])
            ->font('Inter', provider: LocalInterFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth(fn (): string => Shell::sidebarWidth())
            ->collapsedSidebarWidth(Shell::RAIL_WIDTH.'px')
            ->navigationGroups([
                NavigationGroup::make('Customers'),
                NavigationGroup::make('Plans'),
                NavigationGroup::make('Usage'),
                NavigationGroup::make('System'),
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
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
            // Since v1.7: payment receipts for staff who answer upgrade requests (the controller checks the permission).
            ->authenticatedRoutes(function (): void {
                Route::get('upgrade-requests/proofs/{proof}/{variant}', AdminPaymentProofController::class)
                    ->whereUlid('proof')->whereIn('variant', ['thumb', 'full'])->name('upgrade-proofs');
            })
            ->authMiddleware([
                AuthenticateStaff::class,
                // Since v1.6 (P2.5): every member of staff has two-step sign-in on.
                EnsureStaffTwoFactor::class,
                // A suspended account is signed out; one being deleted reaches only "Keep my account" in the app.
                AccountGate::class,
            ], isPersistent: true);
    }
}
