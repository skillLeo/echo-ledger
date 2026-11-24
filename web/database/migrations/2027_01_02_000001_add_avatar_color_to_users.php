<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-r §6 (BE-06; the colours' names since 2.0.0-u): users.avatar_color, the colour of the initials' circle
 * the person chose (0 to 7: teal, blue, cyan, olive, pink, orange, green, slate). Null until they choose one: the
 * colour is then their id's (User::avatarColor), the same on every device and from sign-up, so nothing is filled in.
 * Down drops it (the release before has none of this).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'avatar_color')) {
            Schema::table('users', fn (Blueprint $table) => $table->unsignedTinyInteger('avatar_color')->nullable());
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'avatar_color')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('avatar_color'));
        }
    }
};
