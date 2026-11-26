<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * W-INC-1124: since a fault of the server keeps an upload's parts for recordings:recover, the keeping has a bound
 * (ChunkedUploadService::pastKeeping). recordings.assembling_since: when `complete` started the current assembly (null
 * on rows from before: the first recovery round sets it); recordings.recovery_rounds: how many times the recovery has
 * queued it again since then. Both reset at the next `complete`. recordings has no FULLTEXT index, so each is an
 * instant add on MariaDB. Each column is added on its own, so a half-run migration finishes; down drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('recordings', 'assembling_since')) {
            Schema::table('recordings', fn (Blueprint $table) => $table->timestamp('assembling_since')->nullable());
        }
        if (! Schema::hasColumn('recordings', 'recovery_rounds')) {
            Schema::table('recordings', fn (Blueprint $table) => $table->unsignedTinyInteger('recovery_rounds')->default(0));
        }
    }

    public function down(): void
    {
        foreach (['assembling_since', 'recovery_rounds'] as $column) {
            if (Schema::hasColumn('recordings', $column)) {
                Schema::table('recordings', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }
};
