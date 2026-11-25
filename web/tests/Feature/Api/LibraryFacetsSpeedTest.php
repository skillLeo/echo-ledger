<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Models\Client;
use App\Services\Library\LibraryFacets;
use App\Services\Library\LibraryFilters;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * Contract 2.0.0-p: GET /api/library/facets answers within 150 ms for a library of 2,000+ recordings, and a filter set
 * asked again is served from the cache. The rows are written straight in (the factories would take far longer). The
 * times are processor time (W-219), the same as the clock's on a quiet computer.
 */

const SPEED_RECORDINGS = 2100;

/**
 * The median processor time of [$times] runs of [$run], in milliseconds (W-219). On a quiet computer it is the time a
 * run takes: the database is SQLite inside this process, so nothing is waited for. On a busy one the clock also counts
 * other programs' turns: the fastest of three runs took 214-534 ms by the clock under load, and 30-52 ms of processor
 * time. A slower query or loop still costs more processor time, so the budgets below are unchanged.
 */
function medianCpuMs(int $times, Closure $run): float
{
    $cpu = static function (): float {
        $used = getrusage();

        return ($used['ru_utime.tv_sec'] + $used['ru_stime.tv_sec']) * 1e3 + ($used['ru_utime.tv_usec'] + $used['ru_stime.tv_usec']) / 1e3;
    };
    $runs = [];
    for ($i = 0; $i < $times; $i++) {
        $start = $cpu();
        $run();
        $runs[] = $cpu() - $start;
    }
    sort($runs);

    return $runs[intdiv($times, 2)];
}

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    $this->me = Fx::user();
    $this->token = Fx::token(Fx::device($this->me));
    $workspaceId = (int) $this->me->current_workspace_id;
    $projects = [];
    foreach (range(1, 12) as $i) {
        $projects[] = Client::query()->create(['user_id' => $this->me->id, 'workspace_id' => $workspaceId, 'name' => "Project {$i}", 'color' => '#26978f'])->id;
    }
    $apps = ['whatsapp', 'zoom', 'google_meet', 'microsoft_teams', 'phone_call', 'voice_note'];
    $sessions = $transcripts = $labels = [];
    foreach (range(1, SPEED_RECORDINGS) as $i) {
        $id = (string) Str::ulid();
        $started = now()->subHours($i * 3);
        $sessions[] = ['id' => $id, 'workspace_id' => $workspaceId, 'user_id' => $this->me->id, 'client_ref' => (string) Str::uuid(),
            'source_device' => ['phone', 'desktop', 'web'][$i % 3], 'source_app' => $apps[$i % count($apps)], 'title' => "Call {$i}",
            'started_at' => $started, 'ended_at' => $started->copy()->addMinutes(10), 'duration_ms' => 600000, 'status' => $i % 17 === 0 ? 'audio_only' : 'completed',
            'client_id' => $i % 4 === 0 ? null : $projects[$i % count($projects)], 'pinned' => $i % 25 === 0, 'important' => $i % 9 === 0,
            'keep_forever' => false, 'transcribe' => true, 'title_auto' => false, 'created_at' => $started, 'updated_at' => $started];
        if ($i % 17 !== 0) {
            $transcripts[] = ['id' => (string) Str::ulid(), 'capture_session_id' => $id, 'kind' => TranscriptKind::Final->value, 'provider' => 'groq',
                'status' => $i % 31 === 0 ? 'failed' : 'completed', 'language' => $i % 2 === 0 ? 'ur' : 'en', 'text' => "Words of call {$i}",
                'search_text' => "words of call {$i}", 'segments' => '[]', 'created_at' => $started, 'updated_at' => $started];
        }
        foreach (array_slice(['Clients', 'Invoice', 'Follow up'], 0, $i % 3) as $label) {
            $labels[] = ['capture_session_id' => $id, 'label' => $label];
        }
    }
    foreach ([['capture_sessions', $sessions], ['transcripts', $transcripts], ['session_labels', $labels]] as [$table, $rows]) {
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }
});

it('counts 2,000+ recordings within 150 ms, and serves a filter set asked again from the cache', function () {
    $filters = ['source' => ['op' => 'is', 'values' => ['whatsapp', 'zoom']], 'labels' => ['op' => 'is_not', 'values' => ['invoice']], 'date' => ['op' => 'is', 'values' => ['this_month']]];
    $query = '/api/library/facets?'.http_build_query(['filters' => json_encode($filters), 'tz' => 'Asia/Karachi']);
    $this->api($this->token)->getJson($query)->assertOk();
    $facets = app(LibraryFacets::class);
    $workspaceId = (int) $this->me->current_workspace_id;

    // Nothing cached: the rows read from the database and every section counted.
    $cold = medianCpuMs(5, function () use ($facets, $workspaceId, $filters): void {
        Cache::flush();
        LibraryFacets::forget($workspaceId, $this->me->id);
        $facets->facets($workspaceId, $this->me->id, LibraryFilters::normalize($filters), null, 'Asia/Karachi');
    });
    // The whole request, served from the cache.
    $warm = medianCpuMs(5, fn () => $this->api($this->token)->getJson($query)->assertOk());
    // Another filter set on the cached rows (each click in the filter panel).
    $sets = [['pinned'], ['important'], ['keep_forever'], ['pinned', 'important'], ['important', 'keep_forever']];
    $other = medianCpuMs(5, function () use ($facets, $workspaceId, &$sets): void {
        $facets->facets($workspaceId, $this->me->id, LibraryFilters::normalize(['status' => ['op' => 'is', 'values' => ['failed']], 'marked' => ['op' => 'is_not', 'values' => array_shift($sets)]]), null, 'Asia/Karachi');
    });

    expect($this->api($this->token)->getJson($query)->json('sections.status'))->toHaveCount(4)
        ->and($cold)->toBeLessThan(150.0, "cold {$cold} ms")
        ->and($warm)->toBeLessThan(150.0, "warm {$warm} ms")
        ->and($other)->toBeLessThan(150.0, "another filter set {$other} ms");
    fwrite(STDERR, sprintf("facets for %d recordings: cold %.1f ms, cached request %.1f ms, another filter set %.1f ms\n", SPEED_RECORDINGS, $cold, $warm, $other));
});

it('narrows the list of 2,000+ recordings by the filters within the same budget', function () {
    $filters = json_encode(['source' => ['op' => 'is_not', 'values' => ['voice_note']], 'device' => ['op' => 'is', 'values' => ['phone', 'desktop']]]);
    $this->api($this->token)->getJson('/api/library?limit=50&filters='.urlencode($filters))->assertOk();

    $ms = medianCpuMs(5, fn () => $this->api($this->token)->getJson('/api/library?limit=50&filters='.urlencode($filters))->assertOk()->assertJsonCount(50, 'data'));
    expect($ms)->toBeLessThan(400.0, "library list {$ms} ms");
});
