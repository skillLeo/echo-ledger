<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person's own transcription key (since v1.6, P1.3, docs/API.md "Your own transcription key"): the Free plan
 * transcribes with the person's own Groq key. It is kept encrypted with the app's key (the `encrypted` cast), for that
 * person's recordings only, never sent back; the last four characters are kept apart to show which key it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->text('transcription_key')->nullable();
            $table->string('transcription_key_provider', 16)->nullable();
            $table->string('transcription_key_last4', 4)->nullable();
            $table->timestamp('transcription_key_checked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['transcription_key', 'transcription_key_provider', 'transcription_key_last4', 'transcription_key_checked_at']);
        });
    }
};
