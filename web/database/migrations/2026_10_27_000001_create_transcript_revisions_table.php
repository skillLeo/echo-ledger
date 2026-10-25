<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transcript editing (v1.1): the revisions of an edited transcript (the provider's original is kept as the first
 * one), when it was last edited, and speaker names per session and remembered per client.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transcript_revisions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('transcript_id')->constrained()->cascadeOnDelete();
            $table->string('source', 12); // original | edit | replace | restore
            $table->json('segments');
            $table->longText('text')->nullable();
            $table->string('author', 120)->nullable();
            $table->timestamps();
            $table->index(['transcript_id', 'created_at']);
        });
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->timestamp('edited_at')->nullable()->after('completed_at');
        });
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->json('speaker_names')->nullable()->after('pauses');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->json('speaker_names')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn('speaker_names'));
        Schema::table('capture_sessions', fn (Blueprint $table) => $table->dropColumn('speaker_names'));
        Schema::table('transcripts', fn (Blueprint $table) => $table->dropColumn('edited_at'));
        Schema::dropIfExists('transcript_revisions');
    }
};
