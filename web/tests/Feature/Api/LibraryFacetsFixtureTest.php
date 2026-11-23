<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\LabelList;
use App\Models\SessionLabel;
use App\Models\SessionOpen;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Library\LibraryFacets;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * The shared cases of the Library's filters (contract 2.0.0-p): tests/Fixtures/library-facets.json holds about forty
 * recordings and, for each filter set, the counts every app must get. The desktop and the phone read the same file; this
 * test seeds the server with it and checks each item's `facets`, the list each filter set keeps, every section's counts
 * and "Needs you".
 */

/** @return array<string, mixed> */
function facetsFixture(): array
{
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/library-facets.json'), true, 512, JSON_THROW_ON_ERROR);
}

/** Seeds the fixture's projects, lists and recordings for [$me]. */
function seedFacetsFixture(User $me, array $fixture): void
{
    $workspaceId = (int) $me->current_workspace_id;
    foreach ($fixture['projects'] as $project) {
        $client = new Client;
        $client->forceFill(['id' => $project['id'], 'workspace_id' => $workspaceId, 'user_id' => $me->id, 'name' => $project['name'],
            'color' => $project['color'], 'parent_id' => $project['parent_id']])->save();
    }
    foreach ($fixture['lists'] as $list) {
        LabelList::query()->create(['workspace_id' => $workspaceId, 'user_id' => $me->id, 'label_key' => $list['key'], 'name' => $list['name'],
            'color' => $list['color'], 'position' => $list['position']]);
    }
    foreach ($fixture['recordings'] as $r) {
        $session = CaptureSession::factory()->create([
            'id' => $r['id'], 'user_id' => $me->id, 'workspace_id' => $workspaceId, 'title' => $r['title'], 'title_auto' => false,
            'started_at' => Carbon::parse($r['started_at']), 'ended_at' => $r['ended_at'] !== null ? Carbon::parse($r['ended_at']) : null,
            'status' => $r['status'], 'source_app' => $r['source_app'], 'source_device' => $r['source_device'], 'origin' => $r['origin'],
            'client_id' => $r['client_id'], 'language' => $r['language'], 'pinned' => $r['pinned'], 'important' => $r['important'],
            'keep_forever' => $r['keep'],
        ]);
        if ($r['final_status'] !== null) {
            Transcript::query()->forceCreate(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai',
                'status' => $r['final_status'], 'language' => $r['final_language'], 'text' => $r['text'] ?? 'Hello there.',
                'attempt_started_at' => $r['transcript_attempt_at'] !== null ? Carbon::parse($r['transcript_attempt_at']) : null,
                'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => $r['text'] ?? 'Hello there.', 'words' => []]]]);
        }
        if ($r['upload_attempt_at'] !== null) {
            DB::table('recordings')->insert(['id' => (string) Str::ulid(), 'capture_session_id' => $session->id, 'role' => 'primary', 'mime' => 'audio/ogg',
                'declared_size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'chunk_size_bytes' => 10, 'total_chunks' => 1, 'received_chunks' => '[]',
                'upload_status' => $r['status'] === 'uploading' ? 'uploading' : 'ready', 'upload_started_at' => Carbon::parse($r['upload_attempt_at']),
                'created_at' => now(), 'updated_at' => now()]);
        }
        if ($r['upload_failed']) {
            DB::table('recordings')->insert(['id' => (string) Str::ulid(), 'capture_session_id' => $session->id, 'role' => 'primary', 'mime' => 'audio/ogg',
                'declared_size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'chunk_size_bytes' => 10, 'total_chunks' => 1, 'received_chunks' => '[]',
                'upload_status' => 'failed', 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ($r['labels'] as $label) {
            SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => $label]);
        }
        foreach ($r['bookmarks'] as $i => $tag) {
            $session->markers()->create(['at_ms' => 1000 * ($i + 1), 'tag' => $tag]);
        }
        if ($r['opened']) {
            SessionOpen::query()->create(['capture_session_id' => $session->id, 'user_id' => $me->id, 'opened_at' => now()]);
        }
        if ($r['deleted']) {
            $session->delete();
        }
    }
}

/** A section's options as the fixture writes them: value => count, in the panel's order. */
function countsOf(array $options): array
{
    return array_combine(array_column($options, 'value'), array_column($options, 'count'));
}

beforeEach(function () {
    $this->fixture = facetsFixture();
    Carbon::setTestNow(Carbon::parse($this->fixture['now']));
    $this->me = Fx::user();
    $this->token = Fx::token(Fx::device($this->me));
    seedFacetsFixture($this->me, $this->fixture);
});

it('gives every library item the facets the fixture says', function () {
    $items = collect([
        ...$this->api($this->token)->getJson('/api/library?limit=100')->assertOk()->json('data'),
        ...$this->api($this->token)->getJson('/api/library?view=waiting&limit=100')->assertOk()->json('data'),
    ])->keyBy('id');
    foreach ($this->fixture['recordings'] as $r) {
        if ($r['deleted']) {
            expect($items->has($r['id']))->toBeFalse("{$r['id']} is in Trash");

            continue;
        }
        expect($items[$r['id']]['facets'] ?? null)->toBe($r['facets'], "{$r['id']}: {$r['note']}")
            // What the apps count Needs you's "stuck" from.
            ->and($items[$r['id']]['processing_since'])->toBe($r['processing_since'], "{$r['id']}: processing_since");
        // What the apps count Bookmarks from.
        expect($items[$r['id']]['marker_count'])->toBe(count($r['bookmarks']))
            ->and($items[$r['id']]['tags'])->toBe(array_values(array_unique(array_filter($r['bookmarks'], 'is_string'))));
    }
});

it('keeps the same recordings and counts every section the same for each filter set', function () {
    foreach ($this->fixture['filter_sets'] as $set) {
        $query = ['filters' => json_encode((object) $set['filters']), 'tz' => $this->fixture['tz']] + ($set['search'] !== null ? ['q' => $set['search']] : []);
        $answer = $this->api($this->token)->getJson('/api/library/facets?'.http_build_query($query))->assertOk()->json();

        expect($answer['total'])->toBe($set['expect']['total'], "{$set['name']}: total");
        foreach ($set['expect']['facets'] as $section => $counts) {
            expect(countsOf($answer['sections'][$section]))->toBe($counts, "{$set['name']}: {$section}");
        }
        $listed = $this->api($this->token)->getJson('/api/library?limit=100&'.http_build_query($query))->assertOk()->json('data');
        expect(collect($listed)->pluck('id')->sort()->values()->all())->toBe(collect($set['expect']['ids'])->sort()->values()->all(), "{$set['name']}: the list");
    }
});

it('finds what needs the person, waiting calls included, and counts the sidebar the same', function () {
    $needs = $this->fixture['needs_you'];
    $answer = $this->api($this->token)->getJson('/api/library/facets?tz='.$this->fixture['tz'])->assertOk()->json('needs_you');
    expect($answer)->toBe(['count' => $needs['count'], 'failed' => $needs['failed'], 'waiting' => $needs['waiting'], 'stuck' => $needs['stuck']]);

    $listed = $this->api($this->token)->getJson('/api/library?view=needs_you&limit=100')->assertOk()->json('data');
    expect(collect($listed)->pluck('id')->sort()->values()->all())->toBe(collect(array_keys($needs['ids']))->sort()->values()->all());

    // The sidebar's Library views, whatever the filters (here with one on, to show they stay the whole library's).
    $library = $this->api($this->token)->getJson('/api/library/facets?'.http_build_query(['filters' => '{"device": {"op": "is", "values": ["web"]}}']))->assertOk()->json('library');
    expect($library)->toBe($this->fixture['library_counts'])
        ->and(app(LibraryFacets::class)->libraryCounts((int) $this->me->current_workspace_id, $this->me->id))->toBe($this->fixture['library_counts']);
});

it('names each project and list option with what the apps show', function () {
    $sections = $this->api($this->token)->getJson('/api/library/facets?tz='.$this->fixture['tz'])->assertOk()->json('sections');
    $projects = collect($this->fixture['projects'])->keyBy('id');
    foreach ($sections['project'] as $option) {
        if ($option['value'] === 'none') {
            // No project: as many as the fixture counts with no filter on.
            expect($option)->toBe(['value' => 'none', 'count' => collect($this->fixture['filter_sets'])->firstWhere('name', 'everything')['expect']['facets']['project']['none']]);

            continue;
        }
        expect($option['name'])->toBe($projects[$option['value']]['name'])->and($option['parent_id'])->toBe($projects[$option['value']]['parent_id']);
    }
    $lists = collect($this->fixture['lists'])->keyBy('key');
    expect(collect($sections['labels'])->map(fn (array $o): array => [$o['value'], $o['name'], $o['color']])->all())->toBe([
        ['clients', 'Clients', $lists['clients']['color']],
        ['invoice', 'Invoice', $lists['invoice']['color']],
        ['follow up', 'Follow up', null],
        ['someday', 'Someday', $lists['someday']['color']],
        ['important', 'Important', null],
        ['older label', 'Older label', null],
    ]);
});
