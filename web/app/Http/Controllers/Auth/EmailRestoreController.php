<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Accounts\EmailChange;
use App\Support\Api\AccountRules;
use Filament\Facades\Filament;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Symfony\Component\HttpFoundation\Response;

/**
 * The old address's "Put my address back" link (since the security review of 2.0.0-l, EmailChange::tellOldAddress()):
 * signed, 7 days, no sign-in needed, as whoever changed the address may hold the account. The link only shows a page
 * (mail scanners open links); its button puts the address back and signs out everywhere.
 */
final class EmailRestoreController extends Controller
{
    public function show(Request $request, int $user): Response
    {
        $target = $this->target($request, $user);
        if ($target === null) {
            return $this->message(410, 'This link no longer works', 'The address was changed again or put back already. Sign in, or use "Forgot password?" on the sign-in page.');
        }

        return response()->view('auth.email-restore', ['address' => AccountRules::maskEmail($target[1]), 'action' => $request->fullUrl()]);
    }

    public function restore(Request $request, int $user, EmailChange $change): Response
    {
        $target = $this->target($request, $user);
        if ($target === null || ! $change->restore($target[0], $target[1], (string) $request->query('from'))) {
            return $this->message(410, 'This link no longer works', 'The address was changed again or put back already. Sign in, or use "Forgot password?" on the sign-in page.');
        }

        return $this->message(200, 'Your address is back', 'The account uses '.AccountRules::maskEmail($target[1]).' again and is signed out everywhere. Choose a new password now with "Forgot password?" on the sign-in page.');
    }

    /** @return array{0: User, 1: string}|null the account and the address to put back */
    private function target(Request $request, int $user): ?array
    {
        $account = User::query()->find($user);
        try {
            $old = Crypt::decryptString((string) $request->query('to'));
        } catch (DecryptException) {
            return null;
        }
        if (! $account instanceof User || ! hash_equals(EmailChange::addressHash($account->email), (string) $request->query('from'))) {
            return null;
        }

        return [$account, $old];
    }

    private function message(int $status, string $title, string $message): Response
    {
        return response()->view('auth.message', ['title' => $title, 'message' => $message, 'back' => Filament::getPanel('app')->getLoginUrl(), 'backLabel' => 'Go to sign in'], $status);
    }
}
