<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * People's own accounts (since v1.5, docs/API.md "Signing in with an account" and "Your account"): a linked Google
 * account, the 7-day deletion grace, the recording-consent notice seen once after sign-up, the "transcript ready"
 * email; one-time browser sign-in codes for the apps (PKCE, stored hashed), the browsers an account signed in from
 * (for the new-browser email) and invitations to a workspace. Accounts that exist already are past their sign-up, so
 * they are marked as having seen the consent notice. Rollback drops the tables and columns; nothing else changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('google_id', 64)->nullable()->unique();
            $table->timestamp('deletion_requested_at')->nullable();
            $table->timestamp('deletes_at')->nullable()->index();
            $table->timestamp('consent_seen_at')->nullable();
            $table->boolean('notify_transcript_ready')->default(false);
        });
        DB::table('users')->whereNull('consent_seen_at')->update(['consent_seen_at' => now()]);

        Schema::table('devices', function (Blueprint $table): void {
            // The two-letter country of the last address, when the server sits behind a proxy that names it.
            $table->string('last_country', 2)->nullable();
        });

        Schema::create('device_sign_in_codes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->char('code_hash', 64)->unique();
            $table->string('code_challenge', 64);
            $table->string('name', 120);
            $table->string('platform', 16);
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('known_browsers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('label', 120);
            $table->string('ip', 45)->nullable();
            $table->string('country', 2)->nullable();
            $table->timestamp('last_used_at')->useCurrent();
            $table->timestamps();
        });

        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('email', 255)->index();
            $table->string('role', 16);
            $table->char('token_hash', 64)->unique();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->useCurrent();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invitations');
        Schema::dropIfExists('known_browsers');
        Schema::dropIfExists('device_sign_in_codes');
        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('last_country');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropUnique(['google_id']);
            $table->dropIndex(['deletes_at']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['google_id', 'deletion_requested_at', 'deletes_at', 'consent_seen_at', 'notify_transcript_ready']);
        });
    }
};
