<?php

declare(strict_types=1);

namespace App\Http\Controllers\Connector;

use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Passport\Client;
use Laravel\Passport\Http\Controllers\AuthorizationController;

/**
 * Passport's consent step, always asking (since v1.5, docs/API.md "Claude and ChatGPT connector"). Passport would hand
 * out a code with no page while the app still has a working token for the person, but every app here registered itself
 * and nothing proves who sends the next request under its id. So each one gets the question, and `prompt=none` gets
 * `consent_required`.
 */
final class AuthorizeController extends AuthorizationController
{
    /** @param  array<int, mixed>  $scopes */
    protected function hasGrantedScopes(Authenticatable $user, Client $client, array $scopes): bool
    {
        return false;
    }
}
