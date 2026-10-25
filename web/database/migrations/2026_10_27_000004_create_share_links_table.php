<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Secure share links (v1.1): view-only, expiring, optionally password-protected, revocable, with an access log. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('share_links', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('capture_session_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('password_hash')->nullable();
            $table->boolean('include_audio')->default(false);
            // Nullable on purpose: MySQL without explicit_defaults_for_timestamp would auto-update a NOT NULL one.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->string('created_by', 120)->nullable();
            $table->timestamps();
            $table->index(['capture_session_id', 'created_at']);
        });
        Schema::create('share_link_views', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('share_link_id')->constrained()->cascadeOnDelete();
            $table->string('outcome', 16); // viewed | wrong_password | audio
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('viewed_at')->nullable();
            $table->index(['share_link_id', 'viewed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('share_link_views');
        Schema::dropIfExists('share_links');
    }
};
