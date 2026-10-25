<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Device;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\User;

/**
 * The first-login checklist of the console dashboard (plan B19): what a new super admin still has to set up. It goes
 * away by itself once every step is done, or when the admin dismisses it.
 */
final class OnboardingChecklist
{
    public const DISMISSED_KEY = 'onboarding.dismissed';

    /** Step ids in the order they are shown. */
    public const STEPS = ['two_factor', 'provider_key', 'cert_pins', 'first_device', 'retention'];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly CredentialVault $vault,
    ) {}

    /**
     * Which steps are done.
     *
     * @return array<string, bool>
     */
    public function status(User $user): array
    {
        return [
            'two_factor' => filled($user->app_authentication_secret) || Passkey::query()->where('user_id', $user->id)->exists(),
            'provider_key' => $this->hasProviderKey(),
            'cert_pins' => $this->settings->pins() !== [],
            'first_device' => Device::query()->exists(),
            // A chosen retention is one that was saved; the default alone is not a choice.
            'retention' => Setting::query()->where('key', 'retention_days')->exists(),
        ];
    }

    public function complete(User $user): bool
    {
        return ! in_array(false, $this->status($user), true);
    }

    public function dismissed(): bool
    {
        return $this->settings->get(self::DISMISSED_KEY) === true;
    }

    public function dismiss(): void
    {
        $this->settings->set(self::DISMISSED_KEY, true);
    }

    /** Whether the card is shown: a super admin, not dismissed, something left to do. */
    public function visibleTo(?User $user): bool
    {
        return $user instanceof User && $user->is_super_admin && ! $this->dismissed() && ! $this->complete($user);
    }

    private function hasProviderKey(): bool
    {
        foreach (CredentialVault::FIELDS as $provider => $fields) {
            foreach (['api_key', 'bearer_token'] as $key) {
                if (array_key_exists($key, $fields) && $this->vault->has($provider, $key)) {
                    return true;
                }
            }
        }

        return false;
    }
}
