<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Filament\Support\Notice as Notification;
use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * "Forgot password?" (since v1.5): Filament's page with our own queued email, and one answer whether or not the
 * address has an account, so the page cannot be used to find out who has one.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();
        Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            ['email' => Str::lower(trim((string) $data['email']))],
            function (CanResetPassword $user, #[\SensitiveParameter] string $token): void {
                if ($user instanceof User && $user->canAccessPanel(Filament::getPanel('app'))) {
                    $user->sendPasswordResetNotification($token);
                }
            },
        );

        Notification::make()->title('Check your email')
            ->body('If an account uses that address, a link to choose a new password is on its way.')
            ->success()->send();
        $this->form->fill();
    }
}
