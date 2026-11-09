<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plans & features on the admin panel (since v1.6, P2.3): a plan can be archived (no longer offered or given to anyone
 * new; the workspaces on it keep it), and its display price is an amount with a currency ("PKR 2,000 a month") when no
 * text of its own is set. Every plan starts unarchived with no amount; down() removes exactly these columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable();
            $table->decimal('price_amount', 12, 2)->nullable();
            $table->string('currency', 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['archived_at', 'price_amount', 'currency']);
        });
    }
};
