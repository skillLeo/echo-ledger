<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Sign in again" (since v1.6, P3, docs/API.md "Signing a device in again"): a pairing code can be bound to one
 * signed-out device row, which it brings back instead of making a new one. Removing the row from the list deletes its
 * codes. Every existing code stays unbound; down() removes the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_pairing_codes', function (Blueprint $table): void {
            $table->foreignUlid('bound_device_id')->nullable()->constrained('devices')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('device_pairing_codes', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bound_device_id');
        });
    }
};
