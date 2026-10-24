<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;

it('redirects guests away from the admin dashboard', function () {
    $this->get('/admin')->assertRedirect();
});

it('serves the admin login page', function () {
    $this->get('/admin/login')->assertOk();
});

it('grants panel access only to super admins', function () {
    $panel = Filament::getPanel('admin');

    $regular = User::factory()->create();
    expect($regular->canAccessPanel($panel))->toBeFalse();

    $admin = User::factory()->create();
    $admin->forceFill(['is_super_admin' => true])->save(); // guarded flag, set explicitly
    expect($admin->fresh()->canAccessPanel($panel))->toBeTrue();
});

it('never mass-assigns the super-admin flag', function () {
    $user = User::factory()->create(['is_super_admin' => false]);
    $user->fill(['is_super_admin' => true]);

    expect($user->is_super_admin)->toBeFalse();
});
