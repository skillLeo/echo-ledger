<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Assist 1.9.0-q: how a session's live messages travel, chosen at its start for its whole life: `relay` (the
 * WebSocket relay) or `http` (HTTP mode on this server). Sessions from before are the relay's.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->string('transport', 8)->default('relay')->after('platform');
        });
    }

    public function down(): void
    {
        Schema::table('assist_sessions', function (Blueprint $table): void {
            $table->dropColumn('transport');
        });
    }
};
