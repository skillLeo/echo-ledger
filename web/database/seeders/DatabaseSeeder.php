<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates the super admin from ADMIN_EMAIL / ADMIN_PASSWORD (or a local default), then the demo library
     * when DEMO_SEED is not false. Production: prefer `php artisan echoledger:make-admin`.
     */
    public function run(): void
    {
        $email = (string) env('ADMIN_EMAIL', 'owner@echoledger.local');
        $admin = User::query()->firstOrNew(['email' => $email]);
        $admin->fill(['name' => (string) env('ADMIN_NAME', 'Owner'), 'password' => (string) env('ADMIN_PASSWORD', 'change-me-now-please')]);
        $admin->is_super_admin = true;
        $admin->save();

        if (filter_var(env('DEMO_SEED', true), FILTER_VALIDATE_BOOL)) {
            $this->call(DemoSeeder::class);
        }
    }
}
