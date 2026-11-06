<?php

declare(strict_types=1);

use App\Mail\ResetPasswordMail;
use App\Providers\AppServiceProvider;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Middleware\TrustHosts;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
| The findings of the v1.5 release's security review, each with what guards it (C1, M1 … m7, H1). Every test here failed
| before its fix.
*/

// ---- C1: the Host header and the links this server emails ----------------------------------------------------------

afterEach(function () {
    // TrustHosts sets this on Symfony's Request for the whole process, and it would outlive the test.
    Request::setTrustedHosts([]);
});

/**
 * Laravel's TrustHosts and our root URL both skip themselves in local and in tests (`runningUnitTests()` reads the
 * environment), so as far as the two can tell this test is production: the real middleware and the real boot code run.
 */
function asProduction(): void
{
    app()['env'] = 'production';
    AppServiceProvider::useAppUrl(app());
}

it('C1 trusts exactly the host of APP_URL, on every request', function () {
    expect(app(Kernel::class)->hasMiddleware(TrustHosts::class))->toBeTrue()
        ->and(app(TrustHosts::class)->hosts())->toBe(['^echoledger\.test$']);
});

it('C1 refuses another Host, a subdomain and a name that only starts with ours, and serves its own', function (string $host, int $status) {
    asProduction();

    $this->get("http://{$host}/admin/password-reset/request")->assertStatus($status);
})->with([
    'someone else\'s' => ['evil.test', 400],
    'a subdomain' => ['app.echoledger.test', 400],
    'ours as a prefix' => ['echoledger.test.evil.test', 400],
    'ours' => ['echoledger.test', 200],
]);

it('C1 puts APP_URL in the reset email whatever Host the request named', function () {
    asProduction();
    Mail::fake();
    $user = Fx::user(['email' => 'target@corp.test']);
    // A request the host check did not stop: the same code the reset page calls, while the request says evil.test.
    Request::setTrustedHosts([]);
    $request = Request::create('http://evil.test/admin/password-reset/request');
    app()->instance('request', $request);
    URL::setRequest($request);

    $user->sendPasswordResetNotification('a-reset-token');

    Mail::assertQueued(ResetPasswordMail::class, fn (ResetPasswordMail $mail): bool => str_starts_with($mail->url, 'https://echoledger.test/'));
});

it('C1 keeps the request\'s address in local, where a phone reaches the laptop by its IP', function () {
    app()['env'] = 'local';
    AppServiceProvider::useAppUrl(app());
    $request = Request::create('http://192.168.1.20:8000/admin');
    app()->instance('request', $request);
    URL::setRequest($request);

    expect(url('/admin/login'))->toBe('http://192.168.1.20:8000/admin/login');
});

// ---- M5: browsers end with "Sign out everywhere" on the documented VPS too -----------------------------------------

it('M5 keeps sessions in the database in the VPS guide: only those rows can be ended', function () {
    $guide = (string) file_get_contents(dirname(base_path()).'/docs/DEPLOY_VPS.md');

    expect($guide)->toContain("\nSESSION_DRIVER=database\n")->not->toContain('SESSION_DRIVER=redis');
});
