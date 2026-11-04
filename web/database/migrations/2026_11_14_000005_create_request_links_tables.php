<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Request links (v1.5): a public page (`/r/{token}`, no account) where anyone with the link sends files. Sound and
 * video become recordings in the link's project or the Inbox; with a project, documents and images go to its Files.
 * `request_uploads` tracks each file while its chunks arrive. Rollback drops both tables; what was received stays in
 * the library.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('request_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            // Who made it: the files received become this person's recordings.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The project files go to; a deleted project sends them to the Inbox.
            $table->foreignUlid('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('title', 200);
            $table->timestamp('expires_at')->nullable();
            $table->unsignedSmallInteger('max_files')->default(20);
            $table->unsignedInteger('max_mb')->default(100);
            $table->unsignedInteger('received')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'created_at']);
        });

        Schema::create('request_uploads', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('request_link_id')->constrained()->cascadeOnDelete();
            // recording | file
            $table->string('kind', 12);
            $table->string('name', 255);
            $table->unsignedBigInteger('size_bytes');
            $table->string('sha256', 64);
            // uploading | ready | refused | failed
            $table->string('status', 12)->default('uploading');
            $table->string('error', 300)->nullable();
            // A recording's chunks are the recording's own (ChunkedUploadService); a file's are counted here.
            $table->foreignUlid('recording_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('chunk_size_bytes')->default(0);
            $table->unsignedInteger('total_chunks')->default(0);
            $table->json('received_chunks')->nullable();
            $table->foreignUlid('client_file_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['request_link_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('request_uploads');
        Schema::dropIfExists('request_links');
    }
};
