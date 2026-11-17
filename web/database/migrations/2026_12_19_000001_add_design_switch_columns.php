<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The design switch both ways (contract 2.0.0-g), the columns: interface_flags.availability (off, opt_in, everyone)
 * and users.ui_design (classic, v2 or null). The values are 2026_12_19_000002's. Safe to run again: each column is
 * added only when missing and dropped only when present.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('interface_flags', 'availability')) {
            Schema::table('interface_flags', function (Blueprint $table): void {
                $table->string('availability', 16)->default('off');
            });
        }
        if (! Schema::hasColumn('users', 'ui_design')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('ui_design', 8)->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'ui_design')) {
            Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ui_design'));
        }
        if (Schema::hasColumn('interface_flags', 'availability')) {
            Schema::table('interface_flags', fn (Blueprint $table) => $table->dropColumn('availability'));
        }
    }
};
