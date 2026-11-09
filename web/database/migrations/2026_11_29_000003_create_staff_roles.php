<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Staff roles (since v1.6, P2.5): who works on the platform and what each may do on the admin panel. Four roles come
 * ready (Admin, Support, Billing, Read-only) and more can be made; the super admin (`users.is_super_admin`) keeps every
 * permission and needs no role. Nobody is given a role here, so nothing changes for anyone until one is assigned;
 * down() removes the column and the table.
 */
return new class extends Migration
{
    /** @var array<string, array{0: string, 1: list<string>}> */
    private const ROLES = [
        'admin' => ['Admin', ['customers.view', 'customers.manage', 'customers.support', 'customers.delete', 'plans.view', 'plans.manage', 'upgrades.manage', 'usage.view', 'audit.view', 'devices.reports']],
        'support' => ['Support', ['customers.view', 'customers.support', 'usage.view']],
        'billing' => ['Billing', ['customers.view', 'plans.view', 'plans.manage', 'upgrades.manage', 'usage.view']],
        'read_only' => ['Read-only', ['customers.view', 'plans.view', 'usage.view', 'audit.view']],
    ];

    public function up(): void
    {
        Schema::create('staff_roles', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 40)->unique();
            $table->string('name', 80);
            $table->json('permissions');
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        $now = now();
        foreach (self::ROLES as $key => [$name, $permissions]) {
            DB::table('staff_roles')->insert(['key' => $key, 'name' => $name, 'permissions' => json_encode($permissions), 'is_system' => true, 'created_at' => $now, 'updated_at' => $now]);
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('staff_role_id')->nullable()->constrained('staff_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('staff_role_id');
        });
        Schema::dropIfExists('staff_roles');
    }
};
