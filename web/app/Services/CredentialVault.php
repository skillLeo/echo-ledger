<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Credential;
use App\Stt\Exceptions\ProviderNotConfigured;

/**
 * The only way to read or write provider secrets. Values are encrypted at rest (Credential `encrypted` cast)
 * and must never be sent to clients, logged, or filled back into forms.
 */
final class CredentialVault
{
    /** provider => [key => label]. Drives the API Credentials page. */
    public const FIELDS = [
        'assemblyai' => ['api_key' => 'API key', 'webhook_secret' => 'Webhook signing secret'],
        'elevenlabs' => ['api_key' => 'API key', 'webhook_secret' => 'Webhook HMAC secret', 'webhook_id' => 'Webhook ID'],
        'azure' => ['api_key' => 'Speech resource key'],
        'soniox' => ['api_key' => 'API key', 'webhook_secret' => 'Webhook signing secret'],
        'groq' => ['api_key' => 'API key'],
        'whispercpp' => ['bearer_token' => 'Reverse-proxy bearer token'],
        'openai' => ['api_key' => 'API key (optional, future driver)'],
    ];

    /** Secrets that EchoLedger can generate for the admin. */
    public const GENERATABLE = ['assemblyai.webhook_secret', 'soniox.webhook_secret'];

    /** @var array<string, string|null> */
    private array $memo = [];

    public function get(string $provider, string $key): ?string
    {
        $id = $provider.'.'.$key;
        if (! array_key_exists($id, $this->memo)) {
            $value = Credential::query()->where(['provider' => $provider, 'key' => $key])->first()?->value;
            $this->memo[$id] = is_string($value) && $value !== '' ? $value : null;
        }

        return $this->memo[$id];
    }

    public function require(string $provider, string $key): string
    {
        return $this->get($provider, $key)
            ?? throw new ProviderNotConfigured("Missing credential {$provider}.{$key}. Add it on Admin → API Credentials.");
    }

    public function has(string $provider, string $key): bool
    {
        return $this->get($provider, $key) !== null;
    }

    public function put(string $provider, string $key, string $value, ?int $userId = null): void
    {
        Credential::query()->updateOrCreate(
            ['provider' => $provider, 'key' => $key],
            ['value' => $value, 'updated_by' => $userId],
        );
        unset($this->memo[$provider.'.'.$key]);
    }

    public function forget(string $provider, string $key): void
    {
        Credential::query()->where(['provider' => $provider, 'key' => $key])->delete();
        unset($this->memo[$provider.'.'.$key]);
    }

    /** Human status without revealing the secret, e.g. "Stored · ends …a1b2 · updated 2 hours ago". */
    public function describe(string $provider, string $key): string
    {
        $credential = Credential::query()->where(['provider' => $provider, 'key' => $key])->first();
        if ($credential === null) {
            return 'Not set';
        }
        $value = (string) $credential->value;
        $tail = mb_strlen($value) >= 12 ? ' · ends …'.mb_substr($value, -4) : '';

        return 'Stored'.$tail.' · updated '.$credential->updated_at->diffForHumans();
    }

    public static function generateSecret(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
