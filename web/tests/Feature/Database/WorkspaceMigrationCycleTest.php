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
const LATER_MIGRATIONS = 39;

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

/** The v1.5 Phase 2 migrations (projects, filing rules, share audiences, sent items, request links). */
const PHASE2_STEPS = 5;

/** Migrations that came after them (rolled back first); add yours here as for LATER_MIGRATIONS. */
const AFTER_PHASE2 = 24;

/** A workspace's library as v1.5 Phase 2 leaves it: subfolders, filing, rules, project and people links, a send, a request link. */
function seedPhase2Library(Connection $db): array
{
    $now = now();
    $userId = $db->table('users')->insertGetId(['name' => 'Owner', 'email' => 'owner@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $friend = $db->table('users')->insertGetId(['name' => 'Friend', 'email' => 'friend@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $planId = (int) $db->table('plans')->value('id');
    $workspace = $db->table('workspaces')->insertGetId(['name' => 'Personal', 'plan_id' => $planId, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('workspace_user')->insert(['workspace_id' => $workspace, 'user_id' => $userId, 'role' => 'owner', 'created_at' => $now, 'updated_at' => $now]);
    [$acme, $site] = [(string) Str::ulid(), (string) Str::ulid()];
    $db->table('clients')->insert(['id' => $acme, 'workspace_id' => $workspace, 'user_id' => $userId, 'name' => 'Acme', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('clients')->insert(['id' => $site, 'workspace_id' => $workspace, 'user_id' => $userId, 'parent_id' => $acme, 'name' => 'Website', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('client_keyterms')->insert(['client_id' => $site, 'term' => 'Falcon', 'created_at' => $now, 'updated_at' => $now]);
    $session = (string) Str::ulid();
    $db->table('capture_sessions')->insert(['id' => $session, 'workspace_id' => $workspace, 'user_id' => $userId, 'client_id' => $site, 'filed_by' => 'rule',
        'origin' => 'requested', 'client_ref' => (string) Str::uuid(), 'source_device' => 'web', 'source_app' => 'voice_note', 'started_at' => $now, 'status' => 'completed',
        'created_at' => $now, 'updated_at' => $now]);
    $db->table('filing_rules')->insert(['workspace_id' => $workspace, 'client_id' => $site, 'kind' => 'app', 'value' => 'zoom', 'match_value' => 'zoom', 'created_at' => $now, 'updated_at' => $now]);
    $links = ['session' => (string) Str::ulid(), 'people' => (string) Str::ulid(), 'project' => (string) Str::ulid()];
    $db->table('share_links')->insert(['id' => $links['session'], 'capture_session_id' => $session, 'token_hash' => hash('sha256', 'a'), 'audience' => 'password', 'password_hash' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('share_links')->insert(['id' => $links['people'], 'capture_session_id' => $session, 'token_hash' => hash('sha256', 'b'), 'audience' => 'people', 'people' => '["ana@example.test"]', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('share_links')->insert(['id' => $links['project'], 'client_id' => $acme, 'token_hash' => hash('sha256', 'c'), 'created_at' => $now, 'updated_at' => $now]);
    $db->table('share_link_views')->insert(['share_link_id' => $links['project'], 'outcome' => 'viewed', 'viewed_at' => $now]);
    $db->table('sent_items')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $workspace, 'from_user_id' => $userId, 'to_user_id' => $friend, 'kind' => 'session',
        'capture_session_id' => $session, 'title' => 'Call', 'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('request_links')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $workspace, 'user_id' => $userId, 'client_id' => $site, 'token_hash' => hash('sha256', 'd'),
        'title' => 'Files', 'created_at' => $now, 'updated_at' => $now]);

    return ['user' => $userId, 'acme' => $acme, 'site' => $site, 'session' => $session, 'links' => $links];
}

it('runs the v1.5 Phase 2 migrations down and up again on a library that uses them', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $seeded = seedPhase2Library($db);

    cycleArtisan('migrate:rollback', ['--step' => PHASE2_STEPS + AFTER_PHASE2]);
    $schema = $db->getSchemaBuilder();
    expect($schema->hasColumn('clients', 'parent_id'))->toBeFalse()
        ->and($schema->hasColumn('capture_sessions', 'filed_by'))->toBeFalse()
        ->and($schema->hasColumn('share_links', 'audience'))->toBeFalse()
        ->and($schema->hasTable('filing_rules'))->toBeFalse()
        ->and($schema->hasTable('sent_items'))->toBeFalse()
        ->and($schema->hasTable('request_links'))->toBeFalse()
        // Nothing of the library is lost: the subfolder is a top-level project, the recording stays in it.
        ->and($db->table('clients')->count())->toBe(2)
        ->and($db->table('client_keyterms')->count())->toBe(1)
        ->and($db->table('capture_sessions')->where('id', $seeded['session'])->value('client_id'))->toBe($seeded['site'])
        // A project link cannot live in the old table; a people-only link would open for anyone, so it is revoked.
        ->and($db->table('share_links')->pluck('id')->sort()->values()->all())->toBe(collect([$seeded['links']['session'], $seeded['links']['people']])->sort()->values()->all())
        ->and($db->table('share_links')->where('id', $seeded['links']['people'])->value('revoked_at'))->not->toBeNull()
        ->and($db->table('share_links')->where('id', $seeded['links']['session'])->value('password_hash'))->toBe('x')
        ->and($db->table('share_link_views')->count())->toBe(0);
    expect(collect(Schema::connection('cycle')->getColumns('share_links'))->firstWhere('name', 'capture_session_id')['nullable'])->toBeFalse();

    cycleArtisan('migrate');
    expect($schema->hasColumn('clients', 'parent_id'))->toBeTrue()
        ->and($schema->hasTable('request_links'))->toBeTrue()
        ->and($db->table('capture_sessions')->where('id', $seeded['session'])->value('filed_by'))->toBe('user')
        ->and($db->table('share_links')->where('id', $seeded['links']['session'])->value('audience'))->toBe('password');
    // Names are unique per folder again: the same name under two parents is fine, twice in one folder is not.
    $now = now();
    $db->table('clients')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $db->table('clients')->value('workspace_id'), 'user_id' => $seeded['user'], 'parent_id' => $seeded['acme'], 'name' => 'Notes', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('clients')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $db->table('clients')->value('workspace_id'), 'user_id' => $seeded['user'], 'parent_id' => $seeded['site'], 'name' => 'Notes', 'created_at' => $now, 'updated_at' => $now]);
    expect(fn () => $db->table('clients')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $db->table('clients')->value('workspace_id'), 'user_id' => $seeded['user'], 'parent_id' => $seeded['site'], 'name' => 'Notes', 'created_at' => $now, 'updated_at' => $now]))
        ->toThrow(QueryException::class);
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

it('refuses to roll projects back while one person has two projects of the same name, and changes nothing', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $seeded = seedPhase2Library($db);
    $now = now();
    // "Website" twice: once under Acme (seeded), once at the top. The old unique index could not hold both.
    $db->table('clients')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $db->table('clients')->value('workspace_id'), 'user_id' => $seeded['user'], 'name' => 'Website', 'created_at' => $now, 'updated_at' => $now]);
    cycleArtisan('migrate:rollback', ['--step' => PHASE2_STEPS - 1 + AFTER_PHASE2]);

    expect(fn () => Artisan::call('migrate:rollback', ['--database' => 'cycle', '--force' => true, '--step' => 1]))
        ->toThrow(RuntimeException::class, 'rename one of them before rolling back');
    expect($db->getSchemaBuilder()->hasColumn('clients', 'parent_id'))->toBeTrue()
        ->and($db->table('migrations')->where('migration', '2026_11_14_000001_add_projects_to_clients')->exists())->toBeTrue();
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

/** The v1.5 Phase 3 migration (screen moments), and how many came after it (rolled back first). */
const PHASE3_STEPS = 1;

const AFTER_PHASE3 = 22;

it('runs the v1.5 Phase 3 migration down and up again on a library with screen moments', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $seeded = seedPhase2Library($db);
    $now = now();
    $db->table('screens')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $db->table('clients')->value('workspace_id'), 'capture_session_id' => $seeded['session'],
        'at_ms' => 872000, 'width' => 1920, 'height' => 1080, 'trigger' => 'change', 'sha256' => str_repeat('b', 64), 'mime' => 'image/webp', 'bytes' => 10,
        'created_at' => $now, 'updated_at' => $now]);
    $db->table('usage_ledger')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $db->table('clients')->value('workspace_id'), 'user_id' => $seeded['user'],
        'source' => 'server', 'report_id' => 'screens/x', 'use_case' => 'screen_describe', 'provider' => 'anthropic', 'duration_ms' => 0, 'channels' => 1,
        'billed_ms' => 0, 'input_tokens' => 1200, 'output_tokens' => 80, 'unit_price_usd' => 0, 'cost_usd' => 0.00016, 'billable' => true, 'occurred_at' => $now,
        'created_at' => $now, 'updated_at' => $now]);
    $db->table('clients')->where('id', $seeded['site'])->update(['screens' => false]);

    cycleArtisan('migrate:rollback', ['--step' => PHASE3_STEPS + AFTER_PHASE3]);
    $schema = $db->getSchemaBuilder();
    expect($schema->hasTable('screens'))->toBeFalse()
        ->and($schema->hasColumn('clients', 'screens'))->toBeFalse()
        ->and($schema->hasColumn('usage_ledger', 'input_tokens'))->toBeFalse()
        // Nothing else is lost: the recording, its projects and the spend stay.
        ->and($db->table('capture_sessions')->count())->toBe(1)
        ->and($db->table('clients')->count())->toBe(2)
        ->and($db->table('usage_ledger')->count())->toBe(1);

    cycleArtisan('migrate');
    expect($schema->hasTable('screens'))->toBeTrue()
        ->and($db->table('screens')->count())->toBe(0)
        // Projects capture screens again by default.
        ->and((bool) $db->table('clients')->where('id', $seeded['site'])->value('screens'))->toBeTrue()
        ->and($db->table('usage_ledger')->value('input_tokens'))->toBeNull();
    // One picture per recording and SHA-256.
    $row = ['workspace_id' => $db->table('clients')->value('workspace_id'), 'capture_session_id' => $seeded['session'], 'at_ms' => 1, 'width' => 1, 'height' => 1,
        'trigger' => 'manual', 'sha256' => str_repeat('c', 64), 'mime' => 'image/png', 'bytes' => 1, 'created_at' => $now, 'updated_at' => $now];
    $db->table('screens')->insert(['id' => (string) Str::ulid(), ...$row]);
    expect(fn () => $db->table('screens')->insert(['id' => (string) Str::ulid(), ...$row]))->toThrow(QueryException::class);
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

/** The v1.5 account migrations (accounts, data exports), and how many came after them (rolled back first). */
const ACCOUNT_STEPS = 2;

const AFTER_ACCOUNTS = 20;

it('runs the v1.5 account migrations down and up again without touching the library', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $seeded = seedPhase2Library($db);
    $now = now();
    $workspace = (int) $db->table('workspaces')->value('id');
    $db->table('users')->where('id', $seeded['user'])->update(['google_id' => 'google-1', 'deletion_requested_at' => $now, 'deletes_at' => $now->copy()->addDays(7), 'notify_transcript_ready' => true]);
    $db->table('device_sign_in_codes')->insert(['user_id' => $seeded['user'], 'workspace_id' => $workspace, 'code_hash' => str_repeat('a', 64), 'code_challenge' => str_repeat('b', 43),
        'name' => 'Pixel', 'platform' => 'android', 'expires_at' => $now->copy()->addMinutes(5), 'created_at' => $now, 'updated_at' => $now]);
    $db->table('known_browsers')->insert(['user_id' => $seeded['user'], 'token_hash' => str_repeat('c', 64), 'label' => 'Chrome on Windows', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('workspace_invitations')->insert(['workspace_id' => $workspace, 'email' => 'new@example.test', 'role' => 'member', 'token_hash' => str_repeat('d', 64),
        'invited_by' => $seeded['user'], 'expires_at' => $now->copy()->addDays(7), 'created_at' => $now, 'updated_at' => $now]);
    $db->table('account_exports')->insert(['id' => (string) Str::ulid(), 'user_id' => $seeded['user'], 'status' => 'ready', 'path' => 'exports/x.zip.elr', 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate:rollback', ['--step' => ACCOUNT_STEPS + AFTER_ACCOUNTS]);
    $schema = $db->getSchemaBuilder();
    foreach (['device_sign_in_codes', 'known_browsers', 'workspace_invitations', 'account_exports'] as $table) {
        expect($schema->hasTable($table))->toBeFalse($table);
    }
    foreach (['google_id', 'deletion_requested_at', 'deletes_at', 'consent_seen_at', 'notify_transcript_ready'] as $column) {
        expect($schema->hasColumn('users', $column))->toBeFalse($column);
    }
    expect($schema->hasColumn('devices', 'last_country'))->toBeFalse()
        // Nothing else is lost: the people, their workspace, the library and its links.
        ->and($db->table('users')->count())->toBe(2)
        ->and($db->table('workspace_user')->count())->toBe(1)
        ->and($db->table('capture_sessions')->count())->toBe(1)
        ->and($db->table('clients')->count())->toBe(2)
        ->and($db->table('share_links')->count())->toBe(3)
        ->and($db->table('sent_items')->count())->toBe(1)
        ->and($db->table('request_links')->count())->toBe(1);

    cycleArtisan('migrate');
    foreach (['device_sign_in_codes', 'known_browsers', 'workspace_invitations', 'account_exports'] as $table) {
        expect($schema->hasTable($table))->toBeTrue($table)->and($db->table($table)->count())->toBe(0);
    }
    $owner = $db->table('users')->find($seeded['user']);
    // Accounts that exist are past their sign-up: no consent notice for them, nothing being deleted, no Google link.
    expect($owner->consent_seen_at)->not->toBeNull()
        ->and($owner->deletes_at)->toBeNull()
        ->and($owner->google_id)->toBeNull()
        ->and((bool) $owner->notify_transcript_ready)->toBeFalse()
        ->and($db->table('users')->whereNull('consent_seen_at')->count())->toBe(0);
    // One Google account links to one account only.
    $db->table('users')->where('id', $seeded['user'])->update(['google_id' => 'google-2']);
    expect(fn () => $db->table('users')->where('id', '!=', $seeded['user'])->update(['google_id' => 'google-2']))->toThrow(QueryException::class);
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

/** The v1.5 plans, roles and privacy migration; add later ones to the count as for LATER_MIGRATIONS. */
const AFTER_PLANS = 19;

/** Passport's four tables and Connected apps (v1.5, the Claude and ChatGPT connector); add later ones as for LATER_MIGRATIONS. */
const CONNECTOR_STEPS = 5;

const AFTER_CONNECTOR = 12;

const CONNECTOR_TABLES = ['oauth_auth_codes', 'oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_clients', 'connected_apps'];

it('runs the v1.5 connector migrations down and up again without touching the library', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $seeded = seedPhase2Library($db);
    $now = now();
    $client = (string) Str::uuid();
    $db->table('oauth_clients')->insert(['id' => $client, 'name' => 'Claude', 'redirect_uris' => '["https://claude.ai/api/mcp/auth_callback"]', 'grant_types' => '["authorization_code","refresh_token"]', 'revoked' => false, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('oauth_auth_codes')->insert(['id' => str_repeat('a', 80), 'user_id' => $seeded['user'], 'client_id' => $client, 'scopes' => '["mcp:use"]', 'revoked' => false, 'expires_at' => $now->copy()->addMinutes(10)]);
    $db->table('oauth_access_tokens')->insert(['id' => str_repeat('b', 80), 'user_id' => $seeded['user'], 'client_id' => $client, 'scopes' => '["mcp:use"]', 'revoked' => false, 'created_at' => $now, 'updated_at' => $now, 'expires_at' => $now->copy()->addHour()]);
    $db->table('oauth_refresh_tokens')->insert(['id' => str_repeat('c', 80), 'access_token_id' => str_repeat('b', 80), 'revoked' => false, 'expires_at' => $now->copy()->addDays(30)]);
    $db->table('connected_apps')->insert(['user_id' => $seeded['user'], 'client_id' => $client]);
    expect($db->table('connected_apps')->value('created_at'))->not->toBeNull();
    // One row per person and app.
    expect(fn () => $db->table('connected_apps')->insert(['user_id' => $seeded['user'], 'client_id' => $client]))->toThrow(QueryException::class);

    cycleArtisan('migrate:rollback', ['--step' => CONNECTOR_STEPS + AFTER_CONNECTOR]);
    $schema = $db->getSchemaBuilder();
    foreach (CONNECTOR_TABLES as $table) {
        expect($schema->hasTable($table))->toBeFalse($table);
    }
    // Nothing else is lost.
    expect($db->table('users')->count())->toBe(2)
        ->and($db->table('capture_sessions')->count())->toBe(1)
        ->and($db->table('clients')->count())->toBe(2)
        ->and($db->table('share_links')->count())->toBe(3);

    cycleArtisan('migrate');
    foreach (CONNECTOR_TABLES as $table) {
        expect($schema->hasTable($table))->toBeTrue($table)->and($db->table($table)->count())->toBe(0);
    }
    // An account's connections go with it.
    $db->table('connected_apps')->insert(['user_id' => $seeded['user'], 'client_id' => $client]);
    $db->table('capture_sessions')->delete();
    $db->table('request_links')->delete();
    $db->table('users')->where('id', $seeded['user'])->delete();
    expect($db->table('connected_apps')->count())->toBe(0);
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

it('runs the v1.5 plans and roles migration down and up again without touching the library', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $seeded = seedPhase2Library($db);
    $now = now();
    $workspace = (int) $db->table('workspaces')->value('id');
    $free = (int) $db->table('plans')->where('key', 'free')->value('id');
    expect($free)->toBeGreaterThan(0)
        // Every feature and no limits on Personal; the placeholders on Free.
        ->and($db->table('plans')->where('key', 'personal')->value('monthly_minutes'))->toBeNull()
        ->and(json_decode((string) $db->table('plans')->where('key', 'personal')->value('features'), true))->toBe(['live_translate' => true, 'screen_moments' => true, 'ai_notes' => true, 'connector' => true])
        // Since v1.6 the default plans' migration gives Free its own limits (no minutes of the server's own engines).
        ->and((int) $db->table('plans')->where('key', 'free')->value('monthly_minutes'))->toBe(0)
        ->and((int) $db->table('plans')->where('key', 'free')->value('seats'))->toBe(1);
    // A rule made before the migration was back-filled to its project's maker when the seed ran after it; do it by hand here.
    $db->table('filing_rules')->update(['user_id' => $seeded['user']]);
    $viewer = $db->table('users')->where('email', 'friend@example.test')->value('id');
    $db->table('workspace_user')->insert(['workspace_id' => $workspace, 'user_id' => $viewer, 'role' => 'viewer', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('workspaces')->where('id', $workspace)->update(['plan_id' => $free]);
    $db->table('screens')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $workspace, 'capture_session_id' => $seeded['session'], 'at_ms' => 1, 'width' => 1, 'height' => 1,
        'trigger' => 'manual', 'sha256' => str_repeat('e', 64), 'mime' => 'image/png', 'bytes' => 10, 'stored_bytes' => 40, 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_PLANS]);
    $schema = $db->getSchemaBuilder();
    foreach (['price_usd', 'retention_days', 'seats', 'ai_credits_usd', 'features'] as $column) {
        expect($schema->hasColumn('plans', $column))->toBeFalse($column);
    }
    expect($schema->hasColumn('filing_rules', 'user_id'))->toBeFalse()
        ->and($schema->hasColumn('screens', 'stored_bytes'))->toBeFalse()
        // Free is gone and its workspaces are on Personal, with the B20 limits back; the viewer acts as a member.
        ->and($db->table('plans')->where('key', 'free')->exists())->toBeFalse()
        ->and((int) $db->table('workspaces')->where('id', $workspace)->value('plan_id'))->toBe((int) $db->table('plans')->where('key', 'personal')->value('id'))
        ->and((int) $db->table('plans')->where('key', 'personal')->value('monthly_minutes'))->toBe(30000)
        ->and($db->table('workspace_user')->where('user_id', $viewer)->value('role'))->toBe('member')
        // Nothing else is lost.
        ->and($db->table('capture_sessions')->count())->toBe(1)
        ->and($db->table('clients')->count())->toBe(2)
        ->and($db->table('screens')->count())->toBe(1)
        ->and($db->table('filing_rules')->count())->toBe(1);
    // The B20 rule again: one rule per workspace.
    $rule = ['workspace_id' => $workspace, 'client_id' => $seeded['acme'], 'kind' => 'app', 'value' => 'zoom', 'match_value' => 'zoom', 'created_at' => $now, 'updated_at' => $now];
    expect(fn () => $db->table('filing_rules')->insert($rule))->toThrow(QueryException::class);

    cycleArtisan('migrate');
    expect($db->table('plans')->where('key', 'free')->exists())->toBeTrue()
        ->and($db->table('plans')->where('key', 'personal')->value('monthly_minutes'))->toBeNull()
        // The rule went back to its project's maker, and the same person cannot have it twice.
        ->and((int) $db->table('filing_rules')->value('user_id'))->toBe($seeded['user'])
        ->and(fn () => $db->table('filing_rules')->insert([...$rule, 'user_id' => $seeded['user']]))->toThrow(QueryException::class);
    // Another person may have the same rule; then the rollback refuses to pick one of them and changes nothing.
    $db->table('filing_rules')->insert([...$rule, 'user_id' => $viewer]);
    expect(fn () => cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_PLANS]))->toThrow(RuntimeException::class, 'remove one of them');
    expect($schema->hasColumn('filing_rules', 'user_id'))->toBeTrue();
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

it('gives each person a project of their own for what they filed or nested in someone else\'s before v1.5', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $seeded = seedPhase2Library($db);
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_PLANS]);
    $now = now();
    $owner = $seeded['user'];
    $friend = (int) $db->table('users')->where('email', 'friend@example.test')->value('id');
    $workspace = (int) $db->table('workspaces')->value('id');
    $db->table('workspace_user')->insert(['workspace_id' => $workspace, 'user_id' => $friend, 'role' => 'member', 'created_at' => $now, 'updated_at' => $now]);
    $project = fn (int $user, string $name, ?string $parent = null): string => tap((string) Str::ulid(), fn (string $id) => $db->table('clients')->insert(
        ['id' => $id, 'workspace_id' => $workspace, 'user_id' => $user, 'parent_id' => $parent, 'name' => $name, 'color' => '#123456', 'ai_notes' => true, 'created_at' => $now, 'updated_at' => $now]));
    $call = fn (int $user, ?string $client): string => tap((string) Str::ulid(), fn (string $id) => $db->table('capture_sessions')->insert(['id' => $id, 'workspace_id' => $workspace,
        'user_id' => $user, 'client_id' => $client, 'filed_by' => $client === null ? null : 'user', 'client_ref' => (string) Str::uuid(), 'source_device' => 'phone',
        'source_app' => 'whatsapp', 'started_at' => $now, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]));
    $note = fn (string $client, string $session): string => tap((string) Str::ulid(), fn (string $id) => $db->table('call_notes')->insert(['id' => $id, 'workspace_id' => $workspace,
        'client_id' => $client, 'capture_session_id' => $session, 'status' => 'ready', 'sections' => '{}', 'created_at' => $now, 'updated_at' => $now]));
    $line = fn (string $client, string $type, ?string $source, bool $pinned = false): string => tap((string) Str::ulid(), fn (string $id) => $db->table('client_memory_items')->insert(['id' => $id,
        'workspace_id' => $workspace, 'client_id' => $client, 'kind' => 'decision', 'text' => 'A decision', 'pinned' => $pinned, 'source_type' => $type, 'source_id' => $source,
        'created_at' => $now, 'updated_at' => $now]));

    // The friend's folder inside the owner's Acme, and an owner's folder inside that one.
    $friendFolder = $project($friend, 'Friend calls', $seeded['acme']);
    $ownerFolder = $project($owner, 'Owner inside', $friendFolder);
    // The friend's own top-level Website, so their copy of the owner's Website is "Website (2)".
    $friendWebsite = $project($friend, 'Website');
    // A friend's call filed under Acme with its notes and memory lines; another one moved to the Inbox since.
    $filed = $call($friend, $seeded['acme']);
    $filedNote = $note($seeded['acme'], $filed);
    $filedLine = $line($seeded['acme'], 'call', $filed, true);
    $moved = $call($friend, null);
    $movedNote = $note($seeded['acme'], $moved);
    // The owner's own notes and lines stay where they are.
    $ownNote = $note($seeded['site'], $seeded['session']);
    $ownLine = $line($seeded['site'], 'call', $seeded['session']);
    $handLine = $line($seeded['acme'], 'user', null);
    $link = (string) Str::ulid();
    $db->table('request_links')->insert(['id' => $link, 'workspace_id' => $workspace, 'user_id' => $friend, 'client_id' => $seeded['site'], 'token_hash' => hash('sha256', 'friend'),
        'title' => 'Files', 'created_at' => $now, 'updated_at' => $now]);
    $before = ['clients' => $db->table('clients')->count(), 'capture_sessions' => $db->table('capture_sessions')->count(), 'call_notes' => $db->table('call_notes')->count(), 'client_memory_items' => $db->table('client_memory_items')->count()];

    cycleArtisan('migrate');
    $copy = fn (int $user, string $name): ?object => $db->table('clients')->where('user_id', $user)->whereNull('parent_id')->where('name', $name)->first();
    $friendAcme = $copy($friend, 'Acme');
    $ownerFriendCalls = $copy($owner, 'Friend calls');
    $friendSite = $copy($friend, 'Website (2)');
    expect($friendAcme)->not->toBeNull()->and($ownerFriendCalls)->not->toBeNull()->and($friendSite)->not->toBeNull()
        ->and($ownerFriendCalls->color)->toBe('#123456')->and((int) $friendAcme->workspace_id)->toBe($workspace)
        // Folders sit in a project of their own maker.
        ->and($db->table('clients')->where('id', $friendFolder)->value('parent_id'))->toBe($friendAcme->id)
        ->and($db->table('clients')->where('id', $ownerFolder)->value('parent_id'))->toBe($ownerFriendCalls->id)
        ->and($db->table('clients')->where('id', $friendWebsite)->value('parent_id'))->toBeNull()
        // The friend's call, its notes and its (still pinned) line moved together; the moved one's notes followed it.
        ->and($db->table('capture_sessions')->where('id', $filed)->value('client_id'))->toBe($friendAcme->id)
        ->and($db->table('call_notes')->where('id', $filedNote)->value('client_id'))->toBe($friendAcme->id)
        ->and($db->table('client_memory_items')->where('id', $filedLine)->value('client_id'))->toBe($friendAcme->id)
        ->and((bool) $db->table('client_memory_items')->where('id', $filedLine)->value('pinned'))->toBeTrue()
        ->and($db->table('capture_sessions')->where('id', $moved)->value('client_id'))->toBeNull()
        ->and($db->table('call_notes')->where('id', $movedNote)->value('client_id'))->toBe($friendAcme->id)
        ->and($db->table('request_links')->where('id', $link)->value('client_id'))->toBe($friendSite->id)
        // The owner's own things stay.
        ->and($db->table('capture_sessions')->where('id', $seeded['session'])->value('client_id'))->toBe($seeded['site'])
        ->and($db->table('call_notes')->where('id', $ownNote)->value('client_id'))->toBe($seeded['site'])
        ->and($db->table('client_memory_items')->where('id', $ownLine)->value('client_id'))->toBe($seeded['site'])
        ->and($db->table('client_memory_items')->where('id', $handLine)->value('client_id'))->toBe($seeded['acme'])
        // Nothing is lost, and nothing is left across people.
        ->and($db->table('clients')->count())->toBe($before['clients'] + 3)
        ->and($db->table('capture_sessions')->count())->toBe($before['capture_sessions'])
        ->and($db->table('call_notes')->count())->toBe($before['call_notes'])
        ->and($db->table('client_memory_items')->count())->toBe($before['client_memory_items'])
        ->and($db->table('clients as c')->join('clients as p', 'p.id', '=', 'c.parent_id')->whereColumn('c.user_id', '!=', 'p.user_id')->count())->toBe(0)
        ->and($db->table('capture_sessions as s')->join('clients as c', 'c.id', '=', 's.client_id')->whereColumn('s.user_id', '!=', 'c.user_id')->count())->toBe(0);

    // The rollback keeps the copies and what is in them.
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_PLANS]);
    expect($db->table('clients')->count())->toBe($before['clients'] + 3)
        ->and($db->table('capture_sessions')->where('id', $filed)->value('client_id'))->toBe($friendAcme->id);
    cycleArtisan('migrate');
    expect($db->table('clients')->count())->toBe($before['clients'] + 3);
})->after(function () {
    if (config('database.connections.cycle.driver') === 'sqlite') {
        DB::disconnect('cycle');
        @unlink((string) config('database.connections.cycle.database'));
    }
});

/** Migrations after the sent items' one (rolled back first): the title and preferences ones and the cheapest-first engine routes; add later ones here. */
const AFTER_SENT_ITEMS = 10;

it('fills what a project sent before this release holds, keeps every row and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    // The session_ids migration and the ones after it (AFTER_SENT_ITEMS); add later ones to the count.
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_SENT_ITEMS]);
    expect($db->getSchemaBuilder()->hasColumn('sent_items', 'session_ids'))->toBeFalse();

    $now = now();
    $plan = $db->table('plans')->value('id');
    $workspace = $db->table('workspaces')->insertGetId(['name' => 'Team', 'plan_id' => $plan, 'created_at' => $now, 'updated_at' => $now]);
    $person = fn (string $email): int => $db->table('users')->insertGetId(['name' => $email, 'email' => $email, 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    [$maker, $other, $recipient] = [$person('maker@example.test'), $person('other@example.test'), $person('to@example.test')];
    $project = function (int $owner, ?string $parent = null) use ($db, $workspace, $now): string {
        $id = (string) Str::ulid();
        $db->table('clients')->insert(['id' => $id, 'workspace_id' => $workspace, 'user_id' => $owner, 'parent_id' => $parent, 'name' => $id, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    };
    $call = function (int $owner, ?string $client, string $status = 'completed', mixed $deleted = null) use ($db, $workspace, $now): string {
        $id = (string) Str::ulid();
        $db->table('capture_sessions')->insert(['id' => $id, 'workspace_id' => $workspace, 'user_id' => $owner, 'client_id' => $client, 'client_ref' => (string) Str::uuid(),
            'source_device' => 'phone', 'source_app' => 'whatsapp', 'started_at' => $now, 'status' => $status, 'created_at' => $now, 'updated_at' => $now, 'deleted_at' => $deleted]);

        return $id;
    };
    $sent = function (string $kind, ?string $session, ?string $client) use ($db, $workspace, $maker, $recipient, $now): string {
        $id = (string) Str::ulid();
        $db->table('sent_items')->insert(['id' => $id, 'workspace_id' => $workspace, 'from_user_id' => $maker, 'to_user_id' => $recipient, 'kind' => $kind,
            'capture_session_id' => $session, 'client_id' => $client, 'title' => 'T', 'sent_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    };

    $top = $project($maker);
    $sub = $project($maker, $top);
    $below = $project($maker, $sub);
    $inTop = $call($maker, $top);
    $inSub = $call($maker, $sub);
    $inBelow = $call($maker, $below);
    $call($maker, $top, 'recording');
    $call($maker, $top, 'completed', $now);
    $call($maker, $project($maker));
    $call($other, $top);
    $single = $call($maker, null);
    $projectItem = $sent('project', null, $top);
    $empty = $sent('project', null, $project($maker));
    $sessionItem = $sent('session', $single, null);

    cycleArtisan('migrate', ['--step' => 1]);

    $held = fn (string $item): ?array => json_decode((string) $db->table('sent_items')->where('id', $item)->value('session_ids'), true);
    expect(collect($held($projectItem))->sort()->values()->all())->toBe(collect([$inTop, $inSub, $inBelow])->sort()->values()->all())
        ->and($held($empty))->toBe([])
        ->and($db->table('sent_items')->where('id', $sessionItem)->value('session_ids'))->toBeNull()
        ->and($db->table('sent_items')->count())->toBe(3);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_SENT_ITEMS]);
    expect($db->getSchemaBuilder()->hasColumn('sent_items', 'session_ids'))->toBeFalse()
        ->and($db->table('sent_items')->count())->toBe(3);
    cycleArtisan('migrate', ['--step' => 1]);
    expect(collect($held($projectItem))->sort()->values()->all())->toBe(collect([$inTop, $inSub, $inBelow])->sort()->values()->all());
});

/** Migrations after the automatic titles' one (rolled back first); add later ones here. */
const AFTER_TITLE_AUTO = 9;

it('marks automatic titles with a flag that starts false for every recording and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_TITLE_AUTO]);
    expect($db->getSchemaBuilder()->hasColumn('capture_sessions', 'title_auto'))->toBeFalse();

    $now = now();
    $plan = $db->table('plans')->value('id');
    $workspace = $db->table('workspaces')->insertGetId(['name' => 'Team', 'plan_id' => $plan, 'created_at' => $now, 'updated_at' => $now]);
    $user = $db->table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    foreach (['Budget review', null] as $title) {
        $db->table('capture_sessions')->insert(['id' => (string) Str::ulid(), 'workspace_id' => $workspace, 'user_id' => $user, 'client_ref' => (string) Str::uuid(),
            'source_device' => 'phone', 'source_app' => 'voice_note', 'title' => $title, 'started_at' => $now, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);
    }

    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->table('capture_sessions')->where('title_auto', false)->count())->toBe(2)
        ->and($db->table('capture_sessions')->whereNotNull('title')->value('title'))->toBe('Budget review');

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_TITLE_AUTO]);
    expect($db->getSchemaBuilder()->hasColumn('capture_sessions', 'title_auto'))->toBeFalse()
        ->and($db->table('capture_sessions')->count())->toBe(2);
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasColumn('capture_sessions', 'title_auto'))->toBeTrue();
});

/** Migrations after the preferences one (the cheapest-first engine routes); add later ones here. */
const AFTER_PREFERENCES = 8;

it('adds the email and Library choices empty for everyone and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_PREFERENCES]);
    $now = now();
    $db->table('users')->insert(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->table('users')->whereNull('email_preferences')->whereNull('console_preferences')->count())->toBe(1);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_PREFERENCES]);
    expect($db->getSchemaBuilder()->hasColumns('users', ['email_preferences']))->toBeFalse()
        ->and($db->table('users')->count())->toBe(1);
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasColumns('users', ['email_preferences', 'console_preferences']))->toBeTrue();
});

/** Migrations after v1.6's email codes (P1.1); add later ones here. */
const AFTER_EMAIL_CODES = 6;

it('adds the email codes and keeps every older account\'s password, and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_EMAIL_CODES]);
    expect($db->getSchemaBuilder()->hasTable('email_codes'))->toBeFalse();
    $now = now();
    $db->table('users')->insert(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->table('users')->where('has_password', true)->whereNull('terms_accepted_at')->count())->toBe(1);
    $db->table('email_codes')->insert(['email' => 'a@example.test', 'purpose' => 'sign_in', 'code_hash' => str_repeat('a', 64), 'expires_at' => $now, 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_EMAIL_CODES]);
    expect($db->getSchemaBuilder()->hasTable('email_codes'))->toBeFalse()
        ->and($db->getSchemaBuilder()->hasColumn('users', 'has_password'))->toBeFalse()
        ->and($db->table('users')->count())->toBe(1);
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasColumns('users', ['has_password', 'terms_accepted_at']))->toBeTrue();
});

/** Migrations after v1.6's entitlements (P1.2); add later ones here. */
const AFTER_ENTITLEMENTS = 5;

it('adds what plans show and limit, plan end dates and a person\'s overrides, changing no workspace, and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_ENTITLEMENTS]);
    expect($db->getSchemaBuilder()->hasColumn('plans', 'badge'))->toBeFalse();
    $now = now();
    // A plan the admin made: the later default plans' migration leaves its workspaces where they are.
    $plan = $db->table('plans')->insertGetId(['key' => 'team', 'name' => 'Team', 'created_at' => $now, 'updated_at' => $now]);
    $workspace = $db->table('workspaces')->insertGetId(['name' => 'Team', 'plan_id' => $plan, 'created_at' => $now, 'updated_at' => $now]);
    $db->table('users')->insert(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate', ['--step' => 1]);
    expect((int) $db->table('workspaces')->where('id', $workspace)->value('plan_id'))->toBe($plan)
        ->and($db->table('workspaces')->where('id', $workspace)->value('plan_expires_at'))->toBeNull()
        ->and($db->table('users')->whereNull('entitlement_overrides')->count())->toBe(1)
        // (`migrate --step` runs every later migration too: the default plans then put three plans on offer.)
        ->and($db->table('plans')->where('on_offer', true)->pluck('key')->sort()->values()->all())->toBe(['business', 'free', 'pro'])
        ->and((int) $db->table('plans')->where('id', $plan)->value('trial_minutes'))->toBe(0)
        ->and($db->table('plans')->where('id', $plan)->value('retention_from'))->toBeNull();
    $db->table('users')->update(['entitlement_overrides' => json_encode(['limits' => ['devices' => 9]])]);
    $db->table('workspaces')->update(['plan_expires_at' => $now]);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_ENTITLEMENTS]);
    expect($db->getSchemaBuilder()->hasColumn('users', 'entitlement_overrides'))->toBeFalse()
        ->and($db->getSchemaBuilder()->hasColumn('workspaces', 'plan_expires_at'))->toBeFalse()
        ->and($db->table('workspaces')->where('id', $workspace)->exists())->toBeTrue();
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasColumns('plans', ['badge', 'color', 'engines', 'retention_from']))->toBeTrue();
});

/** Migrations after v1.6's own transcription keys (P1.3); add later ones here. */
const AFTER_TRANSCRIPTION_KEYS = 4;

it('adds a person\'s own transcription key, empty for everyone, and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_TRANSCRIPTION_KEYS]);
    $now = now();
    $db->table('users')->insert(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->table('users')->whereNull('transcription_key')->whereNull('transcription_key_last4')->count())->toBe(1);
    $db->table('users')->update(['transcription_key' => 'encrypted-value', 'transcription_key_provider' => 'groq', 'transcription_key_last4' => 'ab12']);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_TRANSCRIPTION_KEYS]);
    expect($db->getSchemaBuilder()->hasColumn('users', 'transcription_key'))->toBeFalse()->and($db->table('users')->count())->toBe(1);
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasColumns('users', ['transcription_key', 'transcription_key_provider', 'transcription_key_last4', 'transcription_key_checked_at']))->toBeTrue();
});

/** Migrations after v1.6's default plans (P1.3); add later ones here. */
const AFTER_DEFAULT_PLANS = 3;

it('makes Free, Pro and Business, moves Personal and v1.5 Free workspaces to Business, frees the super admins, and puts it all back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_DEFAULT_PLANS]);
    $now = now();
    $personal = (int) $db->table('plans')->where('key', 'personal')->value('id');
    $oldFree = (int) $db->table('plans')->where('key', 'free')->value('id');
    $custom = $db->table('plans')->insertGetId(['key' => 'team', 'name' => 'Team', 'monthly_minutes' => 100, 'retention_days' => 90, 'created_at' => $now, 'updated_at' => $now]);
    $workspaces = [];
    foreach (['admin' => $personal, 'friend' => $oldFree, 'custom' => $custom] as $name => $plan) {
        $workspaces[$name] = $db->table('workspaces')->insertGetId(['name' => $name, 'plan_id' => $plan, 'created_at' => $now, 'updated_at' => $now]);
    }
    $admin = $db->table('users')->insertGetId(['name' => 'Op', 'email' => 'op@example.test', 'password' => 'x', 'is_super_admin' => true, 'created_at' => $now, 'updated_at' => $now]);
    $plain = $db->table('users')->insertGetId(['name' => 'P', 'email' => 'p@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $session = (string) Str::ulid();
    $db->table('capture_sessions')->insert(['id' => $session, 'workspace_id' => $workspaces['friend'], 'user_id' => $plain, 'client_ref' => 'r1', 'source_device' => 'phone',
        'source_app' => 'whatsapp', 'started_at' => $now->copy()->subYears(2), 'status' => 'completed', 'created_at' => $now->copy()->subYears(2), 'updated_at' => $now]);

    cycleArtisan('migrate', ['--step' => 1]);
    $plans = $db->table('plans')->whereIn('key', ['free', 'pro', 'business'])->get()->keyBy('key');
    $business = (int) $plans['business']->id;
    expect($plans->keys()->sort()->values()->all())->toBe(['business', 'free', 'pro'])
        ->and((bool) $plans['free']->is_default)->toBeTrue()
        ->and((int) $plans['free']->max_devices)->toBe(2)
        ->and((int) $plans['free']->storage_bytes)->toBe(1024 ** 3)
        ->and((int) $plans['free']->retention_days)->toBe(30)
        ->and((int) $plans['free']->trial_minutes)->toBe(0)
        ->and(json_decode((string) $plans['free']->features, true))->each->toBeFalse()
        ->and(json_decode((string) $plans['pro']->features, true))->toMatchArray(['platform_transcription' => true, 'views' => true, 'double_check' => true, 'screen_moments' => true, 'connector' => true, 'team' => false])
        ->and((int) $plans['pro']->storage_bytes)->toBe(20 * 1024 ** 3)
        ->and((int) $plans['pro']->retention_days)->toBe(365)
        ->and(json_decode((string) $plans['business']->features, true))->each->toBeTrue()
        ->and((int) $plans['business']->storage_bytes)->toBe(100 * 1024 ** 3)
        ->and($plans['business']->retention_from)->not->toBeNull()
        ->and($db->table('plans')->where('is_default', true)->count())->toBe(1)
        // Nobody loses what they have: Personal and the old Free go to Business; a plan the admin made stays.
        ->and((int) $db->table('workspaces')->where('id', $workspaces['admin'])->value('plan_id'))->toBe($business)
        ->and((int) $db->table('workspaces')->where('id', $workspaces['friend'])->value('plan_id'))->toBe($business)
        ->and((int) $db->table('workspaces')->where('id', $workspaces['custom'])->value('plan_id'))->toBe($custom)
        ->and($db->table('plans')->where('id', $custom)->value('retention_from'))->toBeNull()
        ->and(json_decode((string) $db->table('users')->where('id', $admin)->value('entitlement_overrides'), true)['limits'])->each->toBeNull()
        ->and($db->table('users')->where('id', $plain)->value('entitlement_overrides'))->toBeNull()
        // The two-year-old recording is untouched.
        ->and($db->table('capture_sessions')->where('id', $session)->whereNull('deleted_at')->exists())->toBeTrue();

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_DEFAULT_PLANS]);
    expect((int) $db->table('workspaces')->where('id', $workspaces['admin'])->value('plan_id'))->toBe($personal)
        ->and((int) $db->table('workspaces')->where('id', $workspaces['friend'])->value('plan_id'))->toBe($oldFree)
        ->and((int) $db->table('plans')->where('key', 'free')->value('max_devices'))->toBe(3)
        ->and((int) $db->table('plans')->where('key', 'free')->value('monthly_minutes'))->toBe(300)
        ->and($db->table('plans')->whereIn('key', ['pro', 'business'])->exists())->toBeFalse()
        ->and($db->table('users')->where('id', $admin)->value('entitlement_overrides'))->toBeNull()
        ->and($db->getSchemaBuilder()->hasTable('plans_before_v16'))->toBeFalse();
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->table('plans')->whereIn('key', ['free', 'pro', 'business'])->count())->toBe(3);
});

/** Migrations after v1.6's upgrade requests (P1.4); add later ones here. */
const AFTER_UPGRADE_REQUESTS = 2;

it('adds the upgrade requests, and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    $now = now();
    $user = $db->table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $workspace = $db->table('workspaces')->insertGetId(['name' => 'A', 'plan_id' => $db->table('plans')->value('id'), 'created_at' => $now, 'updated_at' => $now]);
    $db->table('upgrade_requests')->insert(['id' => (string) Str::ulid(), 'user_id' => $user, 'workspace_id' => $workspace, 'plan' => 'pro', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_UPGRADE_REQUESTS]);
    expect($db->getSchemaBuilder()->hasTable('upgrade_requests'))->toBeFalse()->and($db->table('users')->count())->toBe(1);
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasTable('upgrade_requests'))->toBeTrue();
    // A person's requests go with their account.
    $db->table('upgrade_requests')->insert(['id' => (string) Str::ulid(), 'user_id' => $user, 'workspace_id' => $workspace, 'plan' => 'pro', 'status' => 'pending', 'created_at' => $now, 'updated_at' => $now]);
    $db->table('users')->where('id', $user)->delete();
    expect($db->table('upgrade_requests')->count())->toBe(0);
});

/** Migrations after v1.6's suspend switch (P1.5); add later ones here. */
const AFTER_SUSPENSION = 1;

it('adds the suspend switch, off for everyone, and can go back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_SUSPENSION]);
    $now = now();
    $db->table('users')->insert(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->table('users')->whereNull('suspended_at')->count())->toBe(1);
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_SUSPENSION]);
    expect($db->getSchemaBuilder()->hasColumn('users', 'suspended_at'))->toBeFalse()->and($db->table('users')->count())->toBe(1);
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasColumns('users', ['suspended_at', 'suspended_reason']))->toBeTrue();
});

/** Migrations after v1.6's device identity (P3); add later ones here. */
const AFTER_DEVICE_IDENTITY = 0;

it('merges one person\'s duplicate devices into the newest, moving what they hold, and puts them back', function () {
    $db = cycleConnection();
    cycleArtisan('migrate:fresh');
    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_DEVICE_IDENTITY]);
    $now = now();
    $plan = $db->table('plans')->value('id');
    $workspace = $db->table('workspaces')->insertGetId(['name' => 'A', 'plan_id' => $plan, 'created_at' => $now, 'updated_at' => $now]);
    $user = $db->table('users')->insertGetId(['name' => 'A', 'email' => 'a@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $other = $db->table('users')->insertGetId(['name' => 'B', 'email' => 'b@example.test', 'password' => 'x', 'created_at' => $now, 'updated_at' => $now]);
    $device = function (int $owner, string $name, string $seen, string $platform = 'android') use ($db, $workspace, $now): string {
        $id = strtolower((string) Str::ulid());
        $db->table('devices')->insert(['id' => $id, 'workspace_id' => $workspace, 'user_id' => $owner, 'name' => $name, 'platform' => $platform, 'last_seen_at' => $seen, 'created_at' => $now, 'updated_at' => $now]);
        $db->table('personal_access_tokens')->insert(['tokenable_type' => 'App\\Models\\Device', 'tokenable_id' => $id, 'name' => 'device', 'token' => hash('sha256', $id), 'abilities' => '[]', 'created_at' => $now, 'updated_at' => $now]);

        return $id;
    };
    $old = $device($user, 'samsung SM-S938B', '2026-10-01 10:00:00');
    $newest = $device($user, 'samsung SM-S938B', '2026-11-01 10:00:00');
    $laptop = $device($user, 'samsung SM-S938B', '2026-09-01 10:00:00', 'windows'); // another platform: not a duplicate
    $theirs = $device($other, 'samsung SM-S938B', '2026-08-01 10:00:00');         // another person: not a duplicate
    $session = (string) Str::ulid();
    $db->table('capture_sessions')->insert(['id' => $session, 'workspace_id' => $workspace, 'user_id' => $user, 'device_id' => $old, 'client_ref' => 'r-1', 'source_device' => 'phone',
        'source_app' => 'whatsapp', 'started_at' => $now, 'status' => 'completed', 'created_at' => $now, 'updated_at' => $now]);

    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->table('devices')->where('user_id', $user)->where('platform', 'android')->pluck('id')->all())->toBe([$newest])
        ->and($db->table('devices')->whereIn('id', [$laptop, $theirs])->count())->toBe(2)
        ->and($db->table('capture_sessions')->where('id', $session)->value('device_id'))->toBe($newest)
        ->and($db->table('personal_access_tokens')->where('tokenable_id', $old)->count())->toBe(0)
        ->and($db->table('personal_access_tokens')->where('tokenable_id', $newest)->count())->toBe(1)
        ->and($db->table('device_merges')->count())->toBe(1);

    cycleArtisan('migrate:rollback', ['--step' => 1 + AFTER_DEVICE_IDENTITY]);
    expect($db->table('devices')->where('id', $old)->exists())->toBeTrue()
        ->and($db->table('capture_sessions')->where('id', $session)->value('device_id'))->toBe($old)
        ->and($db->getSchemaBuilder()->hasColumn('devices', 'install_id'))->toBeFalse()
        ->and($db->getSchemaBuilder()->hasTable('device_merges'))->toBeFalse();
    cycleArtisan('migrate', ['--step' => 1]);
    expect($db->getSchemaBuilder()->hasColumns('devices', ['install_id', 'name_chosen', 'signed_out_at', 'signed_out_by']))->toBeTrue();
});
