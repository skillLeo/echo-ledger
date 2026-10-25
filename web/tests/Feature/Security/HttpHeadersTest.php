<?php

declare(strict_types=1);

use Symfony\Component\HttpFoundation\Cookie;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/** @return list<Cookie> */
function secCookies(string $path = '/admin/login'): array
{
    return test()->get($path)->headers->getCookies();
}

function secSessionCookie(): Cookie
{
    foreach (secCookies() as $cookie) {
        if (str_ends_with($cookie->getName(), '-session')) {
            return $cookie;
        }
    }
    throw new RuntimeException('No session cookie was issued by the login page.');
}

it('stops the console login page from being framed', function () {
    $response = $this->get('/admin/login');

    $frameOptions = strtolower((string) $response->headers->get('X-Frame-Options'));
    $csp = strtolower((string) $response->headers->get('Content-Security-Policy'));
    expect(in_array($frameOptions, ['deny', 'sameorigin'], true) || str_contains($csp, 'frame-ancestors'))->toBeTrue();
})->skip('A6.W25: the app sends no security headers; they exist only in the hosting .htaccess');

it('sends X-Content-Type-Options nosniff on console pages and API responses', function () {
    expect($this->get('/admin/login')->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($this->api()->getJson('/api/config')->headers->get('X-Content-Type-Options'))->toBe('nosniff');
    expect($this->api(Fx::token(Fx::device()))->getJson('/api/config')->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->skip('A6.W25: the app sends no security headers; they exist only in the hosting .htaccess');

it('sends a Referrer-Policy header on console pages', function () {
    expect($this->get('/admin/login')->headers->get('Referrer-Policy'))->not->toBeNull();
})->skip('A6.W25: the app sends no security headers; they exist only in the hosting .htaccess');

it('sends Strict-Transport-Security on HTTPS responses', function () {
    $response = $this->get('/admin/login');

    expect($this->app['request']->isSecure() || str_starts_with((string) config('app.url'), 'https://'))->toBeTrue();
    expect((string) $response->headers->get('Strict-Transport-Security'))->toContain('max-age=');
})->skip('A6.W25: the app sends no security headers; they exist only in the hosting .htaccess');

it('sends a Content-Security-Policy on console pages', function () {
    expect($this->get('/admin/login')->headers->get('Content-Security-Policy'))->not->toBeNull();
})->skip('A6.W25: the app sends no security headers; they exist only in the hosting .htaccess');

it('marks the session cookie HttpOnly and SameSite', function () {
    $cookie = secSessionCookie();

    expect($cookie->isHttpOnly())->toBeTrue()
        ->and(strtolower((string) $cookie->getSameSite()))->toBeIn(['lax', 'strict']);
});

it('marks the session cookie Secure when the application is served over HTTPS', function () {
    expect(str_starts_with((string) config('app.url'), 'https://'))->toBeTrue();

    expect(secSessionCookie()->isSecure())->toBeTrue();
});

it('marks the CSRF cookie SameSite and Secure over HTTPS', function () {
    $xsrf = collect(secCookies())->first(fn (Cookie $cookie) => $cookie->getName() === 'XSRF-TOKEN');

    expect($xsrf)->not->toBeNull();
    expect(strtolower((string) $xsrf->getSameSite()))->toBeIn(['lax', 'strict'])->and($xsrf->isSecure())->toBeTrue();
});

it('issues no cookies at all for bearer-token API calls', function () {
    $token = Fx::token(Fx::device());

    foreach (['/api/config', '/api/library', '/api/clients'] as $path) {
        expect($this->api($token)->getJson($path)->headers->getCookies())->toBe([]);
    }
    expect($this->api()->getJson('/api/config')->headers->getCookies())->toBe([]);
});

it('does not advertise the framework or PHP version in response headers', function () {
    $headers = $this->get('/admin/login')->headers->all();

    expect(array_keys($headers))->not->toContain('x-powered-by')->not->toContain('server');
    expect(json_encode($headers))->not->toContain('Laravel')->not->toContain('PHP/');
});

it('never answers a cross-origin request from the console with CORS headers', function () {
    $response = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST'])->call('OPTIONS', '/admin/login');

    expect($response->headers->get('Access-Control-Allow-Origin'))->toBeNull()
        ->and($response->headers->get('Access-Control-Allow-Credentials'))->toBeNull();
});

it('never allows credentialed cross-origin API calls', function () {
    $response = $this->withHeaders(['Origin' => 'https://evil.example', 'Access-Control-Request-Method' => 'POST', 'Access-Control-Request-Headers' => 'authorization'])
        ->call('OPTIONS', '/api/sessions');

    expect(strtolower((string) $response->headers->get('Access-Control-Allow-Credentials')))->not->toBe('true');
    $origin = $response->headers->get('Access-Control-Allow-Origin');
    expect($origin === null || $origin === '*')->toBeTrue(); // a wildcard is acceptable only because auth is a bearer token, not a cookie
});

it('never lets a browser cache an authenticated JSON response in a shared cache', function () {
    $token = Fx::token(Fx::device());

    foreach (['/api/config', '/api/library', '/api/clients'] as $path) {
        $cache = strtolower((string) $this->api($token)->getJson($path)->headers->get('Cache-Control'));
        expect($cache)->toContain('private')->not->toContain('public');
    }
});

it('serves error pages for unknown console URLs without version or path details', function () {
    config(['app.debug' => false]);

    $body = $this->get('/admin/does-not-exist-'.bin2hex(random_bytes(3)))->getContent();

    expect($body)->not->toContain('vendor')->not->toContain('Stack trace')->not->toContain(base_path());
});
