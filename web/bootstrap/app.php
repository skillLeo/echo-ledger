<?php

use App\Exceptions\ApiExceptionRenderer;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;
use League\OAuth2\Server\Exception\OAuthServerException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
        // Only the address in APP_URL reaches the app, without subdomains, so a made-up Host header cannot end up in a
        // link this server emails (the web server's half is in docs/DEPLOY_VPS.md). These are regular expressions, hence
        // the anchors. Laravel skips this in local and in tests; Security/ReleaseReviewTest.php runs it as production does.
        $middleware->trustHosts(fn (): array => ['^'.preg_quote((string) parse_url((string) config('app.url'), PHP_URL_HOST)).'$'], subdomains: false);
        // API clients and the connector's /mcp get 401 JSON; browsers go to the Filament login.
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('api/*', 'mcp') ? null : route('filament.app.auth.login'));
        // nosniff, Referrer-Policy, framing, HSTS and the console CSP on every answer.
        $middleware->append(SecurityHeaders::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'mcp') || $request->expectsJson());
        // Device API errors: {"message", "code", ...} with fixed messages (docs/API.md "Error codes").
        $exceptions->render(fn (Throwable $e, Request $request) => (new ApiExceptionRenderer)($e, $request));
        // An expired or wrong token of the connector is an everyday answer (401), not a server error: Passport's guard
        // would otherwise write each one to the log.
        $exceptions->dontReport(OAuthServerException::class);
        // Never put secrets or audio into logs/flash data.
        $exceptions->dontFlash(['current_password', 'password', 'password_confirmation', 'pairing_code', 'token']);
    })->create();
