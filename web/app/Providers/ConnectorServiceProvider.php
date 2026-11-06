<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Connector\ConnectedApps;
use App\Services\Connector\ConnectorScopes;
use App\Services\Connector\ConsentPage;
use App\Services\Connector\S256AuthCodeGrant;
use Carbon\CarbonInterval;
use DateInterval;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
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
use League\OAuth2\Server\Grant\RefreshTokenGrant;

/**
 * The Claude and ChatGPT connector's sign-in (since v1.5, docs/API.md "Claude and ChatGPT connector"): OAuth 2.1 through
 * Passport with only what it needs. None of Passport's own routes are served (routes/ai.php names the four it uses),
 * the one scope is `mcp:use`, access tokens last 1 hour and refresh tokens 30 days (each used once). The server knows
 * two grants, the authorization code with PKCE S256 and the refresh token; Passport's own binding would add client
 * credentials, so it is replaced here.
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

        $this->app->singleton(AuthorizationServer::class, fn (): AuthorizationServer => $this->authorizationServer());
    }

    public function boot(): void
    {
        Event::listen(AccessTokenCreated::class, [ConnectedApps::class, 'tokenIssued']);

        // The apps' servers ask from a few shared addresses, so the token route counts per address and client.
        RateLimiter::for('oauth-token', fn (Request $request) => Limit::perMinute(60)->by('oauth-token:'.$request->ip().'|'.mb_substr((string) $request->input('client_id'), 0, 64)));
        // Open to anyone by the standard: enough to add a connector, not to fill the table.
        RateLimiter::for('oauth-register', fn (Request $request) => Limit::perMinute(10)->by('oauth-register:'.$request->ip()));
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
        );
        $code->setRefreshTokenTTL(Passport::refreshTokensExpireIn());
        $server->enableGrantType($code, Passport::tokensExpireIn());

        $refresh = new RefreshTokenGrant($this->app->make(RefreshTokenRepository::class));
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
