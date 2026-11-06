<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Services\CredentialVault;
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
    public function __construct(private readonly CredentialVault $vault) {}

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
