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
 * initials for everyone).
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
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'avatar_version')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_version'));
        }
    }
};
