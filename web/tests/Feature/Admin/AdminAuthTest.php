<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Cookie;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

dataset('admin urls', [
    'dashboard' => ['/admin'],
    'sessions list' => ['/admin/sessions'],
    'clients list' => ['/admin/clients'],
    'client create' => ['/admin/clients/create'],
    'devices list' => ['/admin/devices'],
    'settings' => ['/admin/settings'],
    'api credentials' => ['/admin/api-credentials'],
    'profile' => ['/admin/profile'],
]);

it('sends the site root to the admin console', function () {
    $this->get('/')->assertRedirect('/admin');
});

it('redirects a guest from the console page to the login page', function (string $url) {
    $this->get($url)->assertRedirect(route('filament.admin.auth.login'));
})->with('admin urls');

it('redirects guests from a session detail page to the login page', function () {
    $session = CaptureSession::factory()->create();

    $this->get('/admin/sessions/'.$session->id)->assertRedirect(route('filament.admin.auth.login'));
});

it('renders the login form with email, password and remember me', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)
        ->toContain('wire:model="data.email"')
        ->toContain('wire:model="data.password"')
        ->toContain('wire:model="data.remember"')
        ->toContain('EchoLedger');
});

it('offers a show and hide control on the password field', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)
        ->toContain('aria-label="Show password"')
        ->toContain('aria-label="Hide password"')
        ->toContain("x-bind:type=\"isPasswordRevealed ? 'text' : 'password'\"");
    // The field is a real password input until the control is used.
    expect($html)->toMatch('/<input[^>]*id="form\.password"[^>]*type="password"/');
});

it('does not render a registration or password-reset path', function () {
    $html = $this->get('/admin/login')->assertOk()->getContent();

    expect($html)->not->toContain('/admin/register')->not->toContain('password-reset');
    $this->get('/admin/register')->assertNotFound();
});

it('signs a super admin in with the right credentials', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($admin);
    $this->get('/admin')->assertOk();
});

it('rejects a wrong password and stays signed out', function () {
    $this->makeAdmin(['email' => 'owner@example.test']);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'not-the-password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('rejects an unknown email with the same message as a wrong password', function () {
    $this->makeAdmin(['email' => 'owner@example.test']);

    $wrongPassword = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'bad'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
    $unknown = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'nobody@example.test', 'password' => 'bad'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect($unknown->errors()->get('data.email'))->toBe($wrongPassword->errors()->get('data.email'));
    $this->assertGuest();
});

it('requires both fields', function () {
    $this->panelComponent(Login::class)
        ->fillForm(['email' => '', 'password' => ''])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => 'required', 'password' => 'required']);
});

it('rejects a malformed email address', function () {
    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'not-an-email', 'password' => 'secret'])
        ->call('authenticate')
        ->assertHasFormErrors(['email' => 'email']);
});

it('refuses a regular, non-admin account even with the correct password', function () {
    $this->makeRegularUser(['email' => 'staff@example.test']);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'staff@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    $this->assertGuest();
});

it('does not reveal to a non-admin account that its password was correct', function () {
    $this->makeRegularUser(['email' => 'staff@example.test']);

    $correct = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'staff@example.test', 'password' => 'password'])
        ->call('authenticate');
    $wrong = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'staff@example.test', 'password' => 'wrong'])
        ->call('authenticate');

    expect($correct->errors()->get('data.email'))->toBe($wrong->errors()->get('data.email'));
});

it('denies a signed-in non-admin account access to the console', function () {
    $this->actingAs($this->makeRegularUser());

    $this->get('/admin')->assertForbidden();
    $this->get('/admin/sessions')->assertForbidden();
    $this->get('/admin/settings')->assertForbidden();
});

it('throttles repeated login attempts, even when the next password is right', function () {
    $this->makeAdmin(['email' => 'owner@example.test']);

    $login = $this->panelComponent(Login::class);
    foreach (range(1, 5) as $attempt) {
        $login->fillForm(['email' => 'owner@example.test', 'password' => 'wrong-'.$attempt])
            ->call('authenticate')
            ->assertHasFormErrors(['email']);
    }

    $login->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertNotified();

    $this->assertGuest();
});

it('only sets the remember-me cookie when the box is ticked', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test', 'remember_token' => null]);
    $recaller = auth()->guard('web')->getRecallerName();

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password', 'remember' => false])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Cookie::hasQueued($recaller))->toBeFalse();
    expect($admin->fresh()->remember_token)->toBeNull();

    auth()->logout();
    Cookie::flushQueuedCookies();

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password', 'remember' => true])
        ->call('authenticate')
        ->assertHasNoFormErrors();

    expect(Cookie::hasQueued($recaller))->toBeTrue();
    expect($admin->fresh()->remember_token)->not->toBeEmpty();
});

it('sends an already signed-in admin straight to the console from the login page', function () {
    $this->signInAsAdmin();

    $this->get('/admin/login')->assertRedirect();
});

it('signs the admin out and protects the console afterwards', function () {
    $admin = $this->signInAsAdmin();
    $this->get('/admin')->assertOk();

    $this->post(route('filament.admin.auth.logout'))->assertRedirect();

    $this->assertGuest();
    $this->get('/admin')->assertRedirect(route('filament.admin.auth.login'));
    expect(User::query()->find($admin->id))->not->toBeNull();
});

it('offers Profile and Sign out in the account menu', function () {
    $this->signInAsAdmin(['name' => 'Farah Khan']);

    $html = $this->get('/admin')->assertOk()->getContent();

    expect($html)->toContain(route('filament.admin.auth.profile'))
        ->toMatch('/<form[^>]*action="'.preg_quote(route('filament.admin.auth.logout'), '/').'"[^>]*method="post"/i')
        ->toContain('Sign out');
});

it('rejects logout over GET', function () {
    $this->signInAsAdmin();

    $this->get('/admin/logout')->assertStatus(405);
});
