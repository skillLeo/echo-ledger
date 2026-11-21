<?php

declare(strict_types=1);

namespace App\Mail;

use App\Support\Brand;

/**
 * A way to sign in was added from an app (since 2.0.0-l): two-step sign-in turned on, or a passkey added. The account
 * holder hears of it, so a stolen device cannot add one quietly. Names the change and the device, never a secret.
 */
final class SecurityChangeMail extends AccountMail
{
    public const TWO_FACTOR_ON = 'two_factor_on';

    public const PASSKEY_ADDED = 'passkey_added';

    public function __construct(
        public readonly string $change,
        public readonly string $device,
        public readonly string $securityUrl,
        public readonly ?string $passkey = null,
    ) {}

    protected function subjectLine(): string
    {
        return $this->change === self::TWO_FACTOR_ON
            ? 'Two-step sign-in is on for your '.Brand::name().' account'
            : 'A passkey was added to your '.Brand::name().' account';
    }

    protected function template(): string
    {
        return 'mail.security-change';
    }

    protected function data(): array
    {
        return [
            'heading' => $this->change === self::TWO_FACTOR_ON ? 'Two-step sign-in is on' : 'A passkey was added',
            'what' => $this->change === self::TWO_FACTOR_ON
                ? 'Two-step sign-in was turned on for your account'
                : 'A passkey'.($this->passkey !== null ? ' named "'.$this->passkey.'"' : '').' was added to your account',
            'device' => $this->device,
            'securityUrl' => $this->securityUrl,
        ];
    }
}
