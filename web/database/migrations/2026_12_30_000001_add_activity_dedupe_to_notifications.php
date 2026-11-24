<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Contract 2.0.0-r, BE-02: one Activity item per person, recording and kind. notifications.dedupe_key is
 * "<kind>:<session_id>" for an item about a recording (null for the others, which are never merged), unique per person;
 * repeat_count is how many times it happened; resolved_at is when what it asked for was done (an upload failure whose
 * recording reached the server). The items already there are merged once, here: for each person, recording and kind
 * the newest is kept, its repeat_count the number merged, unread when any of them was; the others are deleted. Upload
 * failures already over (the recording gone, in the undo bin, or no upload of it failed any more) are resolved. Running
 * it again changes nothing (deploy/checks/2.0.0-r-activity.sql checks there is nothing left to merge). The table is small:
 * 90 days of read items, 180 of unread. Each step is done on its own, so a half-run migration finishes. down() drops
 * the columns and the index; the merged items are not split again.
 */
return new class extends Migration
{
    /** How many items are read at a time (a test passes a small one to cross a page). */
    public int $chunk = 500;

    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            if (! Schema::hasColumn('notifications', 'dedupe_key')) {
                $table->string('dedupe_key', 120)->nullable();
            }
            if (! Schema::hasColumn('notifications', 'repeat_count')) {
                $table->unsignedInteger('repeat_count')->default(1);
            }
            if (! Schema::hasColumn('notifications', 'resolved_at')) {
                $table->timestamp('resolved_at')->nullable();
            }
        });

        $this->merge();

        if (! Schema::hasIndex('notifications', 'notifications_dedupe_unique')) {
            Schema::table('notifications', fn (Blueprint $table) => $table->unique(['notifiable_type', 'notifiable_id', 'dedupe_key'], 'notifications_dedupe_unique'));
        }
    }

    public function down(): void
    {
        if (Schema::hasIndex('notifications', 'notifications_dedupe_unique')) {
            Schema::table('notifications', fn (Blueprint $table) => $table->dropUnique('notifications_dedupe_unique'));
        }
        foreach (['dedupe_key', 'repeat_count', 'resolved_at'] as $column) {
            if (Schema::hasColumn('notifications', $column)) {
                Schema::table('notifications', fn (Blueprint $table) => $table->dropColumn($column));
            }
        }
    }

    /**
     * For each person, recording and kind: keep the newest item, count the rest into it, delete them. Only the ids, the
     * keys and the counts are read (the kind and the recording through the JSON path, never the whole `data`).
     */
    private function merge(): void
    {
        $groups = [];
        DB::table('notifications')->orderByDesc('created_at')->orderByDesc('id')
            ->select(['id', 'notifiable_type', 'notifiable_id', 'read_at', 'repeat_count', 'dedupe_key', 'data->viewData->kind as kind', 'data->viewData->session_id as session_id'])
            ->chunk($this->chunk, function ($rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $kind = is_string($row->kind) ? $row->kind : '';
                    $session = is_string($row->session_id) ? $row->session_id : '';
                    if ($kind === '' || $session === '' || $kind === 'null' || $session === 'null') {
                        continue;
                    }
                    $groups[$row->notifiable_type.'|'.$row->notifiable_id.'|'.$kind.':'.$session][] = [$row->id, $row->read_at, (int) $row->repeat_count, $row->dedupe_key];
                }
            });

        foreach ($groups as $group => $rows) {
            $key = substr($group, strrpos($group, '|') + 1);
            [$id, $readAt, $repeat, $current] = $rows[0];
            if (count($rows) === 1) {
                // Nothing to merge: only a key not written yet is written (a second run changes nothing).
                if ($current !== $key) {
                    DB::table('notifications')->where('id', $id)->update(['dedupe_key' => $key, 'repeat_count' => max(1, $repeat)]);
                }

                continue;
            }
            $unread = collect($rows)->contains(fn (array $row): bool => $row[1] === null);
            DB::transaction(function () use ($rows, $id, $readAt, $key, $unread): void {
                DB::table('notifications')->whereIn('id', array_map(fn (array $row) => $row[0], array_slice($rows, 1)))->delete();
                DB::table('notifications')->where('id', $id)->update([
                    'dedupe_key' => $key,
                    'repeat_count' => array_sum(array_map(fn (array $row): int => max(1, $row[2]), $rows)),
                    'read_at' => $unread ? null : $readAt,
                ]);
            });
        }

        $this->resolveFinished();
    }

    /**
     * Upload failures that are already over (BE-02 review: many from before this release, W-INC-1124's among them): the
     * recording is gone, in the undo bin, or none of its uploads is failed any more. They are resolved and read, so the
     * group and the badge start from what still needs the person. Only those not resolved yet are touched.
     */
    private function resolveFinished(): void
    {
        $now = now();
        // By id, not by offset: resolving a page takes its items out of `resolved_at is null`, and an offset would then
        // skip the next page's first ones (BE-02 review).
        DB::table('notifications')->where('dedupe_key', 'like', 'upload_failed:%')->whereNull('resolved_at')
            ->select(['id', 'dedupe_key', 'read_at'])
            ->chunkById($this->chunk, function ($rows) use ($now): void {
                $sessions = $rows->map(fn ($row): string => substr((string) $row->dedupe_key, strlen('upload_failed:')))->unique()->values()->all();
                $live = DB::table('capture_sessions')->whereIn('id', $sessions)->whereNull('deleted_at')->pluck('id')->flip();
                $failing = DB::table('recordings')->whereIn('capture_session_id', $sessions)->where('upload_status', 'failed')
                    ->distinct()->pluck('capture_session_id')->flip();
                foreach ($rows as $row) {
                    $session = substr((string) $row->dedupe_key, strlen('upload_failed:'));
                    if ($live->has($session) && $failing->has($session)) {
                        continue;
                    }
                    DB::table('notifications')->where('id', $row->id)->update(['resolved_at' => $now, 'read_at' => $row->read_at ?? $now]);
                }
            }, 'id');
    }
};
