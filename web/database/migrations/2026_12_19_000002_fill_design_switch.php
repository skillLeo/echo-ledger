<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The design switch both ways (contract 2.0.0-g), the values. A flag on for everyone keeps everyone; every other
 * ui_v2.* flag, with a row or not, starts on opt_in (the owner, 2026-11-18); live_assist has no opt_in. 2.0.0-a's
 * ui_classic = true becomes the design "classic" (ui_classic stays, kept equal).
 *
 * Safe to run again: it only fills what is not filled yet (availability null, ui_design null for a classic person) and
 * inserts only missing rows, so a choice made since is kept. Down empties both again (null) and ui_design (2026_12_19_000001's down then drops the columns); ui_classic still says who chose classic, and a row made
 * here (no lists, not everyone) is off, as no row was.
 */
return new class extends Migration
{
    private const UI_V2 = ['ui_v2.shell', 'ui_v2.home', 'ui_v2.library', 'ui_v2.recording_controls', 'ui_v2.after_call', 'ui_v2.live',
        'ui_v2.ask', 'ui_v2.settings', 'ui_v2.setup_health', 'ui_v2.admin', 'ui_v2.website'];

    public function up(): void
    {
        // Only rows not filled yet (availability null): an admin's later choice, "off" included, is never touched.
        DB::table('interface_flags')->whereNull('availability')->where('everyone', true)->update(['availability' => 'everyone']);
        DB::table('interface_flags')->whereNull('availability')->whereIn('flag', self::UI_V2)->update(['availability' => 'opt_in']);
        DB::table('interface_flags')->whereNull('availability')->update(['availability' => 'off']);
        $have = DB::table('interface_flags')->pluck('flag')->all();
        foreach (array_diff(self::UI_V2, $have) as $flag) {
            DB::table('interface_flags')->insert(['flag' => $flag, 'everyone' => false, 'percent' => 0, 'workspace_ids' => '[]', 'user_ids' => '[]',
                'availability' => 'opt_in', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('users')->where('ui_classic', true)->whereNull('ui_design')->update(['ui_design' => 'classic']);
    }

    /**
     * Empties what up() filled. A "v2" choice a person made after the deploy is lost on rollback (2.0.0-a has no such
     * choice); a "classic" choice survives through ui_classic, which setDesign() keeps equal to it. Availabilities go
     * back to null; with the columns' down they go entirely, and the flags read from "everyone" and the lists as before.
     */
    public function down(): void
    {
        DB::table('interface_flags')->update(['availability' => null]);
        DB::table('users')->update(['ui_design' => null]);
    }
};
