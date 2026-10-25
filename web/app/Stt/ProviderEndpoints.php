<?php

declare(strict_types=1);

namespace App\Stt;

/**
 * Which configured provider addresses may receive an API key or bearer token.
 *
 * The console validates these on save, and each driver checks again right before it sends a key, so a value
 * stored before the check existed (or written straight into the database) can never send a key elsewhere.
 */
final class ProviderEndpoints
{
    public const ELEVENLABS_MESSAGE = 'Use an HTTPS address on elevenlabs.io, such as https://api.elevenlabs.io.';

    public const AZURE_ENDPOINT_MESSAGE = 'Use your resource\'s HTTPS endpoint, ending in .cognitiveservices.azure.com or .api.cognitive.microsoft.com.';

    public const WHISPER_MESSAGE = 'Use an HTTPS address. Plain HTTP is allowed only for localhost.';

    public const AZURE_REGION_MESSAGE = 'Use the region name in lower case, such as centralindia.';

    public const AZURE_REGION_PATTERN = '/^[a-z0-9]{2,32}\z/';

    /** api.elevenlabs.io or any subdomain of elevenlabs.io (regional API hosts), over HTTPS. */
    public static function allowsElevenLabs(string $url): bool
    {
        $parts = self::parse($url);

        return $parts !== null && $parts['scheme'] === 'https'
            && ($parts['host'] === 'api.elevenlabs.io' || str_ends_with($parts['host'], '.elevenlabs.io'));
    }

    /** An Azure AI services / Speech resource endpoint, over HTTPS. */
    public static function allowsAzureEndpoint(string $url): bool
    {
        $parts = self::parse($url);

        return $parts !== null && $parts['scheme'] === 'https'
            && (str_ends_with($parts['host'], '.cognitiveservices.azure.com') || str_ends_with($parts['host'], '.api.cognitive.microsoft.com'));
    }

    /** A self-hosted whisper-server: any host over HTTPS; plain HTTP only on this machine. */
    public static function allowsWhisper(string $url): bool
    {
        $parts = self::parse($url);
        if ($parts === null) {
            return false;
        }

        return $parts['scheme'] === 'https'
            || ($parts['scheme'] === 'http' && in_array($parts['host'], ['localhost', '127.0.0.1'], true));
    }

    /** The region becomes part of a host name (`https://{region}.api.cognitive.microsoft.com`). */
    public static function allowsAzureRegion(string $region): bool
    {
        return preg_match(self::AZURE_REGION_PATTERN, $region) === 1;
    }

    /** @return array{scheme: string, host: string}|null */
    private static function parse(string $url): ?array
    {
        if ($url !== trim($url) || preg_match('/[\s\\\\]/', $url) === 1) {
            return null;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        // No credentials, query or fragment: these are base addresses that paths get appended to.
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if ($host === '' || preg_match('/^[a-z0-9.-]+$/', $host) !== 1 || str_ends_with($host, '.')) {
            return null;
        }

        return ['scheme' => strtolower($parts['scheme']), 'host' => $host];
    }
}
