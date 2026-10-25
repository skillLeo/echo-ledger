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

/** @return list<array{method: string, uri: string, rest: string}> headings of the form ### `METHOD /api/...` */
function documentedEndpoints(): array
{
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');
    $endpoints = [];
    foreach (explode("\n", $doc) as $line) {
        if (preg_match('/^### `(GET|POST|PUT|PATCH|DELETE) (\/api\/[^`?]*)(?:\?[^`]*)?`(.*)$/', $line, $m) === 1) {
            $endpoints[] = ['method' => $m[1], 'uri' => $m[2], 'rest' => $m[3]];
        }
    }

    return $endpoints;
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

it('documents every endpoint the router serves, except the provider webhook which has its own section', function () {
    $documented = collect(documentedEndpoints())->map(fn (array $e) => $e['method'].' '.shapeOf($e['uri']))->sort()->values()->all();
    $served = collect(array_keys(registeredApiRoutes()))->reject(fn (string $key) => str_contains($key, '/webhooks/'))->sort()->values()->all();

    expect($documented)->toBe($served);
});

it('documents the provider webhook', function () {
    $doc = (string) file_get_contents(dirname(__DIR__, 4).'/docs/API.md');

    expect($doc)->toContain('POST /api/webhooks/stt/{provider}')->and(array_keys(registeredApiRoutes()))->toContain('POST /api/webhooks/stt/{}');
});

it('names, in each heading, the ability the route actually enforces', function () {
    $routes = registeredApiRoutes();
    foreach (documentedEndpoints() as $endpoint) {
        $route = $routes[$endpoint['method'].' '.shapeOf($endpoint['uri'])] ?? null;
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
    $limiterFor = ['/api/devices/register' => 'pairing', '/api/stt/token' => 'stt-token'];

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
