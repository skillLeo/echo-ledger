<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The Claude and ChatGPT connector (since v1.5, docs/API.md "Claude and ChatGPT connector"): one row per app a person
 * allowed to read their recordings (an OAuth client of Passport's `oauth_clients`), for Profile → Connected apps with
 * when it was connected and last used. Its tokens stay in Passport's own tables; Disconnect revokes them and marks the
 * row, which keeps the app's registration for when it connects again. Rollback drops the table; the tokens go with
 * Passport's tables (the four migrations before this one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('connected_apps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Passport's client ids are UUIDs; no foreign key, as Passport's own tables have none either.
            $table->uuid('client_id');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('disconnected_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->unique(['user_id', 'client_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('connected_apps');
    }
};
