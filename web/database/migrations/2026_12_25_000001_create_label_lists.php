<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * B11, contract 2.0.0-m "Lists": one person's lists in one workspace, kept by the server so every device shows the same
 * ones in the same order. A list is a label (label_key, the label in lower case as label_colors has it) with the name as
 * typed, its colour and its place in the row. Same owner and workspace keys as label_colors, so a teammate's lists are
 * their own. Up fills one row a label each person carries (deleted recordings too), in name order, with the colour the
 * label had; a label spelt as a fixed chip (All, Unread, Important) stays on its recordings, with its colour in
 * label_colors, but is never a list. From then on the colours live here and label_colors is left as it was. Down
 * writes the colours back to label_colors (so the release before shows the colours chosen since) and drops the table.
 * Safe to run again.
 */
return new class extends Migration
{
    /** The apps' fixed chips as LabelLists::RESERVED has them at 2.0.0-m (copied: a migration does not follow later code). */
    private const RESERVED = ['all', 'unread', 'important', 'سب', 'نہ پڑھی گئی', 'اہم'];

    public function up(): void
    {
        if (! Schema::hasTable('label_lists')) {
            Schema::create('label_lists', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                // As label_colors.label_key: mb_strtolower of the label, compared byte for byte on MySQL and MariaDB.
                $key = $table->string('label_key', 160);
                if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
                    $key->collation('utf8mb4_bin');
                }
                $table->string('name', 160);
                $table->string('color', 7)->nullable();
                $table->unsignedInteger('position')->default(0);
                $table->timestamps();
                $table->unique(['workspace_id', 'user_id', 'label_key']);
                $table->index(['workspace_id', 'user_id', 'position']);
            });
        }
        if (DB::table('label_lists')->exists()) {
            return;
        }
        $colors = [];
        foreach (DB::table('label_colors')->get(['workspace_id', 'user_id', 'label_key', 'color']) as $row) {
            $colors[$row->workspace_id.'|'.$row->user_id.'|'.$row->label_key] = strtolower((string) $row->color);
        }
        // Each person's labels, spelt as their first recording with it does; grouped in PHP, as LOWER() and the
        // collations disagree on letters outside English from one database to the next.
        $people = [];
        DB::table('session_labels')->join('capture_sessions', 'capture_sessions.id', '=', 'session_labels.capture_session_id')
            ->orderBy('session_labels.id')
            ->select(['session_labels.id', 'session_labels.label', 'capture_sessions.workspace_id', 'capture_sessions.user_id'])
            ->chunk(1000, function ($rows) use (&$people): void {
                foreach ($rows as $row) {
                    $person = $row->workspace_id.'|'.$row->user_id;
                    $key = mb_strtolower((string) $row->label);
                    $people[$person][$key] ??= (string) $row->label;
                }
            });
        $now = now();
        foreach ($people as $person => $labels) {
            [$workspaceId, $userId] = array_map('intval', explode('|', (string) $person));
            uksort($labels, fn (string $a, string $b): int => strcmp($a, $b));
            $rows = [];
            $position = 0;
            foreach ($labels as $key => $name) {
                if (in_array($key, self::RESERVED, true)) {
                    // A label spelt as a fixed chip stays on its recordings (and its colour in label_colors) but is
                    // never a list.
                    continue;
                }
                $rows[] = ['workspace_id' => $workspaceId, 'user_id' => $userId, 'label_key' => $key, 'name' => $name,
                    'color' => $colors[$person.'|'.$key] ?? null, 'position' => $position++, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table('label_lists')->insertOrIgnore($chunk);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('label_lists')) {
            return;
        }
        if (Schema::hasTable('label_colors')) {
            DB::table('label_lists')->orderBy('id')->chunk(500, function ($rows): void {
                $set = [];
                foreach ($rows as $row) {
                    if ($row->color === null) {
                        DB::table('label_colors')->where(['workspace_id' => $row->workspace_id, 'user_id' => $row->user_id, 'label_key' => $row->label_key])->delete();
                    } else {
                        $set[] = ['workspace_id' => $row->workspace_id, 'user_id' => $row->user_id, 'label_key' => $row->label_key, 'color' => $row->color,
                            'created_at' => $row->created_at, 'updated_at' => $row->updated_at];
                    }
                }
                if ($set !== []) {
                    DB::table('label_colors')->upsert($set, ['workspace_id', 'user_id', 'label_key'], ['color', 'updated_at']);
                }
            });
        }
        Schema::drop('label_lists');
    }
};
