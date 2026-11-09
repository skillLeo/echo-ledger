<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Support\Notice as Notification;
use App\Models\User;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two-step sign-in for every member of staff before the admin panel opens (since v1.6, P2.5). With it on, every way
 * into the account (password, email code, Google) already asked for the code, so having it on is the check. Without
 * it a page goes to the profile, where it is turned on; anything else is refused.
 */
final class EnsureStaffTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user instanceof User && ! $user->hasTwoFactor()) {
            abort_unless($request->isMethod('GET') && ! $request->hasHeader('X-Livewire'), 403, 'Turn on two-step sign-in to open the admin panel.');
            // Since v1.6.1 the notice opens the place it is about: Account → Password & sign-in methods, at two-step sign-in.
            $twoStep = (Filament::getPanel('app')->getProfileUrl() ?? '/app/profile').'?tab=sign-in#two-step-sign-in';
            Notification::make()->title('Turn on two-step sign-in to open the admin panel')
                ->body('Every member of staff needs it. Set it up under Two-step sign-in below, then open the admin panel again.')
                ->url($twoStep)->warning()->persistent()->send();

            return redirect()->to($twoStep);
        }

        return $next($request);
    }
}
