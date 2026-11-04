<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests sent to a provider that paces its use (since v1.5: Groq), so its rate limits can be counted over rolling
 * windows per API key: which key (the start of its SHA-256, never the key), the audio it billed and when. A new table
 * only; rows are kept two days (model:prune).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_requests', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('key_id', 32);
            $table->unsignedInteger('audio_ms');
            // An explicit default: MariaDB would otherwise add ON UPDATE CURRENT_TIMESTAMP to the first timestamp column.
            $table->timestamp('requested_at')->useCurrent();
            $table->index(['provider', 'key_id', 'requested_at']);
            $table->index('requested_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('provider_requests');
    }
};
