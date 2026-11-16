<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Since v1.8.1: Share Check results a desktop app chose to send (docs/API.md "Screen-share privacy",
 * `POST /api/diagnostics/share-checks`), one row per check. Only what was checked and how it went: no pictures, window
 * titles or window contents. A new table only; a result goes with its person and outlives a removed device (its device
 * becomes empty) so the matrix keeps its counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_check_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->string('platform', 16);
            $table->string('os_build', 64);
            $table->string('app_version', 32);
            $table->string('method', 24);
            $table->string('result', 8);
            $table->string('reason', 24)->nullable();
            $table->string('meeting_app', 16)->nullable();
            $table->string('meeting_app_version', 32)->nullable();
            $table->string('share_mode', 8)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
            // The admin matrix groups by these.
            $table->index(['platform', 'os_build', 'app_version', 'method'], 'share_check_results_matrix_index');
            $table->index(['meeting_app', 'meeting_app_version', 'share_mode'], 'share_check_results_meeting_index');
            $table->index('result');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_check_results');
    }
};
