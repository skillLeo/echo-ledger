<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Since v1.5: the last device check an app chose to send (docs/API.md `POST /api/devices/report`), one per device. A
 * new table only, gone with its device.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_reports', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('device_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('platform', 16);
            $table->string('brand', 64)->nullable();
            $table->string('model', 64)->nullable();
            $table->string('os_version', 64)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->json('checks');
            $table->timestamp('reported_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_reports');
    }
};
