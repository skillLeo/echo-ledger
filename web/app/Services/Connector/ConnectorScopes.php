<?php

declare(strict_types=1);

namespace App\Services\Connector;

use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Bridge\ScopeRepository;
use League\OAuth2\Server\Entities\ScopeEntityInterface;

/**
 * `mcp:use` is the only scope there is (since v1.5). Passport also lets `*` through, which is meant for its personal
 * tokens: asked for here it is refused like any other unknown scope.
 */
final class ConnectorScopes extends ScopeRepository
{
    public function getScopeEntityByIdentifier(string $identifier): ?ScopeEntityInterface
    {
        return $identifier === Registrar::OAUTH_SCOPE ? parent::getScopeEntityByIdentifier($identifier) : null;
    }
}
