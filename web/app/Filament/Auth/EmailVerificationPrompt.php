<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use Filament\Auth\Pages\EmailVerification\EmailVerificationPrompt as BasePrompt;
use Illuminate\Contracts\Auth\MustVerifyEmail;

/** "Confirm your email" (required since v1.5): "send it again" sends our own queued email. */
class EmailVerificationPrompt extends BasePrompt
{
    protected function sendEmailVerificationNotification(MustVerifyEmail $user): void
    {
        if ($user instanceof User && ! $user->hasVerifiedEmail()) {
            $user->sendEmailVerificationNotification();
        }
    }
}
