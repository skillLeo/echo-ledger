<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use App\Services\Accounts\EmailChange;
use Filament\Auth\Http\Requests\BlockEmailChangeVerificationRequest;

/**
 * Filament's "Block" link in the old address's notice of an email change, with one addition (since 2.0.0-l): a change
 * asked for in an app is also ended there and every device and browser is signed out (EmailChange::blocked()).
 */
final class BlockEmailChangeRequest extends BlockEmailChangeVerificationRequest
{
    public function fulfill(): bool
    {
        $blocked = parent::fulfill();
        $user = $this->user();
        if ($blocked && $user instanceof User) {
            app(EmailChange::class)->blocked($user, (string) $this->query('verificationSignature'));
        }

        return $blocked;
    }
}
