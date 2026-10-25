<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Machine-readable reason of a failed transcript (docs/API.md transcript `error_code`). Nullable, so existing rows stay valid. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->string('error_code', 32)->nullable()->after('error');
        });
    }

    public function down(): void
    {
        Schema::table('transcripts', function (Blueprint $table): void {
            $table->dropColumn('error_code');
        });
    }
};
