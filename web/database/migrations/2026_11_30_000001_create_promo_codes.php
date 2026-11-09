<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Promo codes (since v1.6.2, docs/API.md "Promo codes"): a code for one plan with a discount of 1–100 %, an optional
 * end date and number of uses, one use per person by default, and the months the plan lasts once switched on. Each use
 * says who, when and on which upgrade request; the request keeps the code, the percent and the amount due. The code is
 * kept in capitals, so it is unique without case. Nothing existing changes; down() removes the tables and columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promo_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 24)->unique();          // A-Z 0-9 -, kept in capitals
            $table->string('plan', 32);                    // the plan's key
            $table->unsignedTinyInteger('percent');        // 1–100
            $table->timestamp('ends_at')->nullable();
            $table->unsignedInteger('max_uses')->nullable();
            $table->boolean('one_per_person')->default(true);
            $table->unsignedSmallInteger('months')->default(1);
            $table->boolean('active')->default(true);
            $table->string('note', 500)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        Schema::create('promo_code_uses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('promo_code_id')->constrained('promo_codes')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('upgrade_request_id')->nullable()->constrained('upgrade_requests')->nullOnDelete();
            $table->timestamp('used_at');
            $table->index(['promo_code_id', 'user_id']);
        });
        Schema::table('upgrade_requests', function (Blueprint $table): void {
            $table->string('promo_code', 24)->nullable()->after('message');
            $table->unsignedTinyInteger('percent')->nullable()->after('promo_code');
            $table->string('amount_due', 64)->nullable()->after('percent');
        });
    }

    public function down(): void
    {
        Schema::table('upgrade_requests', function (Blueprint $table): void {
            $table->dropColumn(['promo_code', 'percent', 'amount_due']);
        });
        Schema::dropIfExists('promo_code_uses');
        Schema::dropIfExists('promo_codes');
    }
};
