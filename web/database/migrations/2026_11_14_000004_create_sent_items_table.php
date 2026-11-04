<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sending to a teammate (v1.5): a recording or a project one person sent another account on this server. The
 * recipient reads it (Shared with me, the `shared` smart folder) and may save a copy into their own workspace; the
 * copy is theirs and independent of the original. Rollback drops the list; saved copies stay where they were saved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sent_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            // The sender's workspace (the original stays there).
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('to_user_id')->constrained('users')->cascadeOnDelete();
            // session | project
            $table->string('kind', 8);
            $table->foreignUlid('capture_session_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->nullable()->constrained()->cascadeOnDelete();
            // The title when it was sent, for the list and the activity line.
            $table->string('title', 200);
            $table->string('note', 500)->nullable();
            $table->timestamp('sent_at')->useCurrent();
            $table->timestamp('saved_at')->nullable();
            // The copy in the recipient's workspace (a recording's or a project's id).
            $table->string('saved_id', 26)->nullable();
            $table->timestamps();
            $table->index(['to_user_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sent_items');
    }
};
