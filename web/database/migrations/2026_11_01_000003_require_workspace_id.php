<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * B20 step 3 of 3: `workspace_id` becomes NOT NULL with a foreign key (restrict: deleting a workspace never
 * cascades into recordings). Refuses to run while any row still has no workspace, before changing anything.
 */
return new class extends Migration
{
    private const SCOPED = ['devices', 'device_pairing_codes', 'clients', 'capture_sessions', 'usage_ledger'];

    /**
     * SQLite changes a column by rebuilding the table (copy, drop, rename). Laravel turns foreign keys off around
     * it, but SQLite ignores that inside a transaction, and dropping `capture_sessions` with foreign keys on would
     * cascade-delete every recording and transcript. So this migration never runs inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->refuseInsideTransaction();
        foreach (self::SCOPED as $name) {
            $missing = DB::table($name)->whereNull('workspace_id')->count();
            if ($missing > 0) {
                throw new RuntimeException("{$missing} row(s) in {$name} have no workspace_id; run the backfill migration first.");
            }
        }
        foreach (self::SCOPED as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
                $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        $this->refuseInsideTransaction();
        foreach (self::SCOPED as $name) {
            Schema::table($name, function (Blueprint $table): void {
                $table->dropForeign(['workspace_id']);
                $table->unsignedBigInteger('workspace_id')->nullable()->change();
            });
        }
    }

    private function refuseInsideTransaction(): void
    {
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this migration outside a transaction: a SQLite table rebuild inside one would cascade deletes.');
        }
    }
};
