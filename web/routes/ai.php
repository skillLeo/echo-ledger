<?php

declare(strict_types=1);

use App\Http\Controllers\Connector\AuthorizeController;
use App\Http\Controllers\Connector\RegisterController;
use App\Mcp\Servers\EchoLedgerServer;
use Filament\Http\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\ApproveAuthorizationController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;
use Laravel\Passport\Http\Middleware\CheckToken;

/*
| The Claude and ChatGPT connector (since v1.5, docs/API.md "Claude and ChatGPT connector"). Discovery and dynamic
| client registration through laravel/mcp's routes; of Passport's routes only the authorization-code flow and the token
| route (Passport's own loader is off, App\Providers\ConnectorServiceProvider); the MCP server behind a person's token.
| The consent routes carry the console's session check: a session it has ended (the password changed since) gets the
| sign-in here too, not a consent page that would leave a connection behind.
*/

Route::middleware('throttle:oauth-discovery')->group(fn () => Mcp::oauthRoutes(registration: false));
Route::post('/oauth/register', RegisterController::class)->middleware('throttle:oauth-register')->name('mcp.oauth.register');

Route::get('/oauth/authorize', [AuthorizeController::class, 'authorize'])
    ->middleware(['web', AuthenticateSession::class, 'throttle:oauth-consent'])->name('passport.authorizations.authorize');
Route::middleware(['web', 'auth:web', AuthenticateSession::class, 'throttle:oauth-consent'])->group(function (): void {
    Route::post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])->name('passport.authorizations.approve');
    Route::delete('/oauth/authorize', [DenyAuthorizationController::class, 'deny'])->name('passport.authorizations.deny');
});
Route::post('/oauth/token', [AccessTokenController::class, 'issueToken'])->middleware('throttle:oauth-token')->name('passport.token');

Mcp::web('/mcp', EchoLedgerServer::class)
    ->middleware(['auth:api', CheckToken::using(Registrar::OAUTH_SCOPE), 'throttle:mcp'])
    ->name('mcp');
