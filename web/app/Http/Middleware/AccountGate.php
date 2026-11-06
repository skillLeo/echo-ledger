<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Pages\AccountBeingDeleted;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Console pages for an account in its 7-day deletion grace (since v1.5): "disabled for sign-in except to cancel", so
 * every page leads to "Your account is being deleted" with "Keep my account". Signing out and the email confirmation
 * stay open.
 */
final class AccountGate
{
    /** Routes an account being deleted still reaches. */
    private const OPEN = ['filament.admin.auth.logout', 'filament.admin.auth.email-verification.prompt', 'filament.admin.auth.email-verification.verify'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $route = (string) $request->route()?->getName();
        if (! $user instanceof User || ! $user->isBeingDeleted() || in_array($route, self::OPEN, true) || $route === AccountBeingDeleted::getRouteName()) {
            return $next($request);
        }
        abort_unless($request->isMethod('GET') && ! $request->hasHeader('X-Livewire'), 403, 'This account is being deleted.');

        return redirect()->to(AccountBeingDeleted::getUrl());
    }
}
