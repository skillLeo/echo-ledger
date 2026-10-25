<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use Filament\Auth\MultiFactor\App\AppAuthentication as BaseAppAuthentication;
use Filament\Forms\Components\Field;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Filament's authenticator-app sign-in step with one fix: a recovery code signs in with a single click.
 *
 * Upstream marks the recovery-code field `live(onBlur: true)` and keeps the 6-digit code field required until a
 * recovery code has been sent to the server. Pressing "Confirm sign in" straight after typing the recovery code
 * therefore first sent the blur update, the button was disabled while it ran, and the click was lost. Here the
 * choice "use a recovery code" (already a server round trip) decides which of the two fields is required, so the
 * recovery-code field does not need to be live. Codes are still verified by the upstream rules.
 */
class AppAuthentication extends BaseAppAuthentication
{
    public function getChallengeFormComponents(Authenticatable $user): array
    {
        $components = parent::getChallengeFormComponents($user);
        $isRecoverable = $this->isRecoverable();

        foreach ($components as $component) {
            if (! $component instanceof Field) {
                continue;
            }
            if ($component->getName() === 'code') {
                $component->required(fn (Get $get): bool => (! $isRecoverable) || (! $get('useRecoveryCode')));
            }
            if ($component->getName() === 'recoveryCode') {
                $component->live(condition: false)->required(fn (Get $get): bool => $isRecoverable && (bool) $get('useRecoveryCode'));
            }
        }

        return $components;
    }
}
