<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Call rules, the after-call choice and audio clean-up (contract 2.1.0-a, docs/PLAN_CALL_RULES.md).
 *
 * - call_rules: a person's rules per contact, group and source.
 * - users: the after-call default (null = legacy: every recording is transcribed), short calls (null = legacy: kept),
 *   the rules' revision, and their own audio retention choice (null = the plan's).
 * - capture_sessions: the recording's choice (null for recordings made before), who made it, when, and whether it
 *   still needs a look.
 * - plans: audio_retention_days (null = Never; Free 7, others 30) and the auto_transcribe feature on every plan that
 *   transcribes on the platform.
 * - call_choice_log: transcribed / kept / deleted / cleaned up per plan, without content, for the admin's counts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('call_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 8);
            $table->string('key', 120);
            $table->string('name', 120)->nullable();
            $table->string('action', 16);
            $table->timestamps();
            $table->unique(['user_id', 'kind', 'key']);
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->string('after_call_default', 16)->nullable();
            $table->boolean('short_calls_keep')->nullable();
            $table->unsignedInteger('call_rules_revision')->default(0);
            $table->string('audio_retention_choice', 8)->nullable();
        });

        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->string('choice', 16)->nullable();
            $table->string('choice_by', 16)->nullable();
            $table->timestamp('choice_at')->nullable();
            $table->boolean('needs_action')->default(false);
            $table->index(['choice', 'choice_at']);
        });

        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedSmallInteger('audio_retention_days')->nullable();
        });

        Schema::create('call_choice_log', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('plan_key', 32)->nullable();
            $table->string('outcome', 16);
            $table->string('by', 16)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['plan_key', 'created_at']);
        });

        foreach (DB::table('plans')->get(['id', 'key', 'features']) as $plan) {
            $features = json_decode((string) $plan->features, true);
            $features = is_array($features) ? $features : [];
            // A missing feature counts as on (Plan::allows): a plan that names platform transcription names this one the
            // same way; one that names neither (Personal: every feature) is left as it is.
            if (array_key_exists('platform_transcription', $features)) {
                $features['auto_transcribe'] = $features['platform_transcription'] === true;
            }
            DB::table('plans')->where('id', $plan->id)->update([
                'audio_retention_days' => $plan->key === 'free' ? 7 : 30,
                'features' => json_encode($features),
            ]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('plans')->get(['id', 'features']) as $plan) {
            $features = json_decode((string) $plan->features, true);
            if (is_array($features) && array_key_exists('auto_transcribe', $features)) {
                unset($features['auto_transcribe']);
                DB::table('plans')->where('id', $plan->id)->update(['features' => json_encode($features)]);
            }
        }
        Schema::dropIfExists('call_choice_log');
        Schema::table('plans', fn (Blueprint $table) => $table->dropColumn('audio_retention_days'));
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropIndex(['choice', 'choice_at']);
            $table->dropColumn(['choice', 'choice_by', 'choice_at', 'needs_action']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['after_call_default', 'short_calls_keep', 'call_rules_revision', 'audio_retention_choice']));
        Schema::dropIfExists('call_rules');
    }
};
