<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Screen moments (v1.5, docs/API.md "Screen moments"): what was on the shared screen at a moment of a recording. The
 * picture and its thumbnail are encrypted on the recordings disk like the audio; the row keeps where they are, the text
 * Claude read on them and a one-line description. Projects gain `screens` (the desktop captures them; default on) and
 * the usage ledger the tokens of Claude requests (null for every other row).
 *
 * New table and nullable or defaulted columns only, so existing rows are untouched. Rollback drops the screens and
 * erases their encrypted files with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screens', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('capture_session_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('at_ms');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            // change | words | manual
            $table->string('trigger', 8);
            $table->char('sha256', 64);
            // image/webp | image/png | image/jpeg, read from the picture itself
            $table->string('mime', 16);
            // The encrypted picture and its 480 px WebP thumbnail on the recordings disk.
            $table->string('image_path', 255)->nullable();
            $table->string('thumb_path', 255)->nullable();
            $table->unsignedInteger('bytes');
            $table->text('ocr_text')->nullable();
            $table->string('description', 255)->nullable();
            // The text and description folded like transcripts (App\Support\UrduText), for search.
            $table->text('search_text')->nullable();
            $table->timestamp('described_at')->nullable();
            $table->timestamps();
            $table->unique(['capture_session_id', 'sha256']);
            $table->index(['capture_session_id', 'at_ms']);
        });
        if ($this->fulltext()) {
            Schema::table('screens', fn (Blueprint $table) => $table->fullText('search_text', 'screens_search_text_fulltext'));
        }
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('screens')->default(true)->after('ai_notes');
        });
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->unsignedInteger('input_tokens')->nullable()->after('billed_ms');
            $table->unsignedInteger('output_tokens')->nullable()->after('input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('usage_ledger', function (Blueprint $table): void {
            $table->dropColumn(['input_tokens', 'output_tokens']);
        });
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn('screens');
        });
        Schema::dropIfExists('screens');
        // Without their rows the pictures cannot be read or erased one by one any more.
        Storage::disk('recordings')->deleteDirectory('screens');
    }

    private function fulltext(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
