<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Usage ledger (docs/API.md "Usage and monthly limit"): one row per streaming report sent by a device and one per
 * completed final transcript. New table only, so it is safe on a production database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usage_ledger', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('device_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUlid('capture_session_id')->nullable()->constrained()->nullOnDelete();
            $table->ulid('transcript_id')->nullable(); // no foreign key: transcripts are pruned, the spend stays
            $table->string('source', 8);               // device | server
            $table->string('report_id', 64);
            $table->string('use_case', 24);
            $table->string('provider', 32);
            $table->string('model', 64)->nullable();
            $table->unsignedBigInteger('duration_ms');
            $table->unsignedTinyInteger('channels');
            $table->unsignedBigInteger('billed_ms');
            $table->decimal('unit_price_usd', 10, 4);
            $table->decimal('cost_usd', 12, 6);
            $table->boolean('billable')->default(true);
            $table->timestamp('occurred_at')->index();
            $table->timestamps();
            $table->unique(['user_id', 'report_id']);
            $table->index(['occurred_at', 'billable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_ledger');
    }
};
