<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-p, "Needs you": a recording is stuck when its processing has gone on for more than 24 hours since the
 * current attempt began, so a retry starts the clock again. transcripts.attempt_started_at: when the current run of
 * the transcript was started (FinalTranscriptCoordinator::start, also its Retry); recordings.upload_started_at: when
 * the current upload began (the upload slot made, or the first part after a failed upload). Null on rows from before
 * (the recording's end, else its start, is used then). Each column is added on its own, so a half-run migration
 * finishes; down drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('transcripts', 'attempt_started_at')) {
            Schema::table('transcripts', fn (Blueprint $table) => $table->timestamp('attempt_started_at')->nullable());
        }
        if (! Schema::hasColumn('recordings', 'upload_started_at')) {
            Schema::table('recordings', fn (Blueprint $table) => $table->timestamp('upload_started_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('transcripts', 'attempt_started_at')) {
            Schema::table('transcripts', fn (Blueprint $table) => $table->dropColumn('attempt_started_at'));
        }
        if (Schema::hasColumn('recordings', 'upload_started_at')) {
            Schema::table('recordings', fn (Blueprint $table) => $table->dropColumn('upload_started_at'));
        }
    }
};
