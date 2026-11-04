<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (v1.5): a client folder can sit inside another one (`parent_id`, at most 4 levels, checked in code), and
 * names are unique among the folders of one parent in a workspace instead of per person. Recordings remember how
 * they were filed (`filed_by`: user, contact, rule; null = the Inbox) and where a file came from (`origin`:
 * imported, requested, saved), for the Inbox and Imported smart folders.
 *
 * Rollback needs every person's project names to be unique again (the old unique index on user_id + name): down()
 * refuses, before changing anything, while two projects of one person share a name; rename one of them first. It
 * also puts every subfolder back at the top level (the tree is not kept) and forgets how recordings were filed.
 */
return new class extends Migration
{
    /**
     * SQLite adds the foreign key by rebuilding `clients` (copy, drop, rename). Laravel turns foreign keys off around
     * it, but SQLite ignores that inside a transaction, and dropping `clients` with foreign keys on would take every
     * keyterm, contact and file with it and empty every recording's client. So this never runs inside a transaction.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->refuseInsideTransaction();
        Schema::table('clients', function (Blueprint $table): void {
            // Deleting a project moves its subfolders up first (ProjectTree); the key only keeps stray rows valid.
            $table->foreignUlid('parent_id')->nullable()->after('workspace_id')->constrained('clients')->nullOnDelete();
        });
        Schema::table('clients', function (Blueprint $table): void {
            // MariaDB may use the old unique index for the user_id foreign key: give that key its own index first.
            $table->index('user_id', 'clients_user_id_index');
            $table->dropUnique(['user_id', 'name']);
            // Top-level names (parent_id null) are kept apart by validation: a unique index treats nulls as different.
            $table->unique(['workspace_id', 'parent_id', 'name'], 'clients_sibling_name_unique');
        });

        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->string('filed_by', 8)->nullable()->after('client_id');
            $table->string('origin', 16)->nullable()->after('filed_by');
        });
        // Recordings already in a folder were put there by a person (or by a contact match, which is not told apart).
        DB::table('capture_sessions')->whereNotNull('client_id')->update(['filed_by' => 'user']);
        // Console uploads are the only recordings without a device made on the web.
        DB::table('capture_sessions')->where('source_device', 'web')->whereNull('device_id')->update(['origin' => 'imported']);
    }

    public function down(): void
    {
        $this->refuseInsideTransaction();
        $clash = DB::table('clients')->select('user_id', 'name')->groupBy('user_id', 'name')->havingRaw('COUNT(*) > 1')->first();
        if ($clash !== null) {
            throw new RuntimeException("Two projects of user {$clash->user_id} are named \"{$clash->name}\": rename one of them before rolling back.");
        }
        Schema::table('capture_sessions', function (Blueprint $table): void {
            $table->dropColumn(['filed_by', 'origin']);
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropUnique('clients_sibling_name_unique');
            $table->unique(['user_id', 'name']);
            $table->dropIndex('clients_user_id_index');
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('parent_id');
        });
    }

    private function refuseInsideTransaction(): void
    {
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this migration outside a transaction: a SQLite table rebuild inside one would cascade deletes.');
        }
    }
};
