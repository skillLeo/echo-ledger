<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /** Documented password of the local demo account; refused everywhere except APP_ENV=local. */
    public const LOCAL_DEMO_PASSWORD = 'change-me-now-please';

    /**
     * Creates the super admin from ADMIN_EMAIL / ADMIN_PASSWORD, then the demo library when DEMO_SEED allows it.
     *
     * Local development falls back to the documented demo password. Any other environment never uses it: without
     * a usable ADMIN_PASSWORD (12 or more characters) a new admin gets a random password that is printed once, and
     * an existing admin keeps the password it has. Production: prefer `php artisan echoledger:make-admin`.
     */
    public function run(): void
    {
        $local = app()->environment('local');
        $email = (string) env('ADMIN_EMAIL', 'owner@echoledger.local');
        $admin = User::query()->firstOrNew(['email' => $email]);
        $admin->name = (string) env('ADMIN_NAME', $admin->name ?? 'Owner');

        $password = $this->configuredPassword($local);
        if ($password === null && $local) {
            $password = self::LOCAL_DEMO_PASSWORD;
        }
        if ($password === null && ! $admin->exists) {
            $password = Str::password(24, symbols: false);
            $this->command?->warn("Generated a password for {$email} (shown only once): {$password}");
        }
        if ($password !== null) {
            $admin->password = $password;
        }
        $admin->is_super_admin = true;
        $admin->save();

        if (filter_var(env('DEMO_SEED', $local), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoSeeder::class);
        }
    }

    private function configuredPassword(bool $local): ?string
    {
        $configured = env('ADMIN_PASSWORD');
        if (! is_string($configured) || $configured === '') {
            return null;
        }
        if (! $local && ($configured === self::LOCAL_DEMO_PASSWORD || mb_strlen($configured) < 12)) {
            $this->command?->warn('ADMIN_PASSWORD is ignored here: use 12 or more characters, not the documented demo password.');

            return null;
        }

        return $configured;
    }
}
