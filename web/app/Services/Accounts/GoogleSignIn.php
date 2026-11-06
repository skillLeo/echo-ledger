<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Models\User;
use App\Services\CredentialVault;
use Illuminate\Contracts\Session\Session;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;

/**
 * "Sign in with Google" (since v1.5, laravel/socialite): the client id and secret come from API Credentials → Google
 * sign-in (encrypted like the other credentials), the redirect URI is this server's /auth/google/callback. Google's
 * answer counts only for an address Google says it verified.
 */
final class GoogleSignIn
{
    /** Minutes a "Confirm with Google" on /account/delete stands in for the password. */
    public const CONFIRMED_MINUTES = 5;

    private const CONFIRMED = 'google.confirmed';

    public function __construct(private readonly CredentialVault $vault) {}

    /**
     * The person just chose this account's linked Google account again ("Confirm with Google" on /account/delete):
     * an account made with Google has a password nobody ever saw, so this is how it confirms it is them.
     */
    public static function markConfirmed(Session $session, User $user): void
    {
        $session->put(self::CONFIRMED, ['user_id' => $user->id, 'google_id' => $user->google_id, 'until' => now()->addMinutes(self::CONFIRMED_MINUTES)->getTimestamp()]);
    }

    /** Whether [$user] confirmed with its linked Google account in this session in the last few minutes. */
    public static function confirmed(Session $session, User $user): bool
    {
        $state = $session->get(self::CONFIRMED);

        return is_array($state) && (int) ($state['user_id'] ?? 0) === $user->id && $user->google_id !== null
            && hash_equals($user->google_id, (string) ($state['google_id'] ?? '')) && (int) ($state['until'] ?? 0) >= now()->getTimestamp();
    }

    public static function forgetConfirmed(Session $session): void
    {
        $session->forget(self::CONFIRMED);
    }

    public function configured(): bool
    {
        return $this->vault->has('google', 'client_id') && $this->vault->has('google', 'client_secret');
    }

    public static function redirectUri(): string
    {
        return route('auth.google.callback');
    }

    public function provider(): Provider
    {
        config(['services.google' => [
            'client_id' => $this->vault->get('google', 'client_id'),
            'client_secret' => $this->vault->get('google', 'client_secret'),
            'redirect' => self::redirectUri(),
        ]]);
        $provider = Socialite::driver('google');
        if ($provider instanceof AbstractProvider) {
            $provider->redirectUrl(self::redirectUri())->scopes(['openid', 'email', 'profile']);
        }

        return $provider;
    }

    /** @return array{id: string, email: string, name: string, verified: bool} */
    public function profile(SocialiteUser $user): array
    {
        $raw = method_exists($user, 'getRaw') ? (array) $user->getRaw() : [];
        $verified = $raw['email_verified'] ?? $raw['verified_email'] ?? false;

        return [
            'id' => (string) $user->getId(),
            'email' => mb_strtolower(trim((string) $user->getEmail())),
            'name' => trim((string) ($user->getName() ?? '')),
            'verified' => $verified === true || $verified === 'true' || $verified === 1,
        ];
    }
}
