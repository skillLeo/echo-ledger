<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The app's addresses before v1.6 (P2.1): the console lived at /admin, which is now the staff panel. Every old page
 * of the app (bookmarks, links in emails sent before) goes to the same page under /app with its query. A signed link
 * (an email confirmation, a password reset) whose signature is good is signed again for its new address, so a link
 * sent just before the update still works; a bad or expired one goes on as it came and the app refuses it as before.
 */
final class LegacyConsoleRedirect
{
    /** The app's pages that lived under /admin; the staff panel never uses these names. */
    public const PATHS = ['account-deletion', 'activity', 'clients', 'devices', 'email-change-verification', 'email-verification',
        'get-the-apps', 'login', 'password-reset', 'profile', 'recently-deleted', 'register', 'request-links', 'search', 'security',
        'sessions', 'shared-with-me', 'shares', 'upload', 'usage', 'welcome', 'workspace'];

    public function __invoke(Request $request, string $path): RedirectResponse
    {
        $target = url('/app/'.$path);
        $query = (string) $request->server->get('QUERY_STRING', '');
        if ($request->query->has('signature') && $request->hasValidSignature()) {
            $unsigned = collect(explode('&', $query))->reject(fn (string $part): bool => explode('=', $part, 2)[0] === 'signature')->join('&');
            $base = rtrim($target.'?'.$unsigned, '?');
            $signature = hash_hmac('sha256', $base, (string) config('app.key'));

            return redirect()->to($base.($unsigned === '' ? '?' : '&').'signature='.$signature);
        }

        return redirect()->to(rtrim($target.'?'.$query, '?'));
    }
}
