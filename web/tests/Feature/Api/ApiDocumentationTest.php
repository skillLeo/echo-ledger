<?php

declare(strict_types=1);

use App\Models\Device;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * docs/API.md says "If implementation and this file disagree, fix one of them in the same change". These tests read the
 * file and compare it with the router so drift is caught by the build rather than by a device in the field.
 */

/**
 * Headings of the form ### `METHOD /api/...`, and (since v1.5) endpoints defined in a list item or a paragraph:
 * `METHOD /api/...` followed by "— `ability`", or joined to the next one with "and" ("`POST /a` and `POST /b` —
 * `sessions:write`"), or a later sentence naming only the route ("`GET /api/x` lists them"). `rest` carries the
 * ability (and, for headings, the throttle) the text gives it.
 *
 * @return list<array{method: string, uri: string, rest: string}>
 */
function documentedEndpoints(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');
    $endpoints = [];
    foreach (explode("\n", $doc) as $line) {
        if (preg_match('/^### `(GET|POST|PUT|PATCH|DELETE) (\/api\/[^`?]*)(?:\?[^`]*)?`(.*)$/', $line, $m) === 1) {
            $endpoints[$m[1].' '.shapeOf($m[2])] = ['method' => $m[1], 'uri' => $m[2], 'rest' => $m[3]];
        }
    }
    // Mentions anywhere else; a line break may sit between the method and the path.
    preg_match_all('/`(GET|POST|PUT|PATCH|DELETE)\s+(\/api\/[A-Za-z0-9_\/{}-]*)(?:\?[^`]*)?`/', $doc, $mentions, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
    $abilities = [];
    for ($i = count($mentions) - 1; $i >= 0; $i--) {
        $end = $mentions[$i][0][1] + strlen($mentions[$i][0][0]);
        $after = substr($doc, $end, 80);
        $ability = null;
        if (preg_match('/^\s*—\s*`([a-z]+:[a-z]+)`/', $after, $a) === 1) {
            $ability = $a[1];
        } elseif (preg_match('/^\s+and\s+(?:the new\s+)?`/', $after) === 1 && isset($abilities[$i + 1])) {
            $ability = $abilities[$i + 1];
        }
        $abilities[$i] = $ability;
    }
    foreach ($mentions as $i => $mention) {
        $key = $mention[1][0].' '.shapeOf($mention[2][0]);
        if (str_contains($mention[2][0], '/webhooks/') || (isset($endpoints[$key]) && $abilities[$i] === null)) {
            continue;
        }
        if (! isset($endpoints[$key]) || ! str_contains($endpoints[$key]['rest'], '— `')) {
            $endpoints[$key] = ['method' => $mention[1][0], 'uri' => $mention[2][0], 'rest' => $abilities[$i] !== null ? " — `{$abilities[$i]}`" : ''];
        }
    }

    return array_values($endpoints);
}

/** Route placeholders all look the same for comparison purposes. */
function shapeOf(string $uri): string
{
    return (string) preg_replace('/\{[^}]+\}/', '{}', '/'.ltrim($uri, '/'));
}

/** @return array<string, LaravelRoute> keyed by "METHOD /shape" */
function registeredApiRoutes(): array
{
    $routes = [];
    foreach (Route::getRoutes()->getRoutes() as $route) {
        if (! str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        $routes[$route->methods()[0].' '.shapeOf($route->uri())] = $route;
    }

    return $routes;
}

/*
 * The contract is written ahead of the server (v1.5): endpoints documented for a phase that is not built yet. Each
 * phase removes its own lines when it serves them; nothing else may be documented without a route.
 */
/** v1.6 P1: the contract is written first; each route leaves this list when it is served. */
/** Routes served before docs/API.md names them (a branch ahead of the contract); empty when they agree. */
const SERVED_BEFORE_DOCUMENTED = [];

const NOT_SERVED_YET = [];

it('documents every endpoint the router serves, except the provider webhook which has its own section', function () {
    $documented = collect(documentedEndpoints())->map(fn (array $e) => $e['method'].' '.shapeOf($e['uri']))->sort()->values()->all();
    $served = collect(array_keys(registeredApiRoutes()))->reject(fn (string $key) => str_contains($key, '/webhooks/'))->sort()->values()->all();

    expect(array_values(array_diff($served, $documented, SERVED_BEFORE_DOCUMENTED)))->toBe([], 'served but not documented')
        ->and(array_values(array_intersect(SERVED_BEFORE_DOCUMENTED, $documented)))->toBe([], 'documented now: remove it from SERVED_BEFORE_DOCUMENTED')
        ->and(array_values(array_diff($documented, $served, NOT_SERVED_YET)))->toBe([], 'documented but not served')
        // A line that stays after its phase served the route would hide a missing one later.
        ->and(array_values(array_intersect(NOT_SERVED_YET, $served)))->toBe([], 'served now: remove it from NOT_SERVED_YET');
});

it('documents the provider webhook', function () {
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');

    expect($doc)->toContain('POST /api/webhooks/stt/{provider}')->and(array_keys(registeredApiRoutes()))->toContain('POST /api/webhooks/stt/{}');
});

it('names, in each heading, the ability the route actually enforces', function () {
    $routes = registeredApiRoutes();
    foreach (documentedEndpoints() as $endpoint) {
        $key = $endpoint['method'].' '.shapeOf($endpoint['uri']);
        if (in_array($key, NOT_SERVED_YET, true)) {
            continue;
        }
        $route = $routes[$key] ?? null;
        expect($route)->not->toBeNull("{$endpoint['method']} {$endpoint['uri']} is not routed");

        $enforced = collect($route->gatherMiddleware())->first(fn ($m) => is_string($m) && str_starts_with($m, 'abilities:'));
        if (preg_match('/— `([a-z]+:[a-z]+)`/', $endpoint['rest'], $m) === 1) {
            expect($enforced)->toBe('abilities:'.$m[1], "{$endpoint['method']} {$endpoint['uri']} documents {$m[1]}");
        } elseif (str_contains($endpoint['rest'], '— auth')) {
            expect($enforced)->toBeNull("{$endpoint['uri']} is documented as plain auth");
            expect($route->gatherMiddleware())->toContain('auth:sanctum');
        }
    }
});

it('states the documented throttles the limiters really apply', function () {
    $documented = [];
    foreach (documentedEndpoints() as $endpoint) {
        if (preg_match('/\((\d+)\/min\/(IP|device)\)/', $endpoint['rest'], $m) === 1) {
            $documented[$endpoint['uri']] = [(int) $m[1], $m[2]];
        }
    }
    $limiterFor = ['/api/devices/register' => 'pairing', '/api/stt/token' => 'stt-token', '/api/live-translate/token' => 'stt-token', '/api/search' => 'search', '/api/clients/{id}/ask' => 'ask'];

    expect($documented)->not->toBeEmpty();
    foreach ($documented as $uri => [$perMinute, $by]) {
        $request = Request::create($uri, 'POST', server: ['REMOTE_ADDR' => '203.0.113.9']);
        $request->setUserResolver(fn () => null);
        /** @var Limit $limit */
        $limit = RateLimiter::limiter($limiterFor[$uri])($request);

        expect($limit->maxAttempts)->toBe($perMinute)->and($limit->decaySeconds)->toBe(60);
        if ($by === 'IP') {
            expect($limit->key)->toContain('203.0.113.9');
        }
    }
});

it('documents the same abilities on every device token as the code grants', function () {
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');
    preg_match('/Abilities on every device\s+token: ([^.]+)\./s', $doc, $m);
    preg_match_all('/`([a-z]+:[a-z]+)`/', $m[1] ?? '', $tokens);

    expect($tokens[1])->toEqualCanonicalizing(Device::ABILITIES);
});

it('documents the error statuses the API really uses', function () {
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');

    foreach (['`401`', '`403`', '`404`', '`409`', '`413`', '`422`', '`429`'] as $status) {
        expect($doc)->toContain($status);
    }
});
