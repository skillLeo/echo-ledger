<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Device;
use App\Models\User;
use Database\Seeders\LibraryBenchSeeder;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Measures the Library API on the bench library (plan B6, LibraryBenchSeeder): each case is asked --runs times as the
 * bench person's phone, in this process through the HTTP kernel (the server's own time, no network), and printed with
 * its p50, p95 and slowest time and the queries one answer costs. Local or testing databases only.
 */
final class LibraryBench extends Command
{
    protected $signature = 'echoledger:library-bench {--runs=30 : answers measured per case} {--only= : run only the cases whose name holds this} {--plans : print what each query of a case reads}';

    protected $description = 'Measure the Library API on the bench library (local only)';

    public function handle(Kernel $kernel): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('The library bench runs only on a local or testing database.');

            return self::FAILURE;
        }
        $user = User::query()->where('email', LibraryBenchSeeder::EMAIL)->first();
        $device = $user !== null ? Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->where('platform', 'android')->first() : null;
        if ($user === null || $device === null) {
            $this->error('No bench library: php artisan db:seed --class=LibraryBenchSeeder first.');

            return self::FAILURE;
        }
        $token = $device->createToken('bench', Device::ABILITIES, now()->addHour())->plainTextToken;
        $project = Client::query()->withoutGlobalScopes()->where('user_id', $user->id)->orderBy('name')->value('id');
        $runs = max(1, (int) $this->option('runs'));
        $count = DB::table('capture_sessions')->where('user_id', $user->id)->count();
        $this->line(sprintf('Bench person: %d recordings; table: %d; %s %s; %d runs a case.', $count, DB::table('capture_sessions')->count(),
            DB::connection()->getDriverName(), DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION), $runs));

        $deep = function () use ($kernel, $token): string {
            // The 20th page of 50: the cursor of page 19, found once.
            $cursor = null;
            for ($page = 1; $page < 20; $page++) {
                $cursor = $this->fetch($kernel, $token, '/api/library?limit=50'.($cursor !== null ? '&cursor='.urlencode($cursor) : ''))['json']['next_cursor'] ?? null;
            }

            return '/api/library?limit=50&cursor='.urlencode((string) $cursor);
        };
        $cases = [
            'first page (50)' => '/api/library',
            'first page (100)' => '/api/library?limit=100',
            'page 20 (cursor)' => $deep,
            'sync since a day' => '/api/library?since='.urlencode(now()->subDay()->toAtomString()),
            'view today' => '/api/library?view=today',
            'view pinned' => '/api/library?view=pinned',
            'view failed' => '/api/library?view=failed',
            'bookmarked' => '/api/library?bookmarked=1',
            'label Invoice' => '/api/library?label=Invoice',
            'important=1' => '/api/library?important=1',
            'folder inbox' => '/api/library?folder=inbox',
            'folder zoom' => '/api/library?folder=zoom',
            'one project' => '/api/library?client_id='.$project,
            'views and counts' => '/api/library/views',
        ];
        $only = (string) $this->option('only');
        $rows = [];
        foreach ($cases as $name => $uri) {
            if ($only !== '' && ! str_contains($name, $only)) {
                continue;
            }
            $uri = is_callable($uri) ? $uri() : $uri;
            $work = $this->work($this->fetch($kernel, $token, $uri)['log']); // also warms the caches
            $times = $db = [];
            $queries = 0;
            $items = 0;
            $peak = 0.0;
            for ($i = 0; $i < $runs; $i++) {
                $answer = $this->fetch($kernel, $token, $uri);
                $times[] = $answer['ms'];
                $db[] = $answer['db_ms'];
                $peak = $answer['peak_mb'];
                $queries = $answer['queries'];
                $items = count($answer['json']['data'] ?? $answer['json']['views'] ?? []);
            }
            sort($times);
            sort($db);
            $rows[] = [$name, $items, $queries, $work['rows'] ?? '-', round($peak, 1), self::pct($db, 50), self::pct($times, 50), self::pct($times, 95)];
        }
        if ($only === '' || str_contains('full first sync', $only)) {
            $rows[] = $this->fullSync($kernel, $token, min($runs, 5));
        }
        $this->table(['case', 'items', 'queries', 'rows read', 'peak MB', 'db p50 ms', 'p50 ms', 'p95 ms'], $rows);
        $device->tokens()->where('name', 'bench')->delete();

        return self::SUCCESS;
    }

    /**
     * A new device's first sync: every page of 100 from the start, timed as a whole.
     *
     * @return list<int|float|string>
     */
    private function fullSync(Kernel $kernel, string $token, int $runs): array
    {
        $times = $db = [];
        $pages = 0;
        $queries = 0;
        $rows = 0;
        $peak = 0.0;
        for ($i = 0; $i < $runs; $i++) {
            $cursor = null;
            $pages = 0;
            $queries = 0;
            $spent = $dbSpent = 0.0;
            do {
                $answer = $this->fetch($kernel, $token, '/api/library?limit=100&since=1970-01-01T00:00:00Z'.($cursor !== null ? '&cursor='.urlencode($cursor) : ''));
                if ($i === 0) {
                    $work = $this->work($answer['log']);
                    $rows += $work['rows'] ?? 0;
                }
                $cursor = $answer['json']['next_cursor'] ?? null;
                $pages++;
                $queries += $answer['queries'];
                $spent += $answer['ms'];
                $dbSpent += $answer['db_ms'];
                $peak = max($peak, $answer['peak_mb']);
            } while ($cursor !== null && $pages < 200);
            $times[] = $spent;
            $db[] = $dbSpent;
        }
        sort($times);
        sort($db);

        return ["full first sync ({$pages} pages)", '-', $queries, $rows ?: '-', round($peak, 1), self::pct($db, 50), self::pct($times, 50), self::pct($times, 95)];
    }

    /** @return array{ms: float, db_ms: float, peak_mb: float, queries: int, log: list<array{query: string, bindings: array<int, mixed>, time: float|null}>, json: array<string, mixed>} */
    private function fetch(Kernel $kernel, string $token, string $uri): array
    {
        app('auth')->forgetGuards();
        // The bench asks far faster than a person: start every answer with fresh rate limits (the bench's cache only).
        app('cache')->store()->flush();
        $request = Request::create($uri, 'GET', server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        gc_collect_cycles();
        memory_reset_peak_usage();
        $memory = memory_get_usage();
        $started = hrtime(true);
        $response = $kernel->handle($request);
        $ms = (hrtime(true) - $started) / 1e6;
        $peakMb = (memory_get_peak_usage() - $memory) / 1048576;
        $log = DB::getQueryLog();
        $queries = count($log);
        $dbMs = array_sum(array_map(fn (array $q): float => (float) $q['time'], $log));
        DB::disableQueryLog();
        $kernel->terminate($request, $response);
        if ($response->getStatusCode() !== 200) {
            $this->warn("{$uri} answered {$response->getStatusCode()}");
        }
        $json = json_decode((string) $response->getContent(), true);

        return ['ms' => $ms, 'db_ms' => $dbMs, 'peak_mb' => $peakMb, 'queries' => $queries, 'log' => $log, 'json' => is_array($json) ? $json : []];
    }

    /**
     * What the answer's queries really read, replayed through MariaDB's ANALYZE FORMAT=JSON: every table access's
     * loops times rows, summed, and whether any query sorted rows itself (a filesort). Independent of the machine's
     * load. Null on another database.
     *
     * @param  list<array{query: string, bindings: array<int, mixed>, time: float|null}>  $log
     * @return array{rows: int, filesort: bool}|null
     */
    private function work(array $log): ?array
    {
        $driver = DB::connection()->getDriverName();
        if (! in_array($driver, ['mariadb', 'mysql'], true)) {
            return null;
        }
        $rows = 0;
        $filesort = false;
        foreach ($log as $entry) {
            if (! str_starts_with(ltrim($entry['query']), 'select') || str_contains($entry['query'], 'personal_access_tokens')) {
                continue;
            }
            if ($driver === 'mysql') {
                // MySQL's EXPLAIN ANALYZE tree: the rows each scan or lookup really read, times its loops.
                $tree = (string) (array_values((array) DB::selectOne('EXPLAIN ANALYZE '.$entry['query'], $entry['bindings']))[0] ?? '');
                $before = $rows;
                preg_match_all('/-> (?:Table scan|Index scan|Index range scan|Index lookup|Covering index[^(]*|Single-row index lookup|Index full scan|Filter)[^\n]*?\(actual time=[^)]*rows=([0-9.e+]+) loops=([0-9]+)\)/', $tree, $m, PREG_SET_ORDER);
                foreach ($m as $node) {
                    if (! str_starts_with($node[0], '-> Filter')) {
                        $rows += (int) round((float) $node[1] * (int) $node[2]);
                    }
                }
                $filesort = $filesort || str_contains($tree, 'Sort');
                if ($this->option('plans')) {
                    $this->line(sprintf('  %6d rows  %s', $rows - $before, mb_substr($entry['query'], 0, 150)));
                    if ($rows - $before > 1000) {
                        $this->line($tree);
                    }
                }

                continue;
            }
            $plan = DB::selectOne('ANALYZE FORMAT=JSON '.$entry['query'], $entry['bindings']);
            $json = (string) (array_values((array) $plan)[0] ?? '');
            $filesort = $filesort || str_contains($json, 'filesort');
            $before = $rows;
            $walk = function (mixed $node) use (&$walk, &$rows): void {
                if (! is_array($node)) {
                    return;
                }
                if (isset($node['table_name'], $node['r_rows'])) {
                    $rows += (int) round((float) $node['r_rows'] * (float) ($node['r_loops'] ?? 1));
                }
                foreach ($node as $child) {
                    $walk($child);
                }
            };
            $walk(json_decode($json, true));
            if ($this->option('plans')) {
                $this->line(sprintf('  %6d rows%s  %s', $rows - $before, str_contains($json, 'filesort') ? ' sort' : '     ', mb_substr($entry['query'], 0, 150)));
            }
        }

        return ['rows' => $rows, 'filesort' => $filesort];
    }

    /** @param list<float> $sorted */
    private static function pct(array $sorted, int $pct): float
    {
        return round($sorted[max(0, (int) ceil($pct / 100 * count($sorted)) - 1)], 1);
    }
}
