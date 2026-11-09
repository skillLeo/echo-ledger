<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\AppAuthentication;
use App\Filament\Auth\EditProfile;
use App\Filament\Auth\EmailVerificationPrompt;
use App\Filament\Auth\Login;
use App\Filament\Auth\Register;
use App\Filament\Auth\RequestPasswordReset;
use App\Filament\Pages\WorkspaceSettings;
use App\Filament\Support\CommandPalette;
use App\Filament\Support\InitialsAvatarProvider;
use App\Filament\Support\LocalInterFontProvider;
use App\Filament\Support\Palette;
use App\Filament\Support\Shell;
use App\Http\Middleware\AccountGate;
use App\Http\Middleware\EnsureAppAvailable;
use App\Http\Middleware\EnsureWorkspaceMember;
use App\Livewire\ActivityBell;
use App\Livewire\Shell\Sidebar;
use App\Livewire\Shell\Topbar;
use App\Services\Staff\StaffAccess;
use Closure;
use Filament\Actions\Action;
use Filament\Enums\ThemeMode;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Facades\FilamentIcon;
use Filament\Support\Facades\FilamentView;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\View\TablesIconAlias;
use Filament\View\PanelsRenderHook;
use Filament\Widgets\AccountWidget;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Livewire\Livewire;

class AppPanelProvider extends PanelProvider
{
    public function boot(): void
    {
        // Filament's own table buttons and its empty table: outlined at rest, like everything else (docs/DESIGN_SYSTEM.md).
        FilamentIcon::register([
            TablesIconAlias::ACTIONS_FILTER => Heroicon::OutlinedFunnel,
            TablesIconAlias::ACTIONS_COLUMN_MANAGER => Heroicon::OutlinedViewColumns,
            TablesIconAlias::EMPTY_STATE => Heroicon::OutlinedRectangleStack,
        ]);

        // The app shell's top bar, script and Help & shortcuts in both panels (since v1.6, P4). Registered here, once
        // per application, rather than per panel: Filament boots only the first panel a process serves.
        $shell = fn (Closure $view): Closure => fn (): View|string => in_array(Filament::getCurrentPanel()?->getId(), ['app', 'admin'], true) && auth()->check() ? $view() : '';
        FilamentView::registerRenderHook(PanelsRenderHook::CONTENT_BEFORE, $shell(fn (): View => view('filament.shell.topbar-slot')));
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, $shell(fn (): View => view('filament.shell.head')));
        // Since v1.6.1: the notices' behaviour (toasts, banners), on every page of both panels, the sign-in pages too.
        FilamentView::registerRenderHook(PanelsRenderHook::HEAD_END, fn (): View|string => in_array(Filament::getCurrentPanel()?->getId(), ['app', 'admin'], true) ? view('filament.shell.notices') : '');
        FilamentView::registerRenderHook(PanelsRenderHook::BODY_END, $shell(fn (): View => view('filament.shell.help')));
        // The page pattern (P4.4): the section above the title and a one-line subtitle, where a page has neither.
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_HEADER_HEADING_BEFORE, fn (): View|HtmlString|string => self::pageHeader('crumbs'));
        FilamentView::registerRenderHook(PanelsRenderHook::PAGE_HEADER_HEADING_AFTER, fn (): View|HtmlString|string => self::pageHeader('subtitle'));
    }

    /** The trail or the subtitle the app's page draws no copy of itself (the page being rendered decides). */
    private static function pageHeader(string $part): View|HtmlString|string
    {
        $page = Livewire::current();
        if (Filament::getCurrentPanel()?->getId() !== 'app' || ! $page instanceof Page) {
            return '';
        }
        if ($part === 'crumbs') {
            return $page->getBreadcrumbs() === [] ? view('filament.shell.page-crumbs', ['crumbs' => Shell::crumbs($page::class, (string) $page->getTitle())]) : '';
        }
        $subtitle = blank($page->getSubheading()) ? Shell::subtitle($page::class) : null;

        return $subtitle !== null ? new HtmlString('<p class="fi-header-subheading el-subtitle">'.e($subtitle).'</p>') : '';
    }

    /**
     * A render hook of this panel only: Filament registers hooks for the whole process, so where the admin panel was
     * served too (tests, queued renders) the app's sidebar, bottom bar and notices stay out of it.
     *
     * @param  Closure(): View  $view
     * @return Closure(): (View|string)
     */
    public static function here(Closure $view): Closure
    {
        return fn (): View|string => Filament::getCurrentPanel()?->getId() === 'app' ? $view() : '';
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('app')
            ->brandName('EchoLedger')
            // The brand mark (since v1.5, docs/design/brand) next to the name, and as the tab's icon.
            ->brandLogo(fn (): View => view('filament.brand'))
            ->brandLogoHeight('1.5rem')
            ->favicon(asset('favicon.svg'))
            ->login(Login::class)
            // Sign-up (since v1.5): the page exists, but answers 404 until Settings → "Who can sign up" opens it.
            ->registration(Register::class)
            ->passwordReset(RequestPasswordReset::class)
            // The activity centre (B18): the bell shows transcript, upload, device and budget notifications.
            // Since v1.6 (P6) its number counts only what needs you, "9+" above nine (App\Livewire\ActivityBell).
            ->databaseNotifications(livewireComponent: ActivityBell::class)
            ->databaseNotificationsPolling('30s')
            // Passkeys (B16) and Google (since v1.5) are under the sign-in form itself (Login::content, since v1.6.2).
            // Since v1.5: the Terms and the Privacy Policy under every page and the sign-in page (both layouts have this hook).
            ->renderHook(PanelsRenderHook::FOOTER, self::here(fn (): View => view('filament.legal-links')))
            // Since v1.5: members and viewers while the console is closed to them are pointed to the apps.
            ->renderHook(PanelsRenderHook::PAGE_START, self::here(fn (): View => view('filament.use-the-apps')))
            // Since v1.6 (P2.7): the platform's announcement for everyone.
            ->renderHook(PanelsRenderHook::PAGE_START, self::here(fn (): View => view('filament.announcement')))
            // Since v1.6 (P4): the app shell, the desktop app's frame. The sidebar (brand, Find a page, Home · Library ·
            // Projects · Activity · Shared · Upload, then Help, Account, Collapse) and, beside it, the top bar (the
            // workspace, search and commands, +, the bell, help, the avatar menu). Its script runs before Alpine.
            ->topbar(false)
            ->sidebarLivewireComponent(Sidebar::class)
            ->topbarLivewireComponent(Topbar::class)
            // C6: a quiet notice while the browser is offline.
            ->renderHook(PanelsRenderHook::BODY_END, self::here(fn (): View => view('filament.offline-banner')))
            ->profile(EditProfile::class, isSimple: false)
            // The avatar menu (since v1.6, P4.3): Account, Plan & usage, the workspace for owners and admins, the admin
            // panel for staff (since P2.1; nobody else sees it), the theme and Sign out.
            ->userMenuItems([
                'profile' => fn (Action $action): Action => $action->label('Account')->icon(Heroicon::OutlinedUserCircle),
                Action::make('planAndUsage')->label('Plan & usage')->icon(Heroicon::OutlinedSparkles)
                    ->url(fn (): string => (Filament::getPanel('app')->getProfileUrl() ?? '/app/profile').'?tab=plan'),
                Action::make('workspace')->label('Workspace')->icon(Heroicon::OutlinedUserGroup)
                    ->visible(fn (): bool => WorkspaceSettings::canAccess())
                    ->url(fn (): string => WorkspaceSettings::getUrl(panel: 'app')),
                Action::make('adminPanel')->label('Admin panel')->icon(Heroicon::OutlinedShieldCheck)
                    ->visible(fn (): bool => StaffAccess::isStaff())
                    ->url(fn (): string => Filament::getPanel('admin')->getUrl() ?? '/app'),
                'logout' => fn (Action $action): Action => $action->label('Sign out'),
            ])
            // Confirming one's email: required since v1.5 Phase 4 (an unconfirmed account sees only "Confirm your
            // email", and the apps' sign-in answers 403 email_not_verified); people-only share links need it too.
            ->emailVerification(EmailVerificationPrompt::class, isRequired: true)
            // A new address takes effect once the link sent to it is opened: nobody takes an address they do not read.
            ->emailChangeVerification()
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
            ->renderHook(PanelsRenderHook::BODY_END, self::here(fn (): View => view('filament.bottom-nav')))
            // Self-hosted font and initials avatars: the console asks no third-party host for anything.
            ->font('Inter', provider: LocalInterFontProvider::class)
            ->defaultAvatarProvider(InitialsAvatarProvider::class)
            // Ctrl / Cmd + K: pages, recordings (title and transcript) and projects.
            ->globalSearch(CommandPalette::class)
            ->globalSearchKeyBindings(['mod+k'])
            ->globalSearchFieldSuffix(fn (): string => Shell::paletteKeys())
            // 240 px by default, 200–320 by dragging its edge, a 64 px rail when folded: the person's own (P4.1).
            ->sidebarCollapsibleOnDesktop()
            ->sidebarWidth(fn (): string => Shell::sidebarWidth())
            ->collapsedSidebarWidth(Shell::RAIL_WIDTH.'px')
            // The everyday pages sit ungrouped at the top, like the phone's tabs; the rest below.
            ->navigationGroups([
                NavigationGroup::make('More'),
                NavigationGroup::make('System'),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
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
                // Since v1.5: an account being deleted reaches only "Keep my account".
                AccountGate::class,
                // Since v1.6 (P2.7): during maintenance customers see the pause page.
                EnsureAppAvailable::class,
            ], isPersistent: true);
    }
}
