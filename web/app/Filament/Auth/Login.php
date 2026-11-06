<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Services\Accounts\SignInGuard;
use App\Services\Accounts\SignUp;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The console sign-in page; "sign up" is offered only while anyone may make an account (since v1.5). Filament limits
 * attempts per address of the visitor; since v1.5 five failures for one email lock it here too, as in the apps.
 */
class Login extends BaseLogin
{
    public function authenticate(): ?LoginResponse
    {
        $email = is_string($this->data['email'] ?? null) ? $this->data['email'] : '';
        $guard = app(SignInGuard::class);
        $locked = $guard->lockedFor($email);
        if ($locked > 0) {
            $this->getRateLimitedNotification(new TooManyRequestsException(static::class, 'authenticate', (string) request()->ip(), $locked))?->send();

            return null;
        }
        $response = parent::authenticate();
        if ($response !== null) {
            $guard->clear($email);
        }

        return $response;
    }

    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication) || app(SignUp::class)->mode() === SignUp::ANYONE) {
            return parent::getSubheading();
        }

        return null;
    }
}
