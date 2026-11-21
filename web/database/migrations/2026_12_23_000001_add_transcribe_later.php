<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 1.8.0-e, "Audio only and Transcribe later": capture_sessions.transcribe (the person's choice at create or
 * finish; true for every recording before, as the apps that send nothing keep it) and clients.transcribe_calls
 * (`ask` or `always`, "Always transcribe calls in this project"; `ask` for every project before). The session status
 * `audio_only` fits the existing status column. Safe to run again; down drops only these two columns, after turning audio-only recordings into completed ones (the older release
 * cannot read that status).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('capture_sessions', 'transcribe')) {
            Schema::table('capture_sessions', function (Blueprint $table): void {
                $table->boolean('transcribe')->default(true);
            });
        }
        if (! Schema::hasColumn('clients', 'transcribe_calls')) {
            Schema::table('clients', function (Blueprint $table): void {
                $table->string('transcribe_calls', 8)->default('ask');
            });
        }
    }

    public function down(): void
    {
        // The release before this one has no `audio_only`: its recordings show as finished without a transcript, as
        // they would have after Save audio on a server without the choice. Their audio stays.
        DB::table('capture_sessions')->where('status', 'audio_only')->update(['status' => 'completed']);
        if (Schema::hasColumn('clients', 'transcribe_calls')) {
            Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('transcribe_calls'));
        }
        if (Schema::hasColumn('capture_sessions', 'transcribe')) {
            Schema::table('capture_sessions', fn (Blueprint $table) => $table->dropColumn('transcribe'));
        }
    }
};
