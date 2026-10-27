<?php

declare(strict_types=1);

use App\Support\UrduText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Language accuracy (v1.1.2):
 * - `transcripts.search_text`: the text with Urdu spelling variants folded (App\Support\UrduText), searched instead of
 *   `text` so a word is found however it was spelled; FULLTEXT on MySQL / MariaDB, like `text` before.
 * - Engine comparison keeps one `trial` transcript per engine: the unique key becomes (session, kind, provider). The
 *   new key is added before the old one is dropped, so the foreign key on capture_session_id always has an index.
 * Additive and reversible; `text` and its index stay as they are.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasColumn('transcripts', 'search_text')) {
            Schema::table('transcripts', fn (Blueprint $table) => $table->longText('search_text')->nullable()->after('text'));
        }
        DB::table('transcripts')->select(['id', 'text'])->whereNotNull('text')->orderBy('id')->chunk(200, function ($rows): void {
            foreach ($rows as $row) {
                DB::table('transcripts')->where('id', $row->id)->update(['search_text' => UrduText::fold((string) $row->text)]);
            }
        });
        if ($this->fulltext()) {
            Schema::table('transcripts', fn (Blueprint $table) => $table->fullText('search_text', 'transcripts_search_text_fulltext'));
        }
        Schema::table('transcripts', fn (Blueprint $table) => $table->unique(['capture_session_id', 'kind', 'provider'], 'transcripts_session_kind_provider_unique'));
        Schema::table('transcripts', fn (Blueprint $table) => $table->dropUnique(['capture_session_id', 'kind']));
    }

    public function down(): void
    {
        DB::table('transcripts')->where('kind', 'trial')->delete();
        Schema::table('transcripts', fn (Blueprint $table) => $table->unique(['capture_session_id', 'kind']));
        Schema::table('transcripts', fn (Blueprint $table) => $table->dropUnique('transcripts_session_kind_provider_unique'));
        if ($this->fulltext()) {
            Schema::table('transcripts', fn (Blueprint $table) => $table->dropFullText('transcripts_search_text_fulltext'));
        }
        Schema::table('transcripts', fn (Blueprint $table) => $table->dropColumn('search_text'));
    }

    private function fulltext(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
