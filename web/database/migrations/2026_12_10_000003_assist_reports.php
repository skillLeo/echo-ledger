<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Assist's report (v1.9 L7): the project memory lines a session's report added, so making the report again (or
 * the call's own notes) replaces only its own lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_memory_items', function (Blueprint $table): void {
            $table->string('assist_session_id', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('client_memory_items', function (Blueprint $table): void {
            $table->dropIndex(['assist_session_id']);
            $table->dropColumn('assist_session_id');
        });
    }
};
