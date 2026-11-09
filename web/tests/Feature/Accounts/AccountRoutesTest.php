<?php

declare(strict_types=1);

use App\Filament\Auth\Register;
use App\Models\EmailCode;
use App\Models\User;
use App\Services\Accounts\SignUp;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Mail::fake();
});

/**
 * Every route the accounts added (since v1.5): its rate limiter, and what it needs before it answers anything but a
 * public page (null = public by design: the sign-in steps, the legal pages, the deletion page's explanation).
 */
const ACCOUNT_ROUTES = [
    'api.auth.options' => ['throttle:site', null],
    'api.auth.password' => ['throttle:auth-password', null],
    'api.auth.device.token' => ['throttle:auth-token', null],
    'api.account' => ['throttle:device', 'abilities:config:read'],
    'api.account.sign-out-everywhere' => ['throttle:device', 'abilities:sessions:write'],
    'api.account.destroy' => ['throttle:device', 'abilities:sessions:write'],
    'api.account.export' => ['throttle:device', 'abilities:library:read'],
    'api.account.export.show' => ['throttle:device', 'abilities:library:read'],
    'auth.google.redirect' => ['throttle:google', null],
    'auth.google.callback' => ['throttle:google', null],
    'auth.google.confirm' => ['throttle:google', null],
    'auth.google.confirm.store' => ['throttle:google', null],
    'account.delete' => ['throttle:account', null],
    'account.delete.store' => ['throttle:account', 'auth:web'],
    'account.export.download' => ['throttle:account', 'signed'],
    'device.sign-in' => ['throttle:device-sign-in', null],
    'device.sign-in.approve' => ['throttle:device-sign-in', 'auth:web'],
    'legal.terms' => ['throttle:site', null],
    'legal.privacy' => ['throttle:site', null],
];

/** Signs a console user in and gives back [$uri]. */
function signedInFor($test, string $uri): string
{
    $test->actingAs(Fx::user(), 'web');

    return $uri;
}

it('throttles every account route and guards the ones that act for someone', function () {
    foreach (ACCOUNT_ROUTES as $name => [$throttle, $guard]) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull("{$name} is not registered");
        $middleware = $route->gatherMiddleware();
        expect($middleware)->toContain($throttle);
        if ($guard !== null) {
            expect($middleware)->toContain($guard);
        }
        if (str_starts_with($name, 'api.account')) {
            expect($middleware)->toContain('auth:sanctum');
        }
    }
});

it('answers 429 with Retry-After from every account route\'s limiter', function (string $limiter, string $method, Closure $target) {
    // One request a minute, so the second one shows the limiter is in front of the route.
    RateLimiter::for($limiter, fn (Request $request) => Limit::perMinute(1)->by("{$limiter}:{$request->ip()}"));
    [$uri, $token] = $target($this);
    $send = fn () => ($token === null ? $this : $this->asToken($token))->json($method, $uri);

    expect($send()->status())->not->toBe(429);
    $send()->assertStatus(429)->assertHeader('Retry-After');
})->with([
    // A token that may only read the config: the abilities check comes after the limiter, so nothing is changed.
    'GET /api/account' => ['device', 'GET', fn ($test) => ['/api/account', $test->enroll(Fx::user(), ['config:read'])[1]]],
    'POST /api/account/sign-out-everywhere' => ['device', 'POST', fn ($test) => ['/api/account/sign-out-everywhere', $test->enroll(Fx::user(), ['config:read'])[1]]],
    'DELETE /api/account' => ['device', 'DELETE', fn ($test) => ['/api/account', $test->enroll(Fx::user(), ['config:read'])[1]]],
    'POST /api/account/export' => ['device', 'POST', fn ($test) => ['/api/account/export', $test->enroll(Fx::user(), ['config:read'])[1]]],
    'GET /api/account/export/{id}' => ['device', 'GET', fn ($test) => ['/api/account/export/'.Str::ulid(), $test->enroll(Fx::user(), ['config:read'])[1]]],
    // Signed in: the console's sign-in check runs before any limiter (a guest is sent to sign in, nothing else).
    'POST /account/delete' => ['account', 'POST', fn ($test) => [signedInFor($test, '/account/delete'), null]],
    'GET /account/export/{id}/download' => ['account', 'GET', fn () => [URL::temporarySignedRoute('account.export.download', now()->addHour(), ['export' => (string) Str::ulid()]), null]],
    'POST /device/sign-in' => ['device-sign-in', 'POST', fn ($test) => [signedInFor($test, '/device/sign-in'), null]],
    'GET /api/auth/options' => ['site', 'GET', fn () => ['/api/auth/options', null]],
    'GET /auth/google/callback' => ['google', 'GET', fn () => ['/auth/google/callback', null]],
    'GET /auth/google/confirm' => ['google', 'GET', fn () => ['/auth/google/confirm', null]],
    'POST /auth/google/confirm' => ['google', 'POST', fn () => ['/auth/google/confirm', null]],
]);

it('keeps the account API to a token: 401 without one', function (string $method, string $uri) {
    $this->json($method, $uri)->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
})->with([
    ['GET', '/api/account'],
    ['POST', '/api/account/sign-out-everywhere'],
    ['DELETE', '/api/account'],
    ['POST', '/api/account/export'],
    ['GET', '/api/account/export/01ARZ3NDEKTSV4RRFFQ69G5FAV'],
]);

it('asks for a signed-in person before deleting an account or approving a device', function () {
    $this->post('/account/delete', ['password' => 'password', 'confirm' => '1'])->assertRedirect();
    $this->post('/device/sign-in')->assertRedirect();
    expect(User::query()->whereNotNull('deletes_at')->exists())->toBeFalse();
});

it('refuses an export link that is not signed or has expired', function () {
    $id = (string) Str::ulid();
    $this->get('/account/export/'.$id.'/download')->assertForbidden();
    $this->get(URL::temporarySignedRoute('account.export.download', now()->subMinute(), ['export' => $id]))->assertForbidden();
});

it('slows the console sign-in down after ten tries in a minute from one address', function () {
    app(SettingsRepository::class)->set('signup.mode', SignUp::ANYONE);
    $page = Livewire::test(Register::class);
    foreach (range(1, 10) as $i) {
        $page->fillForm(['email' => "person{$i}@example.test"])->call('authenticate');
        $page->call('startOver');
    }
    $page->fillForm(['email' => 'person11@example.test'])->call('authenticate')->assertNotified();

    expect(EmailCode::query()->where('email', 'person11@example.test')->exists())->toBeFalse()
        ->and(EmailCode::query()->count())->toBe(10);
});
