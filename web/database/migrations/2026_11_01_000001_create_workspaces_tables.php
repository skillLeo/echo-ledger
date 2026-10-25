<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B20 step 1 of 3 (docs/HANDOFF.md "B20 design"): plans, workspaces and memberships, plus a nullable, indexed
 * `workspace_id` on every table that owns user data. Additive only; the backfill and NOT NULL come in the next two.
 */
return new class extends Migration
{
    /** Tables that get `workspace_id` (children are reached through these). */
    public const SCOPED = ['devices', 'device_pairing_codes', 'clients', 'capture_sessions', 'usage_ledger'];

    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 32)->unique();
            $table->string('name', 80);
            $table->unsignedInteger('monthly_minutes')->nullable(); // null = no limit
            $table->unsignedBigInteger('storage_bytes')->nullable();
            $table->unsignedInteger('max_devices')->nullable();
            $table->timestamps();
        });
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->timestamps();
        });
        Schema::create('workspace_user', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16); // owner | member
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
            $table->index('user_id');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->foreignId('current_workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
        });
        foreach (self::SCOPED as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('workspace_id')->nullable()->index();
            });
        }
    }

    public function down(): void
    {
        foreach (self::SCOPED as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropIndex($name.'_workspace_id_index');
                $table->dropColumn('workspace_id');
            });
        }
        Schema::table('users', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('current_workspace_id');
        });
        Schema::dropIfExists('workspace_user');
        Schema::dropIfExists('workspaces');
        Schema::dropIfExists('plans');
    }
};
