<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Live Assist, the server's part (v1.9 L1, docs/API_ASSIST.md 1.9.0-e): preps and their documents, sessions, their
 * final turns, the suggestions the relay showed, and what each suggestion cost. Every text people wrote or said is
 * encrypted (the models' `encrypted` casts) and goes with the session or the account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assist_preps', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('meeting_type', 20);
            // Encrypted, as every text below.
            foreach (['title', 'counterpart', 'company', 'goals', 'done', 'not_done', 'to_discuss', 'instructions'] as $text) {
                $table->text($text)->nullable();
            }
            $table->string('hint_level', 10);
            $table->string('mode', 10);
            $table->string('language_help', 12);
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('armed_until')->nullable();
            $table->string('status', 12)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'user_id', 'status']);
        });

        Schema::create('assist_prep_documents', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('assist_prep_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('client_file_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tokens')->default(0);
            $table->timestamps();
            $table->unique(['assist_prep_id', 'client_file_id']);
        });

        Schema::create('assist_sessions', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('assist_prep_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('capture_session_id')->nullable()->constrained()->nullOnDelete();
            $table->string('mode', 10);
            $table->boolean('practice')->default(false);
            $table->string('platform', 10);
            $table->boolean('auto_saved')->default(false);
            $table->string('status', 10)->default('active');
            $table->timestamp('started_at');
            $table->timestamp('last_token_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('seconds_used')->nullable();
            $table->string('report_status', 10)->default('none');
            $table->longText('report')->nullable();
            $table->longText('summary')->nullable();
            $table->timestamp('summary_at')->nullable();
            $table->longText('facts')->nullable();
            $table->timestamp('facts_at')->nullable();
            // Counts the relay's posts after the session ended: a report made from an older count is made again.
            $table->unsignedInteger('report_rev')->default(0);
            $table->timestamps();
            $table->softDeletes();
            $table->index(['workspace_id', 'user_id', 'status']);
            $table->index(['user_id', 'started_at']);
        });

        Schema::create('assist_turns', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('assist_session_id')->constrained()->cascadeOnDelete();
            $table->string('client_ref', 64);
            $table->string('channel', 5);
            $table->unsignedInteger('start_ms');
            $table->unsignedInteger('end_ms');
            $table->text('text');
            $table->string('language', 16)->nullable();
            $table->boolean('is_question')->default(false);
            $table->timestamps();
            $table->unique(['assist_session_id', 'client_ref']);
        });

        Schema::create('assist_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('assist_session_id')->constrained()->cascadeOnDelete();
            $table->string('suggestion_id', 128);
            $table->string('turn_id', 128)->nullable();
            $table->string('kind', 20)->nullable();
            $table->string('mode', 10)->nullable();
            $table->string('model', 128)->nullable();
            $table->string('replaces', 128)->nullable();
            // Up to 20,000 characters, encrypted: more than a TEXT column holds.
            $table->mediumText('text')->nullable();
            $table->string('action', 12)->nullable();
            $table->timestamp('action_at')->nullable();
            $table->timestamp('shown_at')->nullable();
            $table->timestamps();
            $table->unique(['assist_session_id', 'suggestion_id']);
        });

        Schema::create('assist_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignUlid('assist_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('suggestion_id', 128)->unique();
            $table->string('provider', 20);
            $table->string('model', 128);
            $table->string('mode', 10);
            $table->string('kind', 20);
            $table->string('trigger', 20)->nullable();
            $table->string('outcome', 20)->nullable();
            $table->boolean('fallback')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('written_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->boolean('estimated')->default(false);
            $table->json('latency')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->index(['user_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assist_usage');
        Schema::dropIfExists('assist_suggestions');
        Schema::dropIfExists('assist_turns');
        Schema::dropIfExists('assist_sessions');
        Schema::dropIfExists('assist_prep_documents');
        Schema::dropIfExists('assist_preps');
    }
};
