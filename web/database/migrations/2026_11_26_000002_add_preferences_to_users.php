<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own choices (since v1.5, Phase 1C): which emails they want per category (Profile → Emails; empty means each
 * category's default) and how the console's Library is grouped and sorted in each of its views. Both start empty, so
 * nothing changes for anyone until they choose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('email_preferences')->nullable();
            $table->json('console_preferences')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['email_preferences', 'console_preferences']);
        });
    }
};
