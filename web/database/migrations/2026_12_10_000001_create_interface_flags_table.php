<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Since 2.0.0-a: the interface flags (App\Services\InterfaceFlags). One row per flag the super admin has set: on for
 * everyone, for a share of accounts, for workspaces or for people. A flag without a row is off. `users.ui_classic` is a
 * person's "Switch back to classic", which turns every ui_v2.* flag off for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('interface_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('flag', 64)->unique();
            $table->boolean('everyone')->default(false);
            $table->unsignedTinyInteger('percent')->default(0);
            $table->json('workspace_ids')->nullable();
            $table->json('user_ids')->nullable();
            $table->timestamps();
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('ui_classic')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ui_classic');
        });
        Schema::dropIfExists('interface_flags');
    }
};
