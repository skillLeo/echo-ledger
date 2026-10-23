<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('platform', 16);
            $table->string('app_version', 32)->nullable();
            $table->string('os_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->string('last_ip', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('device_pairing_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('clients', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('color', 9)->nullable();
            $table->string('language', 16)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('client_keyterms', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('client_id')->constrained()->cascadeOnDelete();
            $table->string('term', 100);
            $table->timestamps();
            $table->unique(['client_id', 'term']);
        });

        Schema::create('capture_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('client_ref', 64)->nullable();
            $table->string('source_device', 16)->index();
            $table->string('source_app', 32)->index();
            $table->string('title', 200)->nullable();
            $table->string('language', 16)->nullable();
            $table->timestamp('started_at')->index();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->string('status', 16)->index();
            $table->string('final_provider', 32)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['device_id', 'client_ref']);
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('recordings', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('capture_session_id')->constrained()->cascadeOnDelete();
            $table->string('role', 16);
            $table->string('mime', 64);
            $table->string('codec', 32)->nullable();
            $table->unsignedTinyInteger('channels')->default(1);
            $table->unsignedInteger('sample_rate')->nullable();
            $table->unsignedBigInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('declared_size_bytes');
            $table->string('sha256', 64);
            $table->unsignedInteger('chunk_size_bytes');
            $table->unsignedInteger('total_chunks');
            $table->json('received_chunks');
            $table->string('upload_status', 16)->index();
            $table->string('stored_path')->nullable();
            $table->unsignedBigInteger('stored_size_bytes')->nullable();
            $table->string('key_id', 16)->default('v1');
            $table->timestamp('assembled_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['capture_session_id', 'role']);
        });

        Schema::create('transcripts', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('capture_session_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('provider', 32);
            $table->string('status', 24)->index();
            $table->string('provider_job_id', 128)->nullable()->index();
            $table->string('provider_transcript_id', 128)->nullable();
            $table->timestamp('provider_deleted_at')->nullable();
            $table->string('language', 16)->nullable();
            $table->longText('text')->nullable();
            $table->longText('segments')->nullable();
            $table->json('meta')->nullable();
            $table->text('error')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['capture_session_id', 'kind']);
        });

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('key', 100)->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('credentials', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('key', 64);
            $table->text('value');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['provider', 'key']);
        });

        Schema::create('webhook_events', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 32);
            $table->string('idempotency_key', 191);
            $table->foreignUlid('transcript_id')->nullable()->constrained()->nullOnDelete();
            $table->longText('payload')->nullable();
            $table->string('status', 16)->default('received');
            $table->timestamp('processed_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['provider', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        foreach (['webhook_events', 'credentials', 'settings', 'transcripts', 'recordings', 'capture_sessions',
            'client_keyterms', 'clients', 'device_pairing_codes', 'devices'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
