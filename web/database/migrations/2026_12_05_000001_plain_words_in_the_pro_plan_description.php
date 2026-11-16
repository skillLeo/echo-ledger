<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Since v1.8.1 (D-129): the description v1.6 gave the Pro plan named two AI assistants by their makers' names, and the
 * apps show it on the plan comparison. It says the feature in plain words now, as the features registry does. Only the
 * text v1.6 wrote changes: a description the operator edited on the Plans page stays as it is. down() puts the old text
 * back where it is still the new one.
 */
return new class extends Migration
{
    public const BEFORE = 'Transcripts made for you, Roman Urdu and English views, double-check, screen moments and Claude or ChatGPT.';

    public const AFTER = 'Transcripts made for you, Roman Urdu and English views, double-check, screen moments and asking an AI assistant.';

    /** The words only: the plan's other columns, its updated_at too, stay as they are, so up and down leave no trace. */
    public function up(): void
    {
        DB::table('plans')->where('key', 'pro')->where('description', self::BEFORE)->update(['description' => self::AFTER]);
    }

    public function down(): void
    {
        DB::table('plans')->where('key', 'pro')->where('description', self::AFTER)->update(['description' => self::BEFORE]);
    }
};
