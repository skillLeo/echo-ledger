<?php

declare(strict_types=1);

namespace App\Rules;

use App\Stt\ProviderEndpoints;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/** Settings page rule for provider addresses that receive API keys (see ProviderEndpoints). */
final class ProviderEndpoint implements ValidationRule
{
    public function __construct(private readonly string $provider)
    {
        if (! in_array($provider, ['elevenlabs', 'azure', 'whisper'], true)) {
            throw new InvalidArgumentException("Unknown provider endpoint kind [{$provider}].");
        }
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return; // "required" decides whether a blank value is allowed
        }
        $url = is_string($value) ? $value : '';

        [$allowed, $message] = match ($this->provider) {
            'elevenlabs' => [ProviderEndpoints::allowsElevenLabs($url), ProviderEndpoints::ELEVENLABS_MESSAGE],
            'azure' => [ProviderEndpoints::allowsAzureEndpoint($url), ProviderEndpoints::AZURE_ENDPOINT_MESSAGE],
            default => [ProviderEndpoints::allowsWhisper($url), ProviderEndpoints::WHISPER_MESSAGE],
        };

        if (! $allowed) {
            $fail($message);
        }
    }
}
