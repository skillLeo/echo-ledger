<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Assist's settings (v1.9 L8, the console's Live Assist → Settings): a person's defaults per meeting type (mode,
 * hint level, language help), which a new prep starts with when it names none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('assist_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('assist_settings');
        });
    }
};
