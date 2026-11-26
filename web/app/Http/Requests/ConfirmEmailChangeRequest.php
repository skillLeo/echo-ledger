<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Accounts\EmailChange;
use Filament\Auth\Http\Requests\EmailChangeVerificationRequest;
use Illuminate\Support\Str;

/**
 * Filament's confirmation link of an email change, with one addition (since the security review of 2.0.0-l): once the
 * address changed, the old one gets the "put it back" link (EmailChange::tellOldAddress()), and "remember me" is
 * renewed, as for a change confirmed by code in an app.
 */
final class ConfirmEmailChangeRequest extends EmailChangeVerificationRequest
{
    public function fulfill(): bool
    {
        $user = $this->user();
        $old = $user instanceof User ? $user->email : null;
        try {
            $new = decrypt($this->route('email'));
        } catch (\Throwable) {
            return false;
        }
        // An address reserved for another account's restore window is nobody's (EmailChange).
        if (is_string($new) && EmailChange::isReserved($new, $user instanceof User ? $user->id : null)) {
            return false;
        }
        $changed = parent::fulfill();
        if ($changed && $user instanceof User && $old !== null && $user->email !== $old) {
            $user->forceFill(['remember_token' => Str::random(60)])->saveQuietly();
            app(EmailChange::class)->tellOldAddress($user, $old);
        }

        return $changed;
    }
}
