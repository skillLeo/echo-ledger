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
 * the newest is kept, its repeat_count the number merged, unread when any of them was; the others are deleted. Running
 * it again changes nothing (deploy/checks/2.0.0-r-activity.sql checks there is nothing left to merge). The table is small:
 * 90 days of read items, 180 of unread. Each step is done on its own, so a half-run migration finishes. down() drops
 * the columns and the index; the merged items are not split again.
 */
return new class extends Migration
{
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

    /** For each person, recording and kind: keep the newest item, count the rest into it, delete them. */
    private function merge(): void
    {
        $groups = [];
        DB::table('notifications')->orderByDesc('created_at')->orderByDesc('id')
            ->select(['id', 'notifiable_type', 'notifiable_id', 'data', 'read_at', 'repeat_count'])
            ->chunk(500, function ($rows) use (&$groups): void {
                foreach ($rows as $row) {
                    $data = json_decode((string) $row->data, true);
                    $session = $data['viewData']['session_id'] ?? null;
                    $kind = $data['viewData']['kind'] ?? null;
                    if (! is_string($session) || $session === '' || ! is_string($kind) || $kind === '') {
                        continue;
                    }
                    $groups[$row->notifiable_type.'|'.$row->notifiable_id.'|'.$kind.':'.$session][] = $row;
                }
            });

        foreach ($groups as $group => $rows) {
            $key = substr($group, strrpos($group, '|') + 1);
            $newest = $rows[0];
            $unread = collect($rows)->contains(fn ($row): bool => $row->read_at === null);
            DB::transaction(function () use ($rows, $newest, $key, $unread): void {
                DB::table('notifications')->whereIn('id', array_map(fn ($row) => $row->id, array_slice($rows, 1)))->delete();
                DB::table('notifications')->where('id', $newest->id)->update([
                    'dedupe_key' => $key,
                    'repeat_count' => array_sum(array_map(fn ($row): int => max(1, (int) $row->repeat_count), $rows)),
                    'read_at' => $unread ? null : $newest->read_at,
                ]);
            });
        }
    }
};
