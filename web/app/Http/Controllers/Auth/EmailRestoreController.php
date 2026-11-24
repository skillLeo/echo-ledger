<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\EmailChange;
use App\Support\Api\AccountRules;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The original address's "Put my address back" link (since the security review of 2.0.0-l, EmailChange::tellOldAddress()):
 * signed for the restore window's 7 days, no sign-in needed, as whoever changed the address may hold the account. The
 * link only shows a page (mail scanners open links); its button puts the address back and cuts what they may hold.
 */
final class EmailRestoreController extends Controller
{
    private const GONE = 'The address was put back already, or the 7 days are over. Sign in, or use "Forgot password?" on the sign-in page.';

    public function show(Request $request, int $user): Response
    {
        $account = User::query()->find($user);
        $window = $account instanceof User ? EmailChange::window($account) : null;
        if ($window === null || ! hash_equals($window['nonce'], (string) $request->query('n'))) {
            return $this->message(410, 'This link no longer works', self::GONE);
        }

        return response()->view('auth.email-restore', ['address' => AccountRules::maskEmail($window['to']), 'action' => $request->fullUrl()]);
    }

    public function restore(Request $request, int $user, EmailChange $change): Response
    {
        $account = User::query()->find($user);
        $window = $account instanceof User ? EmailChange::window($account) : null;
        if (! $account instanceof User || $window === null || ! $change->restore($account, (string) $request->query('n'))) {
            return $this->message(410, 'This link no longer works', self::GONE);
        }

        return $this->message(200, 'Your address is back', 'The account uses '.AccountRules::maskEmail($window['to']).' again and is signed out everywhere. Its password no longer works: open the email we just sent there to choose a new one.');
    }

    private function message(int $status, string $title, string $message): Response
    {
        return response()->view('auth.message', ['title' => $title, 'message' => $message, 'back' => Filament::getPanel('app')->getLoginUrl(), 'backLabel' => 'Go to sign in'], $status);
    }
}
