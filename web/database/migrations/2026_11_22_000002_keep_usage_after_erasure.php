<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Since v1.5: what the server paid a provider stays on Server usage when the account or workspace that used it is
 * erased. `usage_ledger.user_id` and `workspace_id` become nullable and are set to null when their account or
 * workspace goes (before: the account's rows were deleted with it, and a workspace with rows could not be deleted,
 * so erasing an account that had used anything failed). Rollback refuses while anonymised rows exist.
 */
return new class extends Migration
{
    /** SQLite rebuilds the table to change a column: never inside a transaction (see require_workspace_id). */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->refuseInsideTransaction();
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['workspace_id']);
        });
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable()->change();
            $table->unsignedBigInteger('workspace_id')->nullable()->change();
        });
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->nullOnDelete();
        });
    }

    public function down(): void
    {
        $this->refuseInsideTransaction();
        $anonymised = DB::table('usage_ledger')->whereNull('user_id')->orWhereNull('workspace_id')->count();
        if ($anonymised > 0) {
            throw new RuntimeException("{$anonymised} usage row(s) belong to erased accounts or deleted workspaces; delete them first to roll back.");
        }
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['workspace_id']);
        });
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->unsignedBigInteger('workspace_id')->nullable(false)->change();
        });
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('workspace_id')->references('id')->on('workspaces')->restrictOnDelete();
        });
    }

    private function refuseInsideTransaction(): void
    {
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this migration outside a transaction: a SQLite table rebuild inside one would cascade deletes.');
        }
    }
};
