<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-l, "Account and security from the apps": users.avatar_version, the version of the person's profile
 * picture (null = none, the apps show initials). The pictures themselves are encrypted files on the recordings disk
 * (avatars/<user>/<version>-<size>.elr), named by the version, so nothing else is stored. Safe to run again; down
 * drops only this column (the files of the pictures stay until their accounts are erased; the release before shows
 * initials for everyone). Since the security review: users.email_restore, the open "Put my address back" window of an
 * email change ({to, changed_at, until, nonce}: the address before the first change, when, until when, the link's
 * key); while it is open the apps cannot change the address again. Down drops it too.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_version')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unsignedInteger('avatar_version')->nullable();
            });
        }
        if (! Schema::hasColumn('users', 'email_restore')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->text('email_restore')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'email_restore')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('email_restore'));
        }
        if (Schema::hasColumn('users', 'avatar_version')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_version'));
        }
    }
};
