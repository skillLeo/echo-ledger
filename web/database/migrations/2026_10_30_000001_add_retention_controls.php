<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retention controls (plan B17): a per-client retention, a "keep" pin per recording, and the 48-hour undo bin
 * (`media_erased_at` says when a deleted recording's audio and text were finally erased).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->boolean('keep_forever')->default(false)->after('pinned');
            $table->timestamp('media_erased_at')->nullable()->after('deleted_at');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->unsignedSmallInteger('retention_days')->nullable()->after('language');
        });

        // Before the bin, a delete erased the media at once: nothing deleted until now can be restored.
        DB::table('capture_sessions')->whereNotNull('deleted_at')->update(['media_erased_at' => DB::raw('deleted_at')]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('retention_days');
        });
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn(['keep_forever', 'media_erased_at']);
        });
    }
};
