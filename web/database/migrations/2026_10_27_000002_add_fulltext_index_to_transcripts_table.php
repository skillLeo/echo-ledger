<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Global search (v1.1): a FULLTEXT index on transcript text where the database has one (MySQL / MariaDB). */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->supported()) {
            Schema::table('transcripts', fn (Blueprint $table) => $table->fullText('text', 'transcripts_text_fulltext'));
        }
    }

    public function down(): void
    {
        if ($this->supported()) {
            Schema::table('transcripts', fn (Blueprint $table) => $table->dropFullText('transcripts_text_fulltext'));
        }
    }

    private function supported(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
