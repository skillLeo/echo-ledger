<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/** Gives the user an authenticator secret and recovery codes, returning both in plain text. */
function adminEnrolTotp(User $user): array
{
    $provider = AppAuthentication::make();
    $secret = $provider->generateSecret();
    $codes = ['alpha-recovery-0001', 'bravo-recovery-0002', 'charlie-recovery-0003'];
    $provider->saveSecret($user, $secret);
    $provider->saveRecoveryCodes($user, $codes);

    return [$secret, $codes];
}

function adminCurrentCode(User $user, string $secret): string
{
    return AppAuthentication::make()->getCurrentCode($user, $secret);
}

/** Opens the "set up app authentication" modal on the profile page and returns [component, secret, recoveryCodes]. */
function adminOpenTotpSetup($test, User $user): array
{
    $profile = $test->panelComponent(EditProfile::class);
    $profile->call('mountAction', 'setUpAppAuthentication', [], ['schemaComponent' => 'content.app']);
    $payload = decrypt($profile->get('mountedActions.0.arguments.encrypted'));

    return [$profile, $payload['secret'], $payload['recoveryCodes']];
}

it('is configured as optional two-factor authentication with recovery codes', function () {
    $panel = Filament::getPanel('admin');

    expect($panel->hasMultiFactorAuthentication())->toBeTrue()
        ->and($panel->isMultiFactorAuthenticationRequired())->toBeFalse();

    $providers = $panel->getMultiFactorAuthenticationProviders();
    expect($providers)->toHaveCount(1)
        ->and($providers['app']->isRecoverable())->toBeTrue()
        ->and($providers['app']->getBrandName())->toBe('EchoLedger');
});

it('lets an admin without two-factor use the console without being forced to enrol', function () {
    $this->signInAsAdmin();

    $this->get('/admin')->assertOk();
    $this->get('/admin/sessions')->assertOk();
    $this->get('/admin/settings')->assertOk();
});

it('lets an admin without two-factor sign in with only a password', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($admin);
});

it('offers two-factor set-up on the profile page while it is switched off', function () {
    $this->signInAsAdmin();

    $html = $this->panelComponent(EditProfile::class)->html();

    expect($html)->toContain('setUpAppAuthentication')
        ->not->toContain('disableAppAuthentication')
        ->not->toContain('regenerateAppAuthenticationRecoveryCodes');
});

it('enables two-factor with a valid code and the account password', function () {
    $admin = $this->signInAsAdmin();
    [$profile, $secret, $recoveryCodes] = adminOpenTotpSetup($this, $admin);

    $profile->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->set('mountedActions.0.data.password', 'password')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $admin->refresh();
    expect($admin->getAppAuthenticationSecret())->toBe($secret)
        ->and($admin->getAppAuthenticationRecoveryCodes())->toHaveCount(8);
});

it('shows a QR code and the text secret in the set-up dialog', function () {
    $admin = $this->signInAsAdmin();
    [$profile, $secret] = adminOpenTotpSetup($this, $admin);

    $html = $this->modalHtml($profile);

    expect($html)->toContain($secret)->toMatch('/src="data:image\/(svg\+xml|png);base64,/');
});

it('does not enable two-factor when the verification code is wrong', function () {
    $admin = $this->signInAsAdmin();
    [$profile, $secret] = adminOpenTotpSetup($this, $admin);

    $profile->set('mountedActions.0.data.code', '000000')
        ->set('mountedActions.0.data.password', 'password')
        ->call('callMountedAction')
        ->assertHasErrors();

    expect($admin->fresh()->getAppAuthenticationSecret())->toBeNull();
});

it('does not enable two-factor when the account password is wrong', function () {
    $admin = $this->signInAsAdmin();
    [$profile, $secret] = adminOpenTotpSetup($this, $admin);

    $profile->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->set('mountedActions.0.data.password', 'not-my-password')
        ->call('callMountedAction')
        ->assertHasErrors();

    expect($admin->fresh()->getAppAuthenticationSecret())->toBeNull();
});

it('stores the secret encrypted and the recovery codes hashed', function () {
    $admin = $this->signInAsAdmin();
    [$profile, $secret, $recoveryCodes] = adminOpenTotpSetup($this, $admin);
    $profile->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->set('mountedActions.0.data.password', 'password')
        ->call('callMountedAction');

    $row = DB::table('users')->where('id', $admin->id)->first();

    expect($row->app_authentication_secret)->not->toBeEmpty()->not->toContain($secret);
    foreach ($recoveryCodes as $code) {
        expect($row->app_authentication_recovery_codes)->not->toContain($code);
    }
});

it('does not show the recovery codes again once two-factor is enabled', function () {
    $admin = $this->signInAsAdmin();
    [$profile, $secret, $recoveryCodes] = adminOpenTotpSetup($this, $admin);
    $profile->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->set('mountedActions.0.data.password', 'password')
        ->call('callMountedAction');

    $html = $this->panelComponent(EditProfile::class)->html();

    foreach ($recoveryCodes as $code) {
        expect($html)->not->toContain($code);
    }
    expect($html)->not->toContain($secret)
        ->toContain('disableAppAuthentication')
        ->toContain('regenerateAppAuthenticationRecoveryCodes')
        ->not->toContain('setUpAppAuthentication');
});

it('limits repeated set-up attempts', function () {
    $admin = $this->signInAsAdmin();
    [$profile, $secret] = adminOpenTotpSetup($this, $admin);

    foreach (range(1, 6) as $_) {
        $profile->set('mountedActions.0.data.code', '000000')
            ->set('mountedActions.0.data.password', 'password')
            ->call('callMountedAction');
    }

    // Even the right code is refused once the attempts are used up.
    $profile->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->set('mountedActions.0.data.password', 'password')
        ->call('callMountedAction');

    expect($admin->fresh()->getAppAuthenticationSecret())->toBeNull();
});

it('asks for the authenticator code after the password when two-factor is on', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    adminEnrolTotp($admin);

    $login = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate');

    $this->assertGuest();
    expect($login->get('userUndertakingMultiFactorAuthentication'))->not->toBeEmpty();
    expect($login->html())->toContain('data.multiFactor.app.code');
});

it('completes the sign-in with a valid authenticator code', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    [$secret] = adminEnrolTotp($admin);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.code', adminCurrentCode($admin, $secret))
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect();

    $this->assertAuthenticatedAs($admin);
});

it('refuses a wrong authenticator code', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    adminEnrolTotp($admin);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.code', '123456')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.code']);

    $this->assertGuest();
});

it('refuses a wrong password before the authenticator code is ever requested', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    adminEnrolTotp($admin);

    $login = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'wrong'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);

    expect($login->get('userUndertakingMultiFactorAuthentication'))->toBeEmpty();
    $this->assertGuest();
});

it('does not accept the same authenticator code twice', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    [$secret] = adminEnrolTotp($admin);
    $code = adminCurrentCode($admin, $secret);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate')
        ->assertHasNoErrors();
    $this->assertAuthenticatedAs($admin);

    auth()->logout();

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.code', $code)
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.code']);
    $this->assertGuest();
});

it('signs in with a recovery code, and that code works only once', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    [, $codes] = adminEnrolTotp($admin);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', $codes[0])
        ->call('authenticate')
        ->assertHasNoErrors()
        ->assertRedirect();
    $this->assertAuthenticatedAs($admin);
    expect($admin->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(2);

    auth()->logout();

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', $codes[0])
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.recoveryCode']);
    $this->assertGuest();
});

it('rejects an invented recovery code', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    adminEnrolTotp($admin);

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->set('data.multiFactor.app.useRecoveryCode', true)
        ->set('data.multiFactor.app.recoveryCode', 'made-up-recovery-code')
        ->call('authenticate')
        ->assertHasErrors(['data.multiFactor.app.recoveryCode']);

    $this->assertGuest();
    expect($admin->fresh()->getAppAuthenticationRecoveryCodes())->toHaveCount(3);
});

it('never renders the authenticator secret on the challenge screen', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    [$secret, $codes] = adminEnrolTotp($admin);

    $html = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->html();

    expect($html)->not->toContain($secret);
    foreach ($codes as $code) {
        expect($html)->not->toContain($code);
    }
});

it('regenerates recovery codes and invalidates the old ones', function () {
    $admin = $this->signInAsAdmin();
    [$secret, $oldCodes] = adminEnrolTotp($admin);
    $oldHashes = $admin->fresh()->getAppAuthenticationRecoveryCodes();

    $profile = $this->panelComponent(EditProfile::class);
    $profile->call('mountAction', 'regenerateAppAuthenticationRecoveryCodes', [], ['schemaComponent' => 'content.app'])
        ->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->set('mountedActions.0.data.password', 'password')
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $newHashes = $admin->fresh()->getAppAuthenticationRecoveryCodes();
    expect($newHashes)->toHaveCount(8)->not->toBe($oldHashes);

    $provider = AppAuthentication::make();
    foreach ($oldCodes as $old) {
        expect($provider->verifyRecoveryCode($old, $admin->fresh()))->toBeFalse();
    }
});

it('disables two-factor with the password and a valid code, after which sign-in needs no code', function () {
    $admin = $this->signInAsAdmin(['email' => 'owner@example.test']);
    [$secret] = adminEnrolTotp($admin);

    $this->panelComponent(EditProfile::class)
        ->call('mountAction', 'disableAppAuthentication', [], ['schemaComponent' => 'content.app'])
        ->set('mountedActions.0.data.password', 'password')
        ->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->call('callMountedAction')
        ->assertHasNoErrors();

    $admin->refresh();
    expect($admin->getAppAuthenticationSecret())->toBeNull()
        ->and($admin->getAppAuthenticationRecoveryCodes())->toBeNull();

    auth()->logout();
    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();
    $this->assertAuthenticatedAs($admin);
});

it('disables two-factor with a recovery code instead of an authenticator code', function () {
    $admin = $this->signInAsAdmin();
    [, $codes] = adminEnrolTotp($admin);

    $this->panelComponent(EditProfile::class)
        ->call('mountAction', 'disableAppAuthentication', [], ['schemaComponent' => 'content.app'])
        ->set('mountedActions.0.data.password', 'password')
        ->set('mountedActions.0.data.useRecoveryCode', true)
        ->set('mountedActions.0.data.recoveryCode', $codes[1])
        ->call('callMountedAction')
        ->assertHasNoErrors();

    expect($admin->fresh()->getAppAuthenticationSecret())->toBeNull();
});

it('keeps two-factor on when disabling is attempted with the wrong password', function () {
    $admin = $this->signInAsAdmin();
    [$secret] = adminEnrolTotp($admin);

    $this->panelComponent(EditProfile::class)
        ->call('mountAction', 'disableAppAuthentication', [], ['schemaComponent' => 'content.app'])
        ->set('mountedActions.0.data.password', 'wrong-password')
        ->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->call('callMountedAction')
        ->assertHasErrors();

    expect($admin->fresh()->getAppAuthenticationSecret())->toBe($secret);
});

it('keeps two-factor on when disabling is attempted with a wrong code', function () {
    $admin = $this->signInAsAdmin();
    [$secret] = adminEnrolTotp($admin);

    $this->panelComponent(EditProfile::class)
        ->call('mountAction', 'disableAppAuthentication', [], ['schemaComponent' => 'content.app'])
        ->set('mountedActions.0.data.password', 'password')
        ->set('mountedActions.0.data.code', '000000')
        ->call('callMountedAction')
        ->assertHasErrors();

    expect($admin->fresh()->getAppAuthenticationSecret())->toBe($secret);
});

it('limits repeated attempts to disable two-factor', function () {
    $admin = $this->signInAsAdmin();
    [$secret] = adminEnrolTotp($admin);

    $profile = $this->panelComponent(EditProfile::class)
        ->call('mountAction', 'disableAppAuthentication', [], ['schemaComponent' => 'content.app']);
    foreach (range(1, 6) as $_) {
        $profile->set('mountedActions.0.data.password', 'wrong')
            ->set('mountedActions.0.data.code', '000000')
            ->call('callMountedAction');
    }
    $profile->set('mountedActions.0.data.password', 'password')
        ->set('mountedActions.0.data.code', adminCurrentCode($admin, $secret))
        ->call('callMountedAction');

    expect($admin->fresh()->getAppAuthenticationSecret())->toBe($secret);
});

it('throttles repeated wrong authenticator codes at the challenge', function () {
    $admin = $this->makeAdmin(['email' => 'owner@example.test']);
    [$secret] = adminEnrolTotp($admin);

    $login = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate');

    foreach (range(1, 8) as $attempt) {
        $login->set('data.multiFactor.app.code', str_pad((string) $attempt, 6, '0', STR_PAD_LEFT))->call('authenticate');
    }
    $login->set('data.multiFactor.app.code', adminCurrentCode($admin, $secret))->call('authenticate');

    $this->assertGuest();
});
