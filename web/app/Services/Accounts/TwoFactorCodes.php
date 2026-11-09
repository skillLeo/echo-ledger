<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Facades\Filament;
use RuntimeException;

/**
 * Checks a two-factor code outside the console's sign-in page (the apps' sign-in, Google, deleting the account): a
 * 6-digit code from the authenticator app, used at most once, or one of the account's recovery codes, which is then
 * used up. The same provider and secret as the console's two-step sign-in.
 */
final class TwoFactorCodes
{
    public function verify(User $user, #[\SensitiveParameter] string $code): bool
    {
        if (! $user->hasTwoFactor()) {
            return false;
        }
        $provider = $this->provider();
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code) === 1) {
            return $provider->verifyCode($code, $provider->getSecret($user), shouldPreventCodeReuse: true);
        }

        return $provider->isRecoverable() && $code !== '' && strlen($code) <= 64 && $provider->verifyRecoveryCode($code, $user);
    }

    private function provider(): AppAuthentication
    {
        foreach (Filament::getPanel('app')->getMultiFactorAuthenticationProviders() as $provider) {
            if ($provider instanceof AppAuthentication) {
                return $provider;
            }
        }

        throw new RuntimeException('Two-step sign-in is not set up on the console.');
    }
}
