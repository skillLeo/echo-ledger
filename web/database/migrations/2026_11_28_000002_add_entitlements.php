<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Entitlements (since v1.6, P1, docs/API.md "Entitlements"): plans gain what the apps show (a badge, its colour, a
 * description and a display price), whether they are on offer and in which order, the plan new sign-ups get, trial
 * minutes, the longest recording, uploads a day, the engines they may use, and from when their retention applies
 * (null: to every recording, as before). A workspace may be on a plan until a date (then the default plan), and a
 * person may have their own overrides of features and limits. Nothing changes for any workspace here: the plans' data
 * is the next migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->string('badge', 16)->nullable();
            $table->string('color', 9)->nullable();
            $table->string('description', 500)->nullable();
            $table->string('price_label', 80)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('on_offer')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedInteger('trial_minutes')->default(0);
            $table->unsignedInteger('max_file_minutes')->nullable();
            $table->unsignedInteger('uploads_per_day')->nullable();
            $table->json('engines')->nullable();               // null = every engine
            $table->timestamp('retention_from')->nullable();   // the plan's retention applies to recordings made since
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->timestamp('plan_expires_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table): void {
            // {"features": {"views": true}, "limits": {"devices": 10, "storage_bytes": null}}: present keys win over the plan
            $table->json('entitlement_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('entitlement_overrides');
        });
        Schema::table('workspaces', function (Blueprint $table): void {
            $table->dropColumn('plan_expires_at');
        });
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['badge', 'color', 'description', 'price_label', 'sort_order', 'on_offer', 'is_default', 'trial_minutes',
                'max_file_minutes', 'uploads_per_day', 'engines', 'retention_from']);
        });
    }
};
