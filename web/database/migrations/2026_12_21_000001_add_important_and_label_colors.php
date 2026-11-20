<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The parity plan's row indicators (contract 2.0.0-i): capture_sessions.important (the person's flag, indexed like
 * pinned for `GET /api/library?important=1`) and label_colors (the colour of one person's label in one workspace; labels
 * belong to the person who made the recording, so one teammate's colour is never another's). The label is compared
 * ignoring capitals through label_key. Rows go with the person or the workspace by their foreign keys. Safe to run
 * again: each part is added only when missing and dropped only when present. Down drops only what up added.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('capture_sessions', 'important')) {
            Schema::table('capture_sessions', function (Blueprint $table): void {
                $table->boolean('important')->default(false);
                $table->index(['user_id', 'important']);
            });
        }
        if (! Schema::hasTable('label_colors')) {
            Schema::create('label_colors', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // The label in lower case: one colour per label of a person in a workspace, whatever its capitals.
                $table->string('label_key', 40);
                $table->string('color', 7);
                $table->timestamps();
                $table->unique(['workspace_id', 'user_id', 'label_key']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('label_colors');
        if (Schema::hasColumn('capture_sessions', 'important')) {
            Schema::table('capture_sessions', function (Blueprint $table): void {
                $table->dropIndex(['user_id', 'important']);
                $table->dropColumn('important');
            });
        }
    }
};
