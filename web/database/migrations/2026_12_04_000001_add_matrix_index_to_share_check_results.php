<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Since the v1.8.1 audit (R26): one index on what Admin → Diagnostics → Screen-share checks groups by (the system, its
 * build, the app version, the method, the meeting app, its version and the share mode), then the result and the date
 * it counts and orders by, so the matrix is read from the index instead of the whole table. An index only; the rows
 * stay as they are.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('share_check_results', function (Blueprint $table): void {
            $table->index(['platform', 'os_build', 'app_version', 'method', 'meeting_app', 'meeting_app_version', 'share_mode', 'result', 'created_at'],
                'share_check_results_group_index');
        });
    }

    public function down(): void
    {
        Schema::table('share_check_results', function (Blueprint $table): void {
            $table->dropIndex('share_check_results_group_index');
        });
    }
};
