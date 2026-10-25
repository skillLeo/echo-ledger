<?php

declare(strict_types=1);

namespace Tests\Feature\Security\Support;

use Illuminate\Testing\TestResponse;

/**
 * Starts every API call from a clean slate: the Sanctum guard caches the resolved device for the lifetime of the
 * application instance, so a test that switches tokens between calls must drop it, along with default headers.
 */
trait ApiClient
{
    protected function api(?string $token = null, bool $json = true): static
    {
        app('auth')->forgetGuards();
        if (config('logging.default') === 'stack') {
            config(['logging.default' => 'null']); // hostile-input cases raise many expected exceptions; keep them out of the shared log file
        }

        $this->defaultHeaders = $json ? ['Accept' => 'application/json'] : [];
        $this->defaultCookies = [];
        $this->unencryptedCookies = [];

        return $token === null ? $this : $this->withToken($token);
    }

    /**
     * Sends one raw octet-stream chunk. `$sha256 = 'auto'` hashes the body; null omits the header.
     *
     * @param  array<string, string>  $headers  extra or overriding request headers
     */
    protected function putChunk(?string $token, string $recordingId, string|int|null $index, string $body, ?string $sha256 = 'auto', array $headers = []): TestResponse
    {
        $this->api($token);

        $sent = array_filter([
            'Content-Type' => 'application/octet-stream',
            'Upload-Chunk-Index' => $index === null ? null : (string) $index,
            'Upload-Chunk-Sha256' => $sha256 === 'auto' ? hash('sha256', $body) : $sha256,
        ], static fn (?string $value): bool => $value !== null);

        return $this->call(
            'POST',
            "/api/recordings/{$recordingId}/chunks",
            [],
            [],
            [],
            $this->transformHeadersToServerVars(array_replace($sent, $headers)),
            $body,
        );
    }
}
