<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-r, BE-06: users.avatar_color, 0 to 7, the colour of a person's initials when there is no photo (the
 * tokens' 8 colours). New accounts get one at sign-up (User::creating); everyone already there gets one from their id,
 * once, here, and keeps it. An instant add on MariaDB, then one update. down() drops the column.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_color')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedTinyInteger('avatar_color')->default(0));
            DB::table('users')->update(['avatar_color' => DB::raw('id % 8')]);
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'avatar_color')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_color'));
        }
    }
};
