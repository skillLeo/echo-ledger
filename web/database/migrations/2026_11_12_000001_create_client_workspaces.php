<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Client workspaces (v1.4, P4): files and instructions per client, a one-page brief, notes after every call, a living
 * memory, and the Claude usage of each client. Nothing is trained: these are only what goes into a request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            // "AI notes" for this client: call notes and memory after each call; off = no Claude request for it.
            $table->boolean('ai_notes')->default(true);
        });

        Schema::create('client_files', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->string('name', 200);
            $table->string('mime', 100);
            $table->unsignedBigInteger('size_bytes');
            // Encrypted like recordings (RecordingCipher); null for pasted instructions, which live only as text.
            $table->string('path')->nullable();
            // ready | extracting | failed
            $table->string('status', 16)->default('extracting');
            $table->string('error', 300)->nullable();
            $table->unsignedSmallInteger('pages')->nullable();
            // Encrypted at rest (cast); "=== Page N ===" separates PDF pages.
            $table->longText('text')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });

        Schema::create('client_briefs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->unique()->constrained()->cascadeOnDelete();
            $table->json('sections');
            // Once edited by a person the brief is never rewritten by itself.
            $table->boolean('edited')->default(false);
            $table->string('status', 16)->default('ready');
            $table->string('error', 300)->nullable();
            $table->string('model', 64)->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });

        Schema::create('call_notes', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('capture_session_id')->unique()->constrained()->cascadeOnDelete();
            // pending | ready | failed | skipped
            $table->string('status', 16)->default('pending');
            $table->string('error', 300)->nullable();
            $table->json('sections')->nullable();
            $table->string('language', 16)->default('en');
            $table->string('model', 64)->nullable();
            $table->timestamps();
            $table->index(['client_id', 'created_at']);
        });

        Schema::create('client_memory_items', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            // decision | question | change | scope
            $table->string('kind', 16);
            $table->string('text', 500);
            $table->date('happened_on')->nullable();
            $table->boolean('pinned')->default(false);
            // call | file | user: what the line cites.
            $table->string('source_type', 8);
            $table->string('source_id', 26)->nullable();
            $table->unsignedInteger('at_ms')->nullable();
            $table->timestamps();
            $table->index(['client_id', 'kind']);
        });

        Schema::table('usage_ledger', function (Blueprint $table): void {
            // Claude requests of a client workspace are counted per client.
            $table->string('client_id', 26)->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->dropIndex(['client_id']);
            $table->dropColumn('client_id');
        });
        Schema::dropIfExists('client_memory_items');
        Schema::dropIfExists('call_notes');
        Schema::dropIfExists('client_briefs');
        Schema::dropIfExists('client_files');
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('ai_notes');
        });
    }
};
