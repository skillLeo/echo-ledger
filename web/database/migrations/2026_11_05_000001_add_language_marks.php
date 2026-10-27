<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The language chip changed during a recording (v1.2): [{at_ms, language}], honoured by the chunked engines. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->json('language_marks')->nullable()->after('pauses');
        });
    }

    public function down(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn('language_marks');
        });
    }
};
