<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Services\Accounts\SignUp;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Support\Htmlable;

/** The console sign-in page; "sign up" is offered only while anyone may make an account (since v1.5). */
class Login extends BaseLogin
{
    public function getSubheading(): string|Htmlable|null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication) || app(SignUp::class)->mode() === SignUp::ANYONE) {
            return parent::getSubheading();
        }

        return null;
    }
}
