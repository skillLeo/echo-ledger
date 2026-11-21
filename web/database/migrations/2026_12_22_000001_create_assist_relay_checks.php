<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Plan B4: the Live Assist relay's health checks, one row each 5 minutes (assist:relay-check, `scheduled`) plus the
 * admin's "Check the relay now" (`manual`), kept 7 days, for the admin's up/down, uptime and outages. A row holds the
 * host asked, whether it answered ok, how long it took, the version it named and the check's own fixed sentence when it
 * did not: never anything the relay sent back beyond that, and no session data (its /healthz holds none).
 * Also two indexes for the admin's "now" counts of assist_sessions (running ones by status, and those started lately).
 * Down drops the table and those two indexes, and nothing else.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('assist_relay_checks')) {
            Schema::create('assist_relay_checks', function (Blueprint $table): void {
                $table->id();
                $table->string('host', 255);
                $table->timestamp('checked_at');
                // scheduled (assist:relay-check) or manual ("Check the relay now"): uptime counts the scheduled ones only.
                $table->string('source', 9)->default('scheduled');
                $table->boolean('ok');
                $table->unsignedInteger('ms');
                $table->string('version', 40)->nullable();
                $table->string('error', 120)->nullable();
                $table->index(['host', 'source', 'checked_at']);
                $table->index('checked_at');
            });
        }
        Schema::table('assist_sessions', function (Blueprint $table): void {
            if (! Schema::hasIndex('assist_sessions', 'assist_sessions_status_ended_at_index')) {
                $table->index(['status', 'ended_at'], 'assist_sessions_status_ended_at_index');
            }
            if (! Schema::hasIndex('assist_sessions', 'assist_sessions_started_at_index')) {
                $table->index('started_at', 'assist_sessions_started_at_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('assist_sessions', function (Blueprint $table): void {
            if (Schema::hasIndex('assist_sessions', 'assist_sessions_status_ended_at_index')) {
                $table->dropIndex('assist_sessions_status_ended_at_index');
            }
            if (Schema::hasIndex('assist_sessions', 'assist_sessions_started_at_index')) {
                $table->dropIndex('assist_sessions_started_at_index');
            }
        });
        Schema::dropIfExists('assist_relay_checks');
    }
};
