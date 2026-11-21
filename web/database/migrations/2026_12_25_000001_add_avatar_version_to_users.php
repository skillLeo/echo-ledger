<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-l, "Account and security from the apps": users.avatar_version, the version of the person's profile
 * picture (null = none, the apps show initials). The pictures themselves are encrypted files on the recordings disk
 * (avatars/<user>/<version>-<size>.elr), named by the version, so nothing else is stored.
 *
 * Since the security review: users.email_restore, the "Put my address back" window of an email change (encrypted:
 * the address before the first change, when, until when, the link's key, and after a restore its lock). The address
 * is reserved meanwhile in users.email_restore_address (indexed, with the same collation as users.email, so the
 * database compares it as it compares addresses: an accented or upper-case lookalike is the same address) until
 * users.email_restore_until. users.recovery_codes_generated_at: when the two-step recovery codes were last made (any
 * path: the console, the apps, staff), as a change proven with two-step counts only with codes older than 7 days.
 * Each column is added on its own, so a half-run migration finishes. Down drops them all
 * (the release before has none of this; the files of the pictures stay until their accounts are erased).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_version')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedInteger('avatar_version')->nullable());
        }
        if (! Schema::hasColumn('users', 'email_restore')) {
            Schema::table('users', fn (Blueprint $table) => $table->text('email_restore')->nullable());
        }
        if (! Schema::hasColumn('users', 'email_restore_address')) {
            $collation = $this->emailCollation();
            Schema::table('users', function (Blueprint $table) use ($collation): void {
                $column = $table->string('email_restore_address')->nullable()->index();
                if ($collation !== null) {
                    $column->collation($collation);
                }
            });
        }
        if (! Schema::hasColumn('users', 'email_restore_until')) {
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('email_restore_until')->nullable());
        }
        if (! Schema::hasColumn('users', 'recovery_codes_generated_at')) {
            Schema::table('users', fn (Blueprint $table) => $table->timestamp('recovery_codes_generated_at')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'email_restore_address')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropIndex(['email_restore_address']);
                $table->dropColumn('email_restore_address');
            });
        }
        foreach (['recovery_codes_generated_at', 'email_restore_until', 'email_restore', 'avatar_version'] as $column) {
            if (Schema::hasColumn('users', $column)) {
                Schema::table('users', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /** users.email's own collation on MySQL and MariaDB; null elsewhere (SQLite compares both the same way). */
    private function emailCollation(): ?string
    {
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            return null;
        }
        $collation = DB::table('information_schema.columns')->where('table_schema', DB::getDatabaseName())
            ->where('table_name', 'users')->where('column_name', 'email')->value('collation_name');

        return is_string($collation) && $collation !== '' ? $collation : null;
    }
};
