<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile, the anti-bot check of the web sign-up (since v1.6, P1.5): on once both keys are saved in API
 * Credentials → Turnstile, skipped without them. Since v1.6 (P2.7) the admin panel's Sign-up page switches it off
 * and on (`signup.turnstile`, on by default); on works only once the keys are there. The browser's answer is checked
 * with Cloudflare's siteverify; the secret never leaves the server and nothing of the answer is logged.
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public const SETTING = 'signup.turnstile';

    public function __construct(private readonly CredentialVault $vault, private readonly SettingsRepository $settings) {}

    /** Both keys are saved (API Credentials → Turnstile). */
    public function configured(): bool
    {
        return $this->vault->has('turnstile', 'site_key') && $this->vault->has('turnstile', 'secret_key');
    }

    /** The admin panel's switch (Sign-up → Anti-bot check); on unless staff turned it off. */
    public function switchedOn(): bool
    {
        return $this->settings->get(self::SETTING) !== false;
    }

    /** The check runs: its keys are saved and the switch is on. */
    public function active(): bool
    {
        return $this->configured() && $this->switchedOn();
    }

    public function siteKey(): ?string
    {
        return $this->active() ? $this->vault->get('turnstile', 'site_key') : null;
    }

    /** Whether Cloudflare accepts [$token] for this site; false when it cannot be asked. */
    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->active()) {
            return true;
        }
        $token = trim((string) $token);
        if ($token === '' || strlen($token) > 2048) {
            return false;
        }
        try {
            $answer = Http::asForm()->timeout(10)->post(self::VERIFY_URL, array_filter([
                'secret' => $this->vault->get('turnstile', 'secret_key'),
                'response' => $token,
                'remoteip' => $ip,
            ]));

            return $answer->successful() && $answer->json('success') === true;
        } catch (Throwable) {
            return false;
        }
    }
}
