<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Staff\StaffAccess;
use Closure;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin panel's sign-in check (since v1.6, P2.1): there is one sign-in, the app's; a guest is sent there and comes
 * back afterwards. Someone signed in who is not staff gets 403 on every page and action of /admin, except the bare
 * /admin, which was everybody's console home before v1.6: it goes to /app and shows nothing of the admin panel.
 */
final class AuthenticateStaff extends Authenticate
{
    /**
     * @param  Request  $request
     * @param  string  ...$guards
     */
    public function handle($request, Closure $next, ...$guards): Response
    {
        $user = Filament::auth()->user();
        if ($user instanceof User && ! StaffAccess::isStaff($user) && $request->isMethod('GET')
            && ! $request->hasHeader('X-Livewire') && trim($request->path(), '/') === 'admin') {
            return redirect()->to(Filament::getPanel('app')->getUrl() ?? '/app');
        }

        return parent::handle($request, $next, ...$guards);
    }

    /** @param  Request  $request */
    protected function redirectTo($request): ?string
    {
        return Filament::getPanel('app')->getLoginUrl();
    }
}
