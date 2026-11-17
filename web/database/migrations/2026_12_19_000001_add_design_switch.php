<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The design switch both ways (contract 2.0.0-g, docs/PLAN_V2_PARITY.md section 0).
 *
 * - interface_flags.availability: off (the admin's lists only), opt_in (whoever chose v2, and the lists) or everyone.
 *   A flag on for everyone keeps everyone; every other ui_v2.* flag, with a row or not, starts on opt_in (the owner,
 *   2026-11-18). live_assist has no opt_in.
 * - users.ui_design: classic, v2 or null. 2.0.0-a's ui_classic = true becomes "classic" (ui_classic stays, kept equal).
 *
 * Down drops both columns: ui_classic still says who chose classic, and a flag row made here (no lists, not
 * everyone) is off, as no row was.
 */
return new class extends Migration
{
    private const UI_V2 = ['ui_v2.shell', 'ui_v2.home', 'ui_v2.library', 'ui_v2.recording_controls', 'ui_v2.after_call', 'ui_v2.live',
        'ui_v2.ask', 'ui_v2.settings', 'ui_v2.setup_health', 'ui_v2.admin', 'ui_v2.website'];

    public function up(): void
    {
        Schema::table('interface_flags', function (Blueprint $table): void {
            $table->string('availability', 16)->default('off');
        });
        Schema::table('users', function (Blueprint $table): void {
            $table->string('ui_design', 8)->nullable();
        });

        DB::table('interface_flags')->where('everyone', true)->update(['availability' => 'everyone']);
        DB::table('interface_flags')->whereIn('flag', self::UI_V2)->where('everyone', false)->update(['availability' => 'opt_in']);
        $have = DB::table('interface_flags')->pluck('flag')->all();
        foreach (array_diff(self::UI_V2, $have) as $flag) {
            DB::table('interface_flags')->insert(['flag' => $flag, 'everyone' => false, 'percent' => 0, 'workspace_ids' => '[]', 'user_ids' => '[]',
                'availability' => 'opt_in', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('users')->where('ui_classic', true)->update(['ui_design' => 'classic']);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ui_design'));
        Schema::table('interface_flags', fn (Blueprint $table) => $table->dropColumn('availability'));
    }
};
