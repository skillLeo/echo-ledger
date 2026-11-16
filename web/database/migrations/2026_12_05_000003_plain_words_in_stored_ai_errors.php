<?php

declare(strict_types=1);

use App\Services\ClientAi\ClaudeClientAi;
use App\Support\ProviderText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Since v1.8.1: before it, a call note, a brief or a project file that the AI could not make kept the provider's own
 * words or the AI's maker ("Claude did not return the notes in the expected form.", a credential's name, the
 * provider's JSON), and so did a transcript's views ("Claude declined to convert this transcript."); the apps and the
 * console showed them. This rewrites each such reason to the plain "The AI could not do this right now. Try again in a
 * few minutes." (ClaudeClientAi::UNAVAILABLE); plain reasons stay as they are. The models read every reason through
 * ProviderText as well. down() is a deliberate no-op: the provider's words were never meant for people.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['call_notes', 'client_briefs', 'client_files'] as $table) {
            DB::table($table)->whereNotNull('error')->select(['id', 'error'])->lazyById(500, 'id')->each(function (object $row) use ($table): void {
                if (ProviderText::names((string) $row->error)) {
                    DB::table($table)->where('id', $row->id)->update(['error' => ClaudeClientAi::UNAVAILABLE]);
                }
            });
        }
        DB::table('transcripts')->where('meta->views->status', 'failed')->select(['id', 'meta'])->lazyById(500, 'id')->each(function (object $row): void {
            $meta = json_decode((string) $row->meta, true);
            $error = is_array($meta) && is_array($meta['views'] ?? null) ? ($meta['views']['error'] ?? null) : null;
            if (is_string($error) && ProviderText::names($error)) {
                $meta['views']['error'] = ClaudeClientAi::UNAVAILABLE;
                DB::table('transcripts')->where('id', $row->id)->update(['meta' => json_encode($meta)]);
            }
        });
    }

    public function down(): void
    {
        // Nothing to put back: the provider's words were never meant for people.
    }
};
