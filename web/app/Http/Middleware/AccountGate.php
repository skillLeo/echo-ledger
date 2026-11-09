<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\AccountBeingDeleted;
use App\Filament\Pages\RecordingConsentNotice;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console pages for an account in its 7-day deletion grace (since v1.5): "disabled for sign-in except to cancel", so
 * every page leads to "Your account is being deleted" with "Keep my account". A new account sees the
 * recording-consent notice once before anything else. Signing out and the email confirmation stay open. Since v1.6 a
 * suspended account is signed out on its next page.
 */
final class AccountGate
{
    /** Routes an account being deleted still reaches. */
    private const OPEN = ['filament.app.auth.logout', 'filament.admin.auth.logout', 'filament.app.auth.email-verification.prompt', 'filament.app.auth.email-verification.verify'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = (string) $request->route()?->getName();
        if (! $user instanceof User) {
            return $next($request);
        }
        // Since v1.6: a suspended account is signed out of the console at once, with the reason on the sign-in page.
        if ($user->isSuspended()) {
            Filament::auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            $request->session()->flash('account.suspended', true);
            abort_unless($this->isPage($request), 403, 'This account is suspended.');

            return redirect()->to(Filament::getPanel('app')->getLoginUrl() ?? '/app/login');
        }
        if (in_array($route, self::OPEN, true)) {
            return $next($request);
        }
        // The pages it leads to are the app's, also from the admin panel (since v1.6).
        $app = Filament::getPanel('app');
        if ($user->isBeingDeleted() && $route !== AccountBeingDeleted::getRouteName($app)) {
            abort_unless($this->isPage($request), 403, 'This account is being deleted.');

            return redirect()->to(AccountBeingDeleted::getUrl(panel: 'app'));
        }
        if (! $user->isBeingDeleted() && $user->consent_seen_at === null && $route !== RecordingConsentNotice::getRouteName($app) && $this->isPage($request)) {
            return redirect()->to(RecordingConsentNotice::getUrl(panel: 'app'));
        }

        return $next($request);
    }

    private function isPage(Request $request): bool
    {
        return $request->isMethod('GET') && ! $request->hasHeader('X-Livewire');
    }
}
