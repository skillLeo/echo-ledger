<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Assist's report and its numbers (v1.9 L7, L8): the project memory lines a session's report added, so making the
 * report again (or the call's own notes) replaces only its own lines; and the plan a session and each model call ran
 * on (the sold plan's key, such as free, pro, business or personal), so the admin panel's cost per plan stays as it was
 * when the plan changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_memory_items', function (Blueprint $table): void {
            $table->string('assist_session_id', 26)->nullable()->index();
        });
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->string('plan', 32)->nullable()->after('mode');
        });
        Schema::table('assist_usage', function (Blueprint $table): void {
            $table->string('plan', 32)->nullable()->after('mode');
            $table->index('occurred_at');
        });
    }

    public function down(): void
    {
        Schema::table('assist_usage', function (Blueprint $table): void {
            $table->dropIndex(['occurred_at']);
            $table->dropColumn('plan');
        });
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->dropColumn('plan');
        });
        Schema::table('client_memory_items', function (Blueprint $table): void {
            $table->dropIndex(['assist_session_id']);
            $table->dropColumn('assist_session_id');
        });
    }
};
