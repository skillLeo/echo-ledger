<?php

declare(strict_types=1);

use App\Stt\LanguageChoice;
use App\Stt\LanguageRouter;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * One language list for every app (v1.2): Hindi and the mixed choices are gone. Stored values are rewritten to
 * today's codes: `hi`, `en+ur`, `ur+en`, `en+hi` become `ur` (Urdu always allows English words), `en+ar` becomes
 * `ar`; the routing table's v1.1 keys fold into en / ur / ar / other. Not reversible (the old codes are not kept).
 */
return new class extends Migration
{
    /** @var array<string, string> */
    private const MAP = [
        'hi' => 'ur', 'en+hi' => 'ur', 'hi+en' => 'ur', 'en+ur' => 'ur', 'ur+en' => 'ur',
        'en+ar' => 'ar', 'ar+en' => 'ar',
    ];

    public function up(): void
    {
        foreach (['capture_sessions', 'clients', 'transcripts'] as $table) {
            foreach (self::MAP as $from => $to) {
                DB::table($table)->where('language', $from)->update(['language' => $to]);
            }
        }

        $default = DB::table('settings')->where('key', 'default_language')->value('value');
        if ($default !== null) {
            $value = json_decode((string) $default, true);
            DB::table('settings')->where('key', 'default_language')->update(['value' => json_encode(LanguageChoice::normalize($value))]);
        }

        $routing = DB::table('settings')->where('key', 'routing.final')->value('value');
        if ($routing !== null) {
            $merged = LanguageRouter::merge(json_decode((string) $routing, true), LanguageRouter::DEFAULT_FINAL_ROUTES);
            DB::table('settings')->where('key', 'routing.final')->update(['value' => json_encode($merged)]);
        }
    }

    public function down(): void
    {
        // The old codes are not kept; v1.1 reads `ur` and `ar` as before.
    }
};
