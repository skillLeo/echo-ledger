<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Invite links (v1.8 P6.3): a link to join a team as a member or a viewer, until it ends, for a number of people or
 * any, until someone revokes it. Only its hash is kept; each person who joins with it takes a seat then.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invite_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('role', 10);
            $table->string('token_hash', 64)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->unsignedInteger('max_uses')->nullable();
            $table->unsignedInteger('uses')->default(0);
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_invite_links');
    }
};
