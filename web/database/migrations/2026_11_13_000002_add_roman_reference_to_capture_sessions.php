<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Accuracy Lab (since v1.5): a Roman Urdu reference next to the original one, so the Roman Urdu view is scored on its
 * own, and the category the recording counts in (ur, en or auto: Urdu, English or mixed). Two nullable columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->longText('reference_roman')->nullable()->after('reference_text');
            $table->string('reference_category', 8)->nullable()->after('reference_roman');
        });
    }

    public function down(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn(['reference_roman', 'reference_category']);
        });
    }
};
