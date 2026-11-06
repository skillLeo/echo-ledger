<?php

declare(strict_types=1);

namespace App\Services\Connector;

use Exception;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The refresh token grant, which notices a used token coming back (since v1.5, docs/API.md "Claude and ChatGPT
 * connector"; OAuth 2.1 asks this of public clients, and every app here is one). The library only refuses it, so whoever
 * refreshed first would keep the connection for 30 days and the other party would just be turned away.
 */
final class RotatingRefreshGrant extends RefreshTokenGrant
{
    public function __construct(RefreshTokenRepositoryInterface $refreshTokens, private readonly ConnectedApps $apps)
    {
        parent::__construct($refreshTokens);
    }

    protected function validateOldRefreshToken(ServerRequestInterface $request, string $clientId): array
    {
        try {
            return parent::validateOldRefreshToken($request, $clientId);
        } catch (OAuthServerException $e) {
            $this->noticeReuse($request, $clientId);

            throw $e;
        }
    }

    private function noticeReuse(ServerRequestInterface $request, string $clientId): void
    {
        try {
            $token = json_decode($this->decrypt((string) $this->getRequestParameter('refresh_token', $request)), true);
        } catch (Exception) {
            return;
        }
        // Only a real token of this app, still in its time. A removed row reads as revoked, so the clean-up changes nothing.
        if (! is_array($token) || ($token['client_id'] ?? null) !== $clientId || (int) $token['expire_time'] < time()
            || ! $this->refreshTokenRepository->isRefreshTokenRevoked((string) $token['refresh_token_id'])) {
            return;
        }
        $this->apps->refreshTokenReused((int) $token['user_id'], $clientId, (string) $token['access_token_id'], (int) $token['expire_time']);
    }
}
