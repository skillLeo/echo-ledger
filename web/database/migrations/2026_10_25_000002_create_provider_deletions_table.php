<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Provider-side copies (uploaded audio and transcripts) that could not be deleted yet; stt:reconcile retries them.
 * New table only, so it is safe on a production database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_deletions', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('provider_job_id', 128);
            $table->string('provider_transcript_id', 128)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamps();
            $table->unique(['provider', 'provider_job_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_deletions');
    }
};
