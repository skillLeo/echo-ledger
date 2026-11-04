<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Share links (v1.5): a link can be for a whole project (`client_id`, then `capture_session_id` is null), says who may
 * open it (`audience`: public, password, people with the `people` emails) and whether files may be downloaded
 * (`allow_download`; `include_audio` stays the player). Existing links keep working as they did: a link with a
 * password is a password link and downloads what it already played.
 *
 * Rollback deletes the project links (a recording link table cannot hold them) and revokes the people-only links
 * first: without the audience they would open for anyone.
 */
return new class extends Migration
{
    /** SQLite changes `capture_session_id` and adds the key by rebuilding the table: never inside a transaction. */
    public $withinTransaction = false;

    public function up(): void
    {
        $this->refuseInsideTransaction();
        Schema::table('share_links', function (Blueprint $table): void {
            $table->dropForeign(['capture_session_id']);
        });
        Schema::table('share_links', function (Blueprint $table): void {
            $table->ulid('capture_session_id')->nullable()->change();
            $table->foreign('capture_session_id')->references('id')->on('capture_sessions')->cascadeOnDelete();
            $table->foreignUlid('client_id')->nullable()->after('capture_session_id')->constrained('clients')->cascadeOnDelete();
        });
        Schema::table('share_links', function (Blueprint $table): void {
            // public | password | people
            $table->string('audience', 8)->default('public')->after('password_hash');
            $table->json('people')->nullable()->after('audience');
            $table->boolean('allow_download')->default(false)->after('include_audio');
        });
        DB::table('share_links')->whereNotNull('password_hash')->update(['audience' => 'password']);
        DB::table('share_links')->where('include_audio', true)->update(['allow_download' => true]);
    }

    public function down(): void
    {
        $this->refuseInsideTransaction();
        $projectLinks = DB::table('share_links')->whereNotNull('client_id')->pluck('id');
        DB::table('share_link_views')->whereIn('share_link_id', $projectLinks)->delete();
        DB::table('share_links')->whereIn('id', $projectLinks)->delete();
        DB::table('share_links')->where('audience', 'people')->whereNull('revoked_at')->update(['revoked_at' => now()]);

        Schema::table('share_links', function (Blueprint $table): void {
            $table->dropColumn(['audience', 'people', 'allow_download']);
        });
        Schema::table('share_links', function (Blueprint $table): void {
            $table->dropForeign(['client_id']);
            $table->dropForeign(['capture_session_id']);
        });
        Schema::table('share_links', function (Blueprint $table): void {
            $table->dropColumn('client_id');
        });
        Schema::table('share_links', function (Blueprint $table): void {
            $table->ulid('capture_session_id')->nullable(false)->change();
            $table->foreign('capture_session_id')->references('id')->on('capture_sessions')->cascadeOnDelete();
        });
    }

    private function refuseInsideTransaction(): void
    {
        if (DB::getDriverName() === 'sqlite' && DB::transactionLevel() > 0) {
            throw new RuntimeException('Run this migration outside a transaction: a SQLite table rebuild inside one would cascade deletes.');
        }
    }
};
