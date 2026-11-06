<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A sent project holds the recordings that were in it when it was sent (docs/API.md "Sending to a teammate"): the list
 * is kept with what was sent. Projects sent before this have no such moment on record, so they keep what they hold today:
 * nobody loses what they can open now, and nothing filed from now on joins it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sent_items', function (Blueprint $table): void {
            $table->json('session_ids')->nullable();
        });

        DB::table('sent_items')->where('kind', 'project')->select(['id', 'client_id'])->chunkById(200, function ($items): void {
            foreach ($items as $item) {
                DB::table('sent_items')->where('id', $item->id)->update(['session_ids' => json_encode($this->heldBy($item->client_id))]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('sent_items', function (Blueprint $table): void {
            $table->dropColumn('session_ids');
        });
    }

    /**
     * The recordings of a project and its subfolders as a recipient opens them: its maker's, not deleted, no longer recording.
     *
     * @return list<string>
     */
    private function heldBy(?string $projectId): array
    {
        $maker = $projectId === null ? null : DB::table('clients')->where('id', $projectId)->value('user_id');
        if ($maker === null) {
            return [];
        }
        $folders = [$projectId];
        $level = [$projectId];
        while ($level !== []) {
            $level = DB::table('clients')->where('user_id', $maker)->whereIn('parent_id', $level)->whereNotIn('id', $folders)->pluck('id')->all();
            $folders = [...$folders, ...$level];
        }

        return DB::table('capture_sessions')->where('user_id', $maker)->whereIn('client_id', $folders)
            ->whereNull('deleted_at')->where('status', '!=', 'recording')->pluck('id')->all();
    }
};
