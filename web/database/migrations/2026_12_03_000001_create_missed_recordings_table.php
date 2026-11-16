<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Since v1.8: calls an app saw but did not record (docs/API.md `POST /api/devices/missed-recordings`), one row per
 * call. Only the call's app, when it started, how long it lasted and why nothing was recorded: no names, numbers, audio
 * or free text. A new table only. A row goes with its person and its workspace, and outlives a removed device (its device
 * becomes empty) so the admin counts keep it; the same event sent again (the device's own `client_ref`) is the same row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('missed_recordings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('client_ref', 64);
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('source_app', 32);
            // An explicit default: MySQL and MariaDB would otherwise give the first NOT NULL TIMESTAMP "ON UPDATE".
            $table->timestamp('call_started_at')->useCurrent();
            $table->unsignedInteger('call_duration_s')->nullable();
            $table->string('reason', 24);
            $table->timestamp('created_at')->useCurrent();
            // One row per event of a device, however often it is sent.
            $table->unique(['device_id', 'client_ref']);
            // The admin counts: the last 30 days, per device and reason.
            $table->index(['call_started_at', 'device_id', 'reason'], 'missed_recordings_counts_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('missed_recordings');
    }
};
