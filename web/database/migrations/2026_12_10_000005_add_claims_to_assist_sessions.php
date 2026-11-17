<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Assist's budget (v1.9, review of the reservations): the budget the last relay token granted a session and when
 * (its unspent part is held back from what is left while the session is on), and when a report stuck in the queue was
 * queued again (once; after that it is marked failed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->unsignedInteger('claimed_cents')->nullable()->after('last_token_at');
            $table->timestamp('claimed_at')->nullable()->after('claimed_cents');
            $table->timestamp('report_requeued_at')->nullable()->after('report_status');
        });
    }

    public function down(): void
    {
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->dropColumn(['claimed_cents', 'claimed_at', 'report_requeued_at']);
        });
    }
};
