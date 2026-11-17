<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Since v2.0 (the owner's decision 2026-11-17): Live Assist's monthly allowances per plan, editable in Admin › Plans
 * instead of only in config/assist.php. Live: assist minutes and the AI budget (US$) of live sessions; Practice: its
 * own, counted apart. Empty means the config's value. The owner's values go on the three plans on offer: Free has
 * Practice only (30 minutes, $1), Pro 5 hours and $15 of live Live Assist, Ultra (the `business` key) 10 hours and
 * $60; their Practice keeps today's config values (Pro 5 hours and $5, Ultra 10 hours and $10). Only the plans
 * change: no workspace, person or usage row is touched. down() drops the four columns, so the config applies again.
 */
return new class extends Migration
{
    /** @var array<string, array{assist_minutes: int, assist_usd: float, practice_minutes: int, practice_usd: float}> */
    private const VALUES = [
        'free' => ['assist_minutes' => 0, 'assist_usd' => 0.0, 'practice_minutes' => 30, 'practice_usd' => 1.0],
        'pro' => ['assist_minutes' => 300, 'assist_usd' => 15.0, 'practice_minutes' => 300, 'practice_usd' => 5.0],
        'business' => ['assist_minutes' => 600, 'assist_usd' => 60.0, 'practice_minutes' => 600, 'practice_usd' => 10.0],
    ];

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->unsignedInteger('assist_minutes')->nullable()->after('ai_credits_usd');
            $table->decimal('assist_usd', 10, 2)->nullable()->after('assist_minutes');
            $table->unsignedInteger('practice_minutes')->nullable()->after('assist_usd');
            $table->decimal('practice_usd', 10, 2)->nullable()->after('practice_minutes');
        });
        foreach (self::VALUES as $key => $values) {
            DB::table('plans')->where('key', $key)->update($values);
        }
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn(['assist_minutes', 'assist_usd', 'practice_minutes', 'practice_usd']);
        });
    }
};
