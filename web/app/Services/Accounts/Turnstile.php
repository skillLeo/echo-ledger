<?php

declare(strict_types=1);

namespace App\Services\Accounts;

use App\Services\CredentialVault;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cloudflare Turnstile, the anti-bot check of the web sign-up (since v1.6, P1.5): on once both keys are saved in API
 * Credentials → Turnstile, skipped without them. The browser's answer is checked with Cloudflare's siteverify; the
 * secret never leaves the server and nothing of the answer is logged.
 */
final class Turnstile
{
    public const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    public function __construct(private readonly CredentialVault $vault) {}

    public function configured(): bool
    {
        return $this->vault->has('turnstile', 'site_key') && $this->vault->has('turnstile', 'secret_key');
    }

    public function siteKey(): ?string
    {
        return $this->configured() ? $this->vault->get('turnstile', 'site_key') : null;
    }

    /** Whether Cloudflare accepts [$token] for this site; false when it cannot be asked. */
    public function verify(?string $token, ?string $ip): bool
    {
        if (! $this->configured()) {
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
