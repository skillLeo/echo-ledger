<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automatic titles (docs/API.md "Easy, trustworthy, clear"): a title the server wrote from what was said is marked, so a
 * title a person set is never replaced. Every existing title was set by a person or at upload, so they all start false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->boolean('title_auto')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn('title_auto');
        });
    }
};
