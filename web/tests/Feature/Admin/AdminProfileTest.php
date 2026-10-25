<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\Pages\EditProfile;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('shows the signed-in admin their own name and email', function () {
    $admin = $this->signInAsAdmin(['name' => 'Farah Khan', 'email' => 'farah@example.test']);

    $this->panelComponent(EditProfile::class)
        ->assertFormSet(['name' => 'Farah Khan', 'email' => 'farah@example.test'])
        ->assertSee('Profile');

    $this->get('/admin/profile')->assertOk()->assertSee('Farah Khan', false);
    expect($admin->is_super_admin)->toBeTrue();
});

it('never renders the password hash or remember token on the profile page', function () {
    $admin = $this->signInAsAdmin();
    $admin->forceFill(['remember_token' => 'remember-token-value-1234567890'])->save();

    $html = $this->panelComponent(EditProfile::class)->html();
    $page = $this->get('/admin/profile')->assertOk()->getContent();

    foreach ([$html, $page] as $output) {
        expect($output)->not->toContain($admin->fresh()->password)
            ->not->toContain('$2y$')
            ->not->toContain('remember-token-value-1234567890');
    }
});

it('updates the display name', function () {
    $admin = $this->signInAsAdmin(['name' => 'Old Name']);

    $this->panelComponent(EditProfile::class)
        ->fillForm(['name' => 'New Name'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified();

    expect($admin->fresh()->name)->toBe('New Name');
});

it('requires a name and an email', function () {
    $this->signInAsAdmin();

    $this->panelComponent(EditProfile::class)
        ->fillForm(['name' => '', 'email' => ''])
        ->call('save')
        ->assertHasFormErrors(['name' => 'required', 'email' => 'required']);
});

it('rejects an over-long name', function () {
    $admin = $this->signInAsAdmin(['name' => 'Short']);

    $this->panelComponent(EditProfile::class)
        ->fillForm(['name' => str_repeat('n', 256)])
        ->call('save')
        ->assertHasFormErrors(['name' => 'max']);

    expect($admin->fresh()->name)->toBe('Short');
});

it('rejects an email that another account already uses', function () {
    $this->makeRegularUser(['email' => 'taken@example.test']);
    $admin = $this->signInAsAdmin(['email' => 'owner@example.test']);

    $this->panelComponent(EditProfile::class)
        ->fillForm(['email' => 'taken@example.test'])
        ->call('save')
        ->assertHasFormErrors(['email' => 'unique']);

    expect($admin->fresh()->email)->toBe('owner@example.test');
});

it('rejects a malformed email', function () {
    $this->signInAsAdmin();

    $this->panelComponent(EditProfile::class)
        ->fillForm(['email' => 'not-an-email'])
        ->call('save')
        ->assertHasFormErrors(['email' => 'email']);
});

it('keeps the same email without tripping the unique rule', function () {
    $this->signInAsAdmin(['email' => 'owner@example.test']);

    $this->panelComponent(EditProfile::class)
        ->fillForm(['name' => 'Owner Renamed'])
        ->call('save')
        ->assertHasNoFormErrors();
});

it('changes the password when the current password is confirmed', function () {
    $admin = $this->signInAsAdmin(['email' => 'owner@example.test']);

    $this->panelComponent(EditProfile::class)
        ->fillForm([
            'password' => 'a-much-longer-passphrase',
            'passwordConfirmation' => 'a-much-longer-passphrase',
            'currentPassword' => 'password',
        ])
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertSet('data.password', null)
        ->assertSet('data.passwordConfirmation', null);

    $stored = $admin->fresh()->password;
    expect(Hash::check('a-much-longer-passphrase', $stored))->toBeTrue()
        ->and(Hash::check('password', $stored))->toBeFalse()
        ->and($stored)->not->toContain('a-much-longer-passphrase');

    // The new password signs in; the old one no longer does.
    auth()->logout();
    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'password'])
        ->call('authenticate')
        ->assertHasFormErrors(['email']);
    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'a-much-longer-passphrase'])
        ->call('authenticate')
        ->assertHasNoFormErrors();
    $this->assertAuthenticatedAs($admin);
});

it('refuses a password change without the right current password', function () {
    $admin = $this->signInAsAdmin();
    $before = $admin->password;

    $this->panelComponent(EditProfile::class)
        ->fillForm([
            'password' => 'a-much-longer-passphrase',
            'passwordConfirmation' => 'a-much-longer-passphrase',
            'currentPassword' => 'guess',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword']);

    expect($admin->fresh()->password)->toBe($before);
});

it('refuses a password change when the current password is left blank', function () {
    $admin = $this->signInAsAdmin();
    $before = $admin->password;

    $this->panelComponent(EditProfile::class)
        ->fillForm([
            'password' => 'a-much-longer-passphrase',
            'passwordConfirmation' => 'a-much-longer-passphrase',
            'currentPassword' => '',
        ])
        ->call('save')
        ->assertHasFormErrors(['currentPassword' => 'required']);

    expect($admin->fresh()->password)->toBe($before);
});

it('refuses a new password that is too short', function () {
    $admin = $this->signInAsAdmin();
    $before = $admin->password;

    $this->panelComponent(EditProfile::class)
        ->fillForm(['password' => 'short', 'passwordConfirmation' => 'short', 'currentPassword' => 'password'])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect($admin->fresh()->password)->toBe($before);
});

it('refuses a new password whose confirmation does not match', function () {
    $admin = $this->signInAsAdmin();
    $before = $admin->password;

    $this->panelComponent(EditProfile::class)
        ->fillForm([
            'password' => 'a-much-longer-passphrase',
            'passwordConfirmation' => 'something-else-entirely',
            'currentPassword' => 'password',
        ])
        ->call('save')
        ->assertHasFormErrors(['password' => 'same']);

    expect($admin->fresh()->password)->toBe($before);
});

it('offers a show and hide control on the password fields', function () {
    $this->signInAsAdmin();

    $html = $this->get('/admin/profile')->assertOk()->getContent();

    expect($html)->toContain('aria-label="Show password"')->toContain('aria-label="Hide password"');
});

it('does not let the profile form change the super-admin flag', function () {
    $admin = $this->signInAsAdmin();

    $this->panelComponent(EditProfile::class)
        ->set('data.is_super_admin', false)
        ->set('data.name', 'Still Admin')
        ->call('save');

    expect($admin->fresh()->is_super_admin)->toBeTrue()
        ->and($admin->fresh()->name)->toBe('Still Admin');
});

it('limits how often the profile can be saved', function () {
    $admin = $this->signInAsAdmin(['name' => 'Start']);

    $profile = $this->panelComponent(EditProfile::class);
    foreach (range(1, 5) as $i) {
        $profile->fillForm(['name' => "Name {$i}"])->call('save');
    }
    $profile->fillForm(['name' => 'Name six'])->call('save');

    expect($admin->fresh()->name)->toBe('Name 5');
});

it('has no file upload field and only a narrow set of editable attributes', function () {
    $this->signInAsAdmin();

    $html = $this->get('/admin/profile')->assertOk()->getContent();

    expect($html)->not->toContain('type="file"');
    expect(User::query()->getModel()->getFillable())->toBe(['name', 'email', 'password']);
});
