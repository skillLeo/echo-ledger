<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * "Export my data" (since v1.5, docs/API.md "Your account"): one ZIP per request, made by a queued job, stored
 * encrypted on the recordings disk and deleted 24 hours after it is ready. Rollback drops the table; a ZIP still on
 * the disk is removed by hand (exports/).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_exports', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('making');
            $table->string('path', 255)->nullable();
            $table->unsignedBigInteger('bytes')->nullable();
            $table->string('error', 255)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_exports');
    }
};
