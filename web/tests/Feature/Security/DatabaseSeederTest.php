<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Hash;

/** Runs the seeder in the given environment with the given variables, then restores both. */
function seedAs(string $environment, array $variables): void
{
    $variables += ['DEMO_SEED' => 'false'];
    app()->detectEnvironment(fn () => $environment);
    foreach ($variables as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        (new DatabaseSeeder)->run();
    } finally {
        foreach (array_keys($variables) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        app()->detectEnvironment(fn () => 'testing');
    }
}

function seededAdmin(): User
{
    return User::query()->where('is_super_admin', true)->sole();
}

it('creates the local demo admin with the documented password only in local development', function () {
    seedAs('local', []);

    expect(Hash::check(DatabaseSeeder::LOCAL_DEMO_PASSWORD, seededAdmin()->password))->toBeTrue();
});

it('creates a new admin with a random password elsewhere when no password is configured', function () {
    seedAs('production', []);

    $admin = seededAdmin();
    expect(Hash::check(DatabaseSeeder::LOCAL_DEMO_PASSWORD, $admin->password))->toBeFalse()
        ->and($admin->email)->toBe('owner@echoledger.local');
});

it('refuses the documented demo password and short passwords outside local development', function (string $password) {
    seedAs('production', ['ADMIN_PASSWORD' => $password]);

    expect(Hash::check($password, seededAdmin()->password))->toBeFalse();
})->with(['demo password' => DatabaseSeeder::LOCAL_DEMO_PASSWORD, 'short' => 'eleven-char']);

it('uses a configured password of twelve or more characters in any environment', function () {
    seedAs('production', ['ADMIN_EMAIL' => 'owner@example.test', 'ADMIN_PASSWORD' => 'a-long-configured-passphrase']);

    $admin = seededAdmin();
    expect($admin->email)->toBe('owner@example.test')
        ->and(Hash::check('a-long-configured-passphrase', $admin->password))->toBeTrue();
});

it('keeps the password of an existing admin when the seeder runs again without one', function () {
    $existing = User::factory()->create(['email' => 'owner@echoledger.local', 'password' => 'the-password-already-in-use']);

    seedAs('production', []);

    $admin = seededAdmin();
    expect($admin->id)->toBe($existing->id)
        ->and(Hash::check('the-password-already-in-use', $admin->password))->toBeTrue();
});

it('does not load the demo library outside local development unless asked', function () {
    putenv('DEMO_SEED');
    unset($_ENV['DEMO_SEED'], $_SERVER['DEMO_SEED']);
    app()->detectEnvironment(fn () => 'production');

    try {
        (new DatabaseSeeder)->run(); // would fail without a container if it tried to call DemoSeeder
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }

    expect(seededAdmin()->captureSessions()->count())->toBe(0);
});
