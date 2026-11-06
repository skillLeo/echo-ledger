<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\CredentialVault;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Http\RedirectResponse;
use Laravel\Socialite\Contracts\Factory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as GoogleUser;

/** Gives the user an authenticator secret and recovery codes; returns the secret and the codes in plain text. */
function accountsEnrolTotp(User $user): array
{
    $provider = AppAuthentication::make();
    $secret = $provider->generateSecret();
    $codes = ['alpha-recovery-0001', 'bravo-recovery-0002'];
    $provider->saveSecret($user, $secret);
    $provider->saveRecoveryCodes($user, $codes);

    return [$secret, $codes];
}

function accountsTotp(User $user, string $secret): string
{
    return AppAuthentication::make()->getCurrentCode($user, $secret);
}

/** API Credentials → Google sign-in filled in, and Google answering as the given person (no request leaves the test). */
function fakeGoogle(string $id = 'google-sub-1', string $email = 'person@example.test', string $name = 'Person Example', bool $verified = true): void
{
    app(CredentialVault::class)->put('google', 'client_id', '1234-test.apps.googleusercontent.com');
    app(CredentialVault::class)->put('google', 'client_secret', 'GOCSPX-test-secret-value');
    $user = (new GoogleUser)->setRaw(['sub' => $id, 'email' => $email, 'email_verified' => $verified])->map(['id' => $id, 'email' => $email, 'name' => $name]);
    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('redirectUrl')->andReturnSelf();
    $provider->shouldReceive('scopes')->andReturnSelf();
    $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth?client_id=1234-test'));
    $provider->shouldReceive('user')->andReturn($user);
    $factory = Mockery::mock(Factory::class);
    $factory->shouldReceive('driver')->with('google')->andReturn($provider);
    Socialite::swap($factory);
}
