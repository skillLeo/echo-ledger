<?php

declare(strict_types=1);

namespace App\Services\Connector;

use DateInterval;
use Exception;
use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use League\OAuth2\Server\ResponseTypes\ResponseTypeInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The authorization code grant with PKCE S256 only (since v1.5, docs/API.md "Claude and ChatGPT connector"). The library
 * also takes `plain`, and takes it silently when the method is left out, so every other request is refused here, before
 * the person sees anything, for every client. A used code coming back means it leaked (OAuth 2.1 section 4.1.3): it is
 * refused as the library does, and what it gave stops too.
 */
final class S256AuthCodeGrant extends AuthCodeGrant
{
    public function __construct(
        AuthCodeRepositoryInterface $codes,
        RefreshTokenRepositoryInterface $refreshTokens,
        DateInterval $codeLifetime,
        private readonly ConnectedApps $apps,
    ) {
        parent::__construct($codes, $refreshTokens, $codeLifetime);
    }

    public function validateAuthorizationRequest(ServerRequestInterface $request): AuthorizationRequestInterface
    {
        $query = $request->getQueryParams();
        if (($query['code_challenge'] ?? '') === '') {
            throw OAuthServerException::invalidRequest('code_challenge', 'A PKCE code challenge is required.');
        }
        if (($query['code_challenge_method'] ?? null) !== 'S256') {
            throw OAuthServerException::invalidRequest('code_challenge_method', 'Only the S256 code challenge method is supported.');
        }

        return parent::validateAuthorizationRequest($request);
    }

    public function respondToAccessTokenRequest(ServerRequestInterface $request, ResponseTypeInterface $responseType, DateInterval $accessTokenTTL): ResponseTypeInterface
    {
        try {
            return parent::respondToAccessTokenRequest($request, $responseType, $accessTokenTTL);
        } catch (OAuthServerException $e) {
            $this->noticeReuse($request);

            throw $e;
        }
    }

    private function noticeReuse(ServerRequestInterface $request): void
    {
        try {
            $code = json_decode($this->decrypt((string) $this->getRequestParameter('code', $request)), true);
            $clientId = $this->getRequestParameter('client_id', $request);
        } catch (Exception) {
            return;
        }
        // Only a real code of this app, still in its time, and used: a wrong verifier leaves a code unused.
        if (! is_array($code) || ($code['client_id'] ?? null) !== $clientId || (int) $code['expire_time'] < time()
            || ! $this->authCodeRepository->isAuthCodeRevoked((string) $code['auth_code_id'])) {
            return;
        }
        $this->apps->codeReused((int) $code['user_id'], (string) $clientId);
    }
}
