<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\AccountBeingDeleted;
use App\Filament\Pages\RecordingConsentNotice;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console pages for an account in its 7-day deletion grace (since v1.5): "disabled for sign-in except to cancel", so
 * every page leads to "Your account is being deleted" with "Keep my account". A new account sees the
 * recording-consent notice once before anything else. Signing out and the email confirmation stay open.
 */
final class AccountGate
{
    /** Routes an account being deleted still reaches. */
    private const OPEN = ['filament.admin.auth.logout', 'filament.admin.auth.email-verification.prompt', 'filament.admin.auth.email-verification.verify'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = (string) $request->route()?->getName();
        if (! $user instanceof User || in_array($route, self::OPEN, true)) {
            return $next($request);
        }
        if ($user->isBeingDeleted() && $route !== AccountBeingDeleted::getRouteName()) {
            abort_unless($this->isPage($request), 403, 'This account is being deleted.');

            return redirect()->to(AccountBeingDeleted::getUrl());
        }
        if (! $user->isBeingDeleted() && $user->consent_seen_at === null && $route !== RecordingConsentNotice::getRouteName() && $this->isPage($request)) {
            return redirect()->to(RecordingConsentNotice::getUrl());
        }

        return $next($request);
    }

    private function isPage(Request $request): bool
    {
        return $request->isMethod('GET') && ! $request->hasHeader('X-Livewire');
    }
}
