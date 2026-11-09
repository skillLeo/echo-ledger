<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Upgrade requests (since v1.6, P1.4, docs/API.md "Upgrade requests"): a person asks for a plan (with an optional
 * message), pays as the admin's payment instructions say, and the super admin approves (the plan, until a date or not)
 * or declines with a reply. One pending request per person; the workspace is the one they asked from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upgrade_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 32);                       // the plan's key asked for
            $table->string('message', 1000)->nullable();
            $table->string('status', 16)->default('pending'); // pending | approved | declined
            $table->string('reply', 1000)->nullable();
            $table->string('approved_plan', 32)->nullable();
            $table->timestamp('approved_until')->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upgrade_requests');
    }
};
