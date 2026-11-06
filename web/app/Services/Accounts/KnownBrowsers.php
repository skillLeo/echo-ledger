<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Models\KnownBrowser;
use App\Models\User;
use App\Services\WebSessions;
use App\Support\ClientCountry;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Throwable;

/**
 * "A console sign-in from a new browser sends the account an email" (since v1.5). Each browser an account signs in
 * from gets a random token in a long-lived cookie (one cookie per account, so several accounts can share a browser);
 * a sign-in without a token the account knows is a new browser. The account's very first browser is not news: it is
 * where the account was made, or the first sign-in after this feature arrived.
 */
final class KnownBrowsers
{
    /** Two years, in minutes. */
    private const COOKIE_MINUTES = 60 * 24 * 730;

    public function __construct(private readonly LoginAlerts $alerts) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }
        try {
            $this->seen($event->user);
        } catch (Throwable $e) {
            report($e); // never in the way of a sign-in
        }
    }

    private function seen(User $user): void
    {
        $request = request();
        $name = self::cookieName($user);
        $token = $request->cookie($name);
        $country = ClientCountry::of($request);
        $known = is_string($token) && $token !== ''
            ? KnownBrowser::query()->where('user_id', $user->id)->where('token_hash', hash('sha256', $token))->first()
            : null;
        if ($known instanceof KnownBrowser) {
            $known->forceFill(['ip' => $request->ip(), 'country' => $country, 'last_used_at' => now()])->save();

            return;
        }
        $first = ! KnownBrowser::query()->where('user_id', $user->id)->exists();
        $token = Str::random(48);
        $label = WebSessions::describe((string) $request->userAgent());
        KnownBrowser::query()->create(['user_id' => $user->id, 'token_hash' => hash('sha256', $token), 'label' => $label, 'ip' => $request->ip(), 'country' => $country, 'last_used_at' => now()]);
        Cookie::queue(Cookie::make($name, $token, self::COOKIE_MINUTES, httpOnly: true, sameSite: 'lax'));
        if (! $first) {
            $this->alerts->browserSignedIn($user, $label, $request->ip(), $country);
        }
    }

    public static function cookieName(User $user): string
    {
        return 'echoledger_browser_'.substr(hash_hmac('sha256', 'browser:'.$user->id, (string) config('app.key')), 0, 12);
    }
}
