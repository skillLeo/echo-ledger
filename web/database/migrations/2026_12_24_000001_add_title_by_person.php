<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * B10, contract 2.0.0-k "Title from the person": a title a person gave by renaming (PATCH, the console) always wins, even
 * a generic word, and is never replaced by the person called or an automatic title. Recordings renamed before this keep
 * the rule they had (a generic title counts as none). Rollback drops the column: the release before ignores it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->boolean('title_by_person')->default(false)->after('title_auto');
        });
    }

    public function down(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn('title_by_person');
        });
    }
};
