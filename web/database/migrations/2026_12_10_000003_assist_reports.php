<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Assist's report (v1.9 L7, docs/API_ASSIST.md 1.9.0-h): when the relay's facts ledger and running summary were
 * written (an older one arriving late never replaces a newer one), and the project memory lines a session's report
 * added (so making the report again, or the call's own notes, replace only their own lines).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->timestamp('facts_at')->nullable()->after('facts');
            $table->timestamp('summary_at')->nullable()->after('summary');
        });
        Schema::table('client_memory_items', function (Blueprint $table): void {
            $table->string('assist_session_id', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('client_memory_items', function (Blueprint $table): void {
            $table->dropIndex(['assist_session_id']);
            $table->dropColumn('assist_session_id');
        });
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->dropColumn(['facts_at', 'summary_at']);
        });
    }
};
