<?php

declare(strict_types=1);

namespace App\Services\Connector;

use League\OAuth2\Server\Exception\OAuthServerException;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\RequestTypes\AuthorizationRequestInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * The authorization code grant with PKCE S256 only (since v1.5, docs/API.md "Claude and ChatGPT connector"). The library
 * also takes `plain`, and takes it silently when the method is left out, so every other request is refused here, before
 * the person sees anything, for every client.
 */
final class S256AuthCodeGrant extends AuthCodeGrant
{
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
}
