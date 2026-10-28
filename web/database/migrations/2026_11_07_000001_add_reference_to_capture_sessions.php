<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Accuracy Lab (v1.2): the owner's corrected reference transcript of a recording, to score the engines against. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->longText('reference_text')->nullable()->after('language_marks');
            $table->timestamp('reference_set_at')->nullable()->after('reference_text');
        });
    }

    public function down(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn(['reference_text', 'reference_set_at']);
        });
    }
};
