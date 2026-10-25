<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bookmarks set during or after a recording (docs/API.md "markers") and the paused stretches of a recording
 * (`capture_sessions.pauses`). Additive, so existing rows stay valid; `down()` removes both.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_markers', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('capture_session_id')->constrained('capture_sessions')->cascadeOnDelete();
            $table->unsignedInteger('at_ms');
            $table->string('tag', 40)->nullable();
            $table->string('note', 200)->nullable();
            $table->string('source', 8)->default('device');
            $table->timestamps();
            $table->index(['capture_session_id', 'at_ms']);
            $table->index('tag');
        });

        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->json('pauses')->nullable()->after('duration_ms');
        });
    }

    public function down(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn('pauses');
        });
        Schema::dropIfExists('session_markers');
    }
};
