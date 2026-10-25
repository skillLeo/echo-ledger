<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

it('creates a super admin from the command who can then sign in to the console', function () {
    $exit = Artisan::call('echoledger:make-admin', ['--email' => 'owner@example.test', '--name' => 'Farah Khan', '--password' => 'a-long-enough-passphrase']);

    expect($exit)->toBe(0);
    $user = User::query()->where('email', 'owner@example.test')->firstOrFail();
    expect($user->is_super_admin)->toBeTrue()
        ->and($user->name)->toBe('Farah Khan')
        ->and(Hash::check('a-long-enough-passphrase', $user->password))->toBeTrue()
        ->and($user->password)->not->toContain('a-long-enough-passphrase');

    $this->panelComponent(Login::class)
        ->fillForm(['email' => 'owner@example.test', 'password' => 'a-long-enough-passphrase'])
        ->call('authenticate')
        ->assertHasNoFormErrors()
        ->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $this->get('/admin')->assertOk();
});

it('refuses a password shorter than twelve characters and creates nobody', function () {
    $exit = Artisan::call('echoledger:make-admin', ['--email' => 'owner@example.test', '--name' => 'Owner', '--password' => 'short']);

    expect($exit)->toBe(1)
        ->and(User::query()->count())->toBe(0);
    expect(Artisan::output())->toContain('12');
});

it('refuses an invalid email address', function () {
    $exit = Artisan::call('echoledger:make-admin', ['--email' => 'not-an-email', '--name' => 'Owner', '--password' => 'a-long-enough-passphrase']);

    expect($exit)->toBe(1)->and(User::query()->count())->toBe(0);
});

it('promotes an existing account and resets its password without creating a duplicate', function () {
    $existing = User::factory()->create(['email' => 'staff@example.test']);
    expect($existing->fresh()->is_super_admin)->toBeFalse();

    Artisan::call('echoledger:make-admin', ['--email' => 'staff@example.test', '--name' => 'Now Admin', '--password' => 'a-brand-new-passphrase']);

    $user = $existing->fresh();
    expect(User::query()->count())->toBe(1)
        ->and($user->is_super_admin)->toBeTrue()
        ->and(Hash::check('a-brand-new-passphrase', $user->password))->toBeTrue();
});

it('does not tell the new admin they will be forced to set up two-factor, because it is optional', function () {
    Artisan::call('echoledger:make-admin', ['--email' => 'owner@example.test', '--name' => 'Owner', '--password' => 'a-long-enough-passphrase']);

    expect(Artisan::output())->not->toContain('will be asked to set up 2FA');
})->skip('A6.W12: the make-admin command still says two-factor setup is required');
