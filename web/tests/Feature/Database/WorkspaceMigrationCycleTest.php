<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| B20 migrations (docs/HANDOFF.md "B20 design"): up → down → up on a database that already holds a realistic
| library, on the test driver (SQLite here, MariaDB in the MariaDB run). Uses its own connection `cycle` so the
| test transaction of the default connection is not involved.
*/

const CYCLE_SCOPED = ['devices', 'device_pairing_codes', 'clients', 'capture_sessions', 'usage_ledger'];
const CYCLE_CHILDREN = ['recordings', 'transcripts', 'session_markers', 'session_labels', 'share_links', 'share_link_views', 'client_keyterms'];

function cycleConnection(): Connection
{
    $default = config('database.default');
    $config = config("database.connections.{$default}");
    if ($config['driver'] === 'sqlite') {
        $file = storage_path('framework/testing/cycle-'.getmypid().'.sqlite');
        @mkdir(dirname($file), 0777, true);
        file_put_contents($file, '');
        $config['database'] = $file;
    } else {
        $name = DB::connection($default)->getDatabaseName().'_cycle';
        DB::connection($default)->statement("CREATE DATABASE IF NOT EXISTS `{$name}`");
        $config['database'] = $name;
    }
    $config['foreign_key_constraints'] = true;
    config(['database.connections.cycle' => $config]);
    DB::purge('cycle');

    return DB::connection('cycle');
}

function cycleArtisan(string $command, array $options = []): void
{
    $code = Artisan::call($command, ['--database' => 'cycle', '--force' => true, ...$options]);
    expect($code)->toBe(0, Artisan::output());
}

/** Three users (two super admins, one plain user), each with a small library. Returns the ids per user. */
function seedPreWorkspaceLibrary(Connection $db): array
{
    $now = now();
    $owned = [];
    foreach ([['Owner', true], ['Second admin', true], ['Plain user', false]] as $i => [$name, $admin]) {
        $userId = $db->table('users')->insertGetId([
            'name' => $name, 'email' => "user{$i}@example.test", 'password' => 'x', 'is_super_admin' => $admin,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $device = (string) Str::ulid();
        $db->table('devices')->insert(['id' => $device, 'user_id' => $userId, 'name' => "Phone {$i}", 'platform' => 'android', 'created_at' => $now, 'updated_at' => $now]);
        $db->table('device_pairing_codes')->insert(['user_id' => $userId, 'code_hash' => hash('sha256', "code{$i}"), 'expires_at' => $now->copy()->addMinutes(10), 'device_id' => $device, 'created_at' => $now, 'updated_at' => $now]);
        $client = (string) Str::ulid();
        $db->table('clients')->insert(['id' => $client, 'user_id' => $userId, 'name' => 'Acme', 'created_at' => $now, 'updated_at' => $now]);
        $db->table('client_keyterms')->insert(['client_id' => $client, 'term' => 'EchoLedger', 'created_at' => $now, 'updated_at' => $now]);
        $sessions = [];
        foreach (range(1, 7) as $n) {
            $session = (string) Str::ulid();
            $sessions[] = $session;
            $db->table('capture_sessions')->insert([
                'id' => $session, 'user_id' => $userId, 'device_id' => $device, 'client_id' => $client, 'client_ref' => (string) Str::uuid(),
                'source_device' => 'phone', 'source_app' => 'whatsapp', 'started_at' => $now, 'status' => 'completed',
                'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $n === 7 ? $now : null,
            ]);
            $db->table('recordings')->insert([
                'id' => (string) Str::ulid(), 'capture_session_id' => $session, 'role' => 'primary', 'mime' => 'audio/ogg',
                'declared_size_bytes' => 1000, 'sha256' => str_repeat('a', 64), 'chunk_size_bytes' => 1000, 'total_chunks' => 1,
                'received_chunks' => '[0]', 'upload_status' => 'ready', 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->table('transcripts')->insert(['id' => (string) Str::ulid(), 'capture_session_id' => $session, 'kind' => 'final', 'provider' => 'assemblyai', 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
            $db->table('session_markers')->insert(['id' => (string) Str::ulid(), 'capture_session_id' => $session, 'at_ms' => 1000, 'created_at' => $now, 'updated_at' => $now]);
            $db->table('session_labels')->insert(['capture_session_id' => $session, 'label' => 'work']);
            $link = (string) Str::ulid();
            $db->table('share_links')->insert(['id' => $link, 'capture_session_id' => $session, 'token_hash' => hash('sha256', $session), 'created_at' => $now, 'updated_at' => $now]);
            $db->table('share_link_views')->insert(['share_link_id' => $link, 'outcome' => 'viewed', 'viewed_at' => $now]);
            $db->table('usage_ledger')->insert([
                'id' => (string) Str::ulid(), 'user_id' => $userId, 'device_id' => $device, 'capture_session_id' => $session,
                'source' => 'server', 'report_id' => "final/{$session}/a1", 'use_case' => 'final', 'provider' => 'assemblyai',
                'duration_ms' => 60000, 'channels' => 2, 'billed_ms' => 120000, 'unit_price_usd' => 0.15, 'cost_usd' => 0.005,
                'billable' => true, 'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        $owned[$userId] = ['device' => $device, 'client' => $client, 'sessions' => $sessions];
    }

    return $owned;
}

/** @return array<string, int> */
function cycleCounts(Connection $db): array
{
    return collect([...CYCLE_SCOPED, ...CYCLE_CHILDREN, 'users'])->mapWithKeys(fn (string $t): array => [$t => $db->table($t)->count()])->all();
}

function assertBackfilled(Connection $db, array $owned): void
{
    $schema = $db->getSchemaBuilder();
    expect($schema->hasTable('workspaces'))->toBeTrue()
        ->and($db->table('plans')->where('key', 'personal')->count())->toBe(1)
        ->and($db->table('workspaces')->count())->toBe(3);

    $firstAdmin = array_key_first($owned);
    foreach (array_keys($owned) as $userId) {
        $workspace = (int) $db->table('users')->where('id', $userId)->value('current_workspace_id');
        expect($workspace)->toBeGreaterThan(0)
            ->and($db->table('workspace_user')->where(['user_id' => $userId, 'workspace_id' => $workspace, 'role' => 'owner'])->count())->toBe(1);
        if ($userId === $firstAdmin) {
            expect($db->table('workspaces')->where('id', $workspace)->value('name'))->toBe('Personal');
        }
        foreach (CYCLE_SCOPED as $table) {
            $rows = $db->table($table)->where('user_id', $userId)->pluck('workspace_id')->map(fn ($v): int => (int) $v)->unique()->values()->all();
            expect($rows)->toBe([$workspace], "{$table} of user {$userId}");
        }
    }
    foreach (CYCLE_SCOPED as $table) {
        expect($db->table($table)->whereNull('workspace_id')->count())->toBe(0, $table);
    }
}

/** The three B20 migrations, and how many migrations came after them (rolled back first, migrated again after). */
const WORKSPACE_STEPS = 3;
const LATER_MIGRATIONS = 5;

it('runs the workspace migrations up, down and up again without losing or mixing data', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => WORKSPACE_STEPS + LATER_MIGRATIONS]);
    expect($db->getSchemaBuilder()->hasTable('workspaces'))->toBeFalse();

    $owned = seedPreWorkspaceLibrary($db);
    $before = cycleCounts($db);

    cycleArtisan('migrate');
    assertBackfilled($db, $owned);
    expect(cycleCounts($db))->toBe($before);

    // NOT NULL + foreign key are in force after step 3.
    expect(fn () => $db->table('clients')->insert(['id' => (string) Str::ulid(), 'user_id' => array_key_first($owned), 'name' => 'No workspace', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);
    expect(fn () => $db->table('clients')->insert(['id' => (string) Str::ulid(), 'user_id' => array_key_first($owned), 'workspace_id' => 999999, 'name' => 'Ghost', 'created_at' => now(), 'updated_at' => now()]))
        ->toThrow(QueryException::class);

    cycleArtisan('migrate:rollback', ['--step' => WORKSPACE_STEPS + LATER_MIGRATIONS]);
    $schema = $db->getSchemaBuilder();
    expect($schema->hasTable('workspaces'))->toBeFalse()
        ->and($schema->hasTable('plans'))->toBeFalse()
        ->and($schema->hasColumn('users', 'current_workspace_id'))->toBeFalse();
    foreach (CYCLE_SCOPED as $table) {
        expect($schema->hasColumn($table, 'workspace_id'))->toBeFalse($table);
    }
    expect(cycleCounts($db))->toBe($before);

    cycleArtisan('migrate');
    assertBackfilled($db, $owned);
    expect(cycleCounts($db))->toBe($before);

    // Running the backfill again changes nothing (no second workspace, no moved rows).
    $snapshot = $db->table('capture_sessions')->orderBy('id')->pluck('workspace_id', 'id')->all();
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection('cycle');
    try {
        (require database_path('migrations/2026_11_01_000002_backfill_workspaces.php'))->up();
    } finally {
        DB::setDefaultConnection($default);
    }
    expect($db->table('workspaces')->count())->toBe(3)
        ->and($db->table('capture_sessions')->orderBy('id')->pluck('workspace_id', 'id')->all())->toBe($snapshot);
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

it('refuses step 3 while a row has no workspace and changes nothing', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + LATER_MIGRATIONS]);
    $now = now();
    $userId = $db->table('users')->insertGetId(['name' => 'Late', 'email' => 'late@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('clients')->insert(['id' => (string) Str::ulid(), 'user_id' => $userId, 'name' => 'Orphan', 'created_at' => $now, 'updated_at' => $now]);

    expect(fn () => Artisan::call('migrate', ['--database' => 'cycle', '--force' => true]))
        ->toThrow(RuntimeException::class, 'have no workspace_id');
    expect(collect(Schema::connection('cycle')->getColumns('clients'))->firstWhere('name', 'workspace_id')['nullable'])->toBeTrue()
        ->and($db->table('migrations')->where('migration', '2026_11_01_000003_require_workspace_id')->exists())->toBeFalse();
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});
