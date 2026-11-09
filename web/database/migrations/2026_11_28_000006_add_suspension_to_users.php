<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The suspend switch (since v1.6, P1.5): a suspended account signs in nowhere and its devices, which keep their tokens,
 * get `403 account_suspended` on every call until the super admin lifts it. Nobody starts suspended.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspended_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['suspended_at', 'suspended_reason']);
        });
    }
};
