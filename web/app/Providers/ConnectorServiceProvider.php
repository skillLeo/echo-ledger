<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Controllers\Connector\AuthorizeController;
use App\Services\Connector\ConnectedApps;
use App\Services\Connector\ConnectorScopes;
use App\Services\Connector\ConsentPage;
use App\Services\Connector\RotatingRefreshGrant;
use App\Services\Connector\S256AuthCodeGrant;
use Carbon\CarbonInterval;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Server\Registrar;
use Laravel\Passport\Bridge\AccessTokenRepository;
use Laravel\Passport\Bridge\AuthCodeRepository;
use Laravel\Passport\Bridge\ClientRepository;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Events\AccessTokenCreated;
use Laravel\Passport\Passport;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;

/**
 * The Claude and ChatGPT connector's sign-in (since v1.5, docs/API.md "Claude and ChatGPT connector"): OAuth 2.1 through
 * Passport with only what it needs. None of Passport's own routes are served (routes/ai.php names the four it uses),
 * the one scope is `mcp:use`, access tokens last 1 hour and refresh tokens 30 days (each used once, and one that comes
 * back ends the connection). The server knows two grants, the authorization code with PKCE S256 and the refresh token;
 * Passport's own binding would add client credentials, so it is replaced here.
 */
final class ConnectorServiceProvider extends ServiceProvider
{
    /** What the person is asked to allow, in plain words. */
    public const SCOPE_DESCRIPTION = 'Your recordings, their transcripts and screens, your projects and what was shared with you. It can only read: it cannot record, change, share or delete anything.';

    /** Minutes a code stays good between "Allow" and the app's exchange (Passport's own value). */
    private const CODE_MINUTES = 10;

    public function register(): void
    {
        Passport::ignoreRoutes();
        // Its table is not migrated and its routes are not served.
        Passport::$deviceCodeGrantEnabled = false;
        Passport::tokensCan([Registrar::OAUTH_SCOPE => self::SCOPE_DESCRIPTION]);
        Passport::setDefaultScope([Registrar::OAUTH_SCOPE]);
        Passport::tokensExpireIn(CarbonInterval::hour());
        Passport::refreshTokensExpireIn(CarbonInterval::days(30));
        Passport::$revokeRefreshTokenAfterUse = true;
        Passport::authorizationView(fn (array $parameters) => $this->app->make(ConsentPage::class)($parameters));

        // Passport gives its own controller the console's guard this way; a subclass has to ask for it too.
        $this->app->when(AuthorizeController::class)->needs(StatefulGuard::class)->give(fn () => Auth::guard(config('passport.guard')));
        $this->app->singleton(AuthorizationServer::class, fn (): AuthorizationServer => $this->authorizationServer());
    }

    public function boot(): void
    {
        Event::listen(AccessTokenCreated::class, [ConnectedApps::class, 'tokenIssued']);

        // The apps' servers ask from a few shared addresses, so the token route counts per address and client, with a
        // wider limit per address because the caller chooses the client id.
        RateLimiter::for('oauth-token', function (Request $request): array {
            $clientId = $request->input('client_id');

            return [
                Limit::perMinute(300)->by('address:'.$request->ip()),
                Limit::perMinute(60)->by('client:'.$request->ip().'|'.(is_string($clientId) ? mb_substr($clientId, 0, 64) : '')),
            ];
        });
        // Open to anyone by the standard: enough to add a connector, not to fill the table. An app that never connects
        // is removed after a day (ConnectedApps), so these limits are also about what one address can leave behind.
        RateLimiter::for('oauth-register', fn (Request $request): array => [
            Limit::perMinute(10)->by('minute:'.$request->ip()),
            Limit::perHour(30)->by('hour:'.$request->ip()),
            Limit::perDay(100)->by('day:'.$request->ip()),
        ]);
        RateLimiter::for('oauth-consent', fn (Request $request) => Limit::perMinute(30)->by('oauth-consent:'.$request->ip()));
        RateLimiter::for('oauth-discovery', fn (Request $request) => Limit::perMinute(120)->by('oauth-discovery:'.$request->ip()));
        // Every message to /mcp; the 60 tool calls a minute per person are counted by the tools themselves.
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(240)->by('mcp:'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }

    private function authorizationServer(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            $this->app->make(ClientRepository::class),
            $this->app->make(AccessTokenRepository::class),
            $this->app->make(ConnectorScopes::class),
            $this->privateKey(),
            Passport::tokenEncryptionKey($this->app->make('encrypter')),
            Passport::$authorizationServerResponseType,
        );
        $server->setDefaultScope(Passport::$defaultScope);
        $server->revokeRefreshTokens(Passport::$revokeRefreshTokenAfterUse);

        $code = new S256AuthCodeGrant(
            $this->app->make(AuthCodeRepository::class),
            $this->app->make(RefreshTokenRepository::class),
            new DateInterval('PT'.self::CODE_MINUTES.'M'),
            $this->app->make(ConnectedApps::class),
        );
        $code->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $server->enableGrantType($code, Passport::tokensExpireIn());

        $refresh = new RotatingRefreshGrant($this->app->make(RefreshTokenRepository::class), $this->app->make(ConnectedApps::class));
        $refresh->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $server->enableGrantType($refresh, Passport::tokensExpireIn());

        return $server;
    }

    /** `storage/oauth-private.key` (`php artisan passport:keys` at deploy), or PASSPORT_PRIVATE_KEY when it is set. */
    private function privateKey(): CryptKey
    {
        $key = str_replace('\\n', "\n", (string) config('passport.private_key'));

        return new CryptKey($key !== '' ? $key : 'file://'.Passport::keyPath('oauth-private.key'), null, Passport::$validateKeyPermissions);
    }
}
