<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * B5, contract 2.0.0-w "Call rules": the person's rules for the whole account (contact, contact group, Source) and
 * their After each call settings (users.call_settings: after_call, ask_when, short_calls, version, CC5's preferences);
 * CC5, contract 2.0.0-x: the offers turned down (call_declined_offers). Both start empty;
 * the release before ignores them, so the rollback only drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_rules', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // contact | group | source
            $table->string('kind', 10);
            // What a call is matched by, one per person and kind: the normalised number, "name:<lower case name>",
            // the group or the Source.
            // Compared byte for byte on MySQL and MariaDB, as in PHP.
            $key = $table->string('match_key', 160);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->collation('utf8mb4_bin');
            }
            $table->string('number', 32)->nullable();
            $table->string('name', 120)->nullable();
            $table->string('group_key', 20)->nullable();
            $table->string('source_app', 40)->nullable();
            // ask | always_transcribe | always_audio | dont_record
            $table->string('action', 20);
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'match_key']);
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->json('call_settings')->nullable();
        });
        // CC5 (contract 2.0.0-x): the contacts and Sources whose "Always …?" offer the person turned down, at most 500
        // each, the oldest going; its own table, not users.call_settings, which every request reads.
        Schema::create('call_declined_offers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // contact | source
            $table->string('kind', 10);
            $key = $table->string('match_key', 160);
            if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                $key->collation('utf8mb4_bin');
            }
            $table->string('number', 32)->nullable();
            $table->string('name', 120)->nullable();
            $table->string('source_app', 40)->nullable();
            // When it was turned down (last): newest first, and the oldest goes past 500.
            $table->timestamp('declined_at');
            $table->unique(['user_id', 'kind', 'match_key']);
            $table->index(['user_id', 'declined_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('call_declined_offers');
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('call_settings');
        });
        Schema::dropIfExists('call_rules');
    }
};
