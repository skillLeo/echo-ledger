<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\AccountExport;
use App\Models\CaptureSession;
use App\Models\LabelList;
use App\Models\Recording;
use App\Models\SessionLabel;
use App\Models\Transcript;
use App\Models\User;
use App\Services\Accounts\AccountExporter;
use App\Services\Library\LibraryFacets;
use App\Services\Library\SavedViews;
use App\Services\Lists\LabelLists;
use App\Services\Storage\RecordingFiles;
use Illuminate\Support\Carbon;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * The review of contract 2.0.0-p (LF1): the counts' cache is renewed once a request and only after its transactions,
 * lists renew it too, Needs you keeps its waiting calls with filters or a search, the shared folder refuses the person's
 * own places, a saved view kept before an option was withdrawn still reads, and the account export carries lists and
 * saved views.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    $this->me = Fx::user();
    $this->token = Fx::token(Fx::device($this->me));
    $this->ws = (int) $this->me->current_workspace_id;
    $this->stamp = fn (): mixed => Cache::get('library-facets:stamp:'.$this->ws.':'.$this->me->id);
    $this->endOfRequest = fn () => app(DeferredCallbackCollection::class)->invoke();
});

function reviewed(User $owner, array $state = [], ?string $final = 'completed', array $labels = []): CaptureSession
{
    $session = CaptureSession::factory()->create(['user_id' => $owner->id, ...$state]);
    if ($final !== null) {
        Transcript::query()->forceCreate(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai',
            'status' => $final, 'language' => 'ur', 'text' => 'Invoice talk', 'segments' => []]);
    }
    foreach ($labels as $label) {
        SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => $label]);
    }

    return $session;
}

describe('the cache', function () {
    it('renews the person\'s stamp once a request, after its transactions, and reads afresh until then', function () {
        $session = reviewed($this->me);
        ($this->endOfRequest)();
        $before = ($this->stamp)();
        expect($before)->not->toBeNull()->and(LibraryFacets::pending($this->ws, $this->me->id))->toBe(0);

        DB::transaction(function () use ($session, $before): void {
            SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => 'One']);
            SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => 'Two']);
            // Nothing renewed while the change is not committed: a reader elsewhere keeps nothing under a new stamp.
            expect(($this->stamp)())->toBe($before);
        });
        // Still nothing after the commit: once, after the request, however many rows changed.
        expect(($this->stamp)())->toBe($before)
            ->and(LibraryFacets::pending($this->ws, $this->me->id))->toBe(2)
            ->and(count(app(DeferredCallbackCollection::class)))->toBe(1);
        // Meanwhile this request reads the change.
        expect(app(LibraryFacets::class)->facets($this->ws, $this->me->id, ['labels' => ['op' => 'is', 'values' => ['two']]])['total'])->toBe(1);

        ($this->endOfRequest)();
        expect(($this->stamp)())->not->toBe($before)->and(LibraryFacets::pending($this->ws, $this->me->id))->toBe(0);
    });

    it('never keeps rows read while a change is not renewed, and serves the change at once to the next request', function () {
        $session = reviewed($this->me);
        ($this->endOfRequest)();
        $facets = app(LibraryFacets::class);
        expect($facets->facets($this->ws, $this->me->id, [])['total'])->toBe(1);

        // Another request's change, read here in the same second (same counts, same newest change) before its renewal.
        SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => 'Late']);
        DB::table('capture_sessions')->where('id', $session->id)->update(['updated_at' => $session->updated_at]);
        expect(app(LibraryFacets::class)->facets($this->ws, $this->me->id, ['labels' => ['op' => 'is', 'values' => ['late']]])['total'])->toBe(1);
        ($this->endOfRequest)();
        expect(app(LibraryFacets::class)->facets($this->ws, $this->me->id, ['labels' => ['op' => 'is', 'values' => ['late']]])['total'])->toBe(1);
    });

    it('renews on a list\'s colour, order or a new empty one, and an upload only when its state changes', function () {
        reviewed($this->me);
        ($this->endOfRequest)();

        app(LabelLists::class)->create($this->ws, $this->me->id, 'Empty list', '#e15f56');
        expect(LibraryFacets::pending($this->ws, $this->me->id))->toBeGreaterThan(0);
        ($this->endOfRequest)();
        expect(collect(app(LibraryFacets::class)->facets($this->ws, $this->me->id, [])['sections']['labels'])->pluck('value')->all())->toContain('empty list');

        LabelList::query()->where('user_id', $this->me->id)->update(['color' => null]);
        expect(LibraryFacets::pending($this->ws, $this->me->id))->toBe(0);
        LabelList::query()->where('user_id', $this->me->id)->firstOrFail()->update(['color' => '#2d9a63']);
        expect(LibraryFacets::pending($this->ws, $this->me->id))->toBe(1);
        ($this->endOfRequest)();

        $recording = Recording::query()->forceCreate(['id' => (string) Str::ulid(), 'capture_session_id' => CaptureSession::query()->where('user_id', $this->me->id)->value('id'),
            'role' => 'primary', 'mime' => 'audio/ogg', 'declared_size_bytes' => 10, 'sha256' => str_repeat('0', 64), 'chunk_size_bytes' => 10,
            'total_chunks' => 2, 'received_chunks' => [], 'upload_status' => 'uploading']);
        ($this->endOfRequest)();
        // A part received changes nothing a filter reads.
        $recording->update(['received_chunks' => [0]]);
        expect(LibraryFacets::pending($this->ws, $this->me->id))->toBe(0);
        $recording->update(['upload_status' => 'failed']);
        expect(LibraryFacets::pending($this->ws, $this->me->id))->toBe(1);
    });
});

it('renews on a label\'s colour set through PUT /api/labels/color, written without model events', function () {
    reviewed($this->me, [], 'completed', ['Clients']);
    // The list is there already, so setting its colour writes nothing but the colour.
    LabelList::query()->create(['workspace_id' => $this->ws, 'user_id' => $this->me->id, 'label_key' => 'clients', 'name' => 'Clients', 'color' => null, 'position' => 0]);
    ($this->endOfRequest)();
    $colour = fn (): mixed => collect(app(LibraryFacets::class)->facets($this->ws, $this->me->id, [])['sections']['labels'])->firstWhere('value', 'clients')['color'] ?? null;
    expect($colour())->toBeNull();
    $before = ($this->stamp)();

    $this->api($this->token)->putJson('/api/labels/color', ['label' => 'Clients', 'color' => '#2D9A63'])->assertOk();
    // The request is over: the stamp is new, and the next reader sees the colour at once.
    expect(($this->stamp)())->not->toBe($before)->and($colour())->toBe('#2d9a63');
});

describe('Needs you and the shared folder', function () {
    it('keeps the waiting calls of Needs you with filters or a search', function () {
        $waiting = CaptureSession::factory()->open()->create(['user_id' => $this->me->id, 'source_app' => 'zoom', 'title' => 'Invoice call']);
        $failed = reviewed($this->me, ['source_app' => 'zoom', 'title' => 'Invoice failed'], TranscriptStatus::Failed->value);
        reviewed($this->me, ['source_app' => 'whatsapp', 'status' => SessionStatus::Failed, 'title' => 'Other'], null);

        $ids = fn (array $query): array => collect($this->api($this->token)->getJson('/api/library?'.http_build_query(['view' => 'needs_you', ...$query]))->assertOk()->json('data.*.id'))->sort()->values()->all();
        $both = collect([$waiting->id, $failed->id])->sort()->values()->all();
        expect($ids(['filters' => json_encode(['source' => ['op' => 'is', 'values' => ['zoom']]])]))->toBe($both)
            ->and($ids(['q' => 'invoice']))->toBe($both);
    });

    it('refuses Needs you, Unread, the filters and the search in the shared folder, naming the field', function () {
        foreach ([['view' => 'needs_you'], ['view' => 'unread'], ['filters' => '{"status": {"op": "is", "values": ["failed"]}}'], ['q' => 'invoice']] as $query) {
            $field = array_key_first($query);
            $this->api($this->token)->getJson('/api/library?'.http_build_query(['folder' => 'shared', ...$query]))->assertStatus(422)
                ->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors([$field]);
        }
        $this->api($this->token)->getJson('/api/library?folder=shared')->assertOk();
    });
});

describe('saved views', function () {
    it('reads a view kept before an option or a section was withdrawn, without it, and still refuses one sent', function () {
        $id = (string) Str::ulid();
        DB::table('saved_views')->insert(['id' => $id, 'workspace_id' => $this->ws, 'user_id' => $this->me->id, 'name' => 'Old view',
            'filters' => json_encode(['source' => ['op' => 'is', 'values' => ['fax', 'whatsapp']], 'colour' => ['op' => 'is', 'values' => ['red']],
                'device' => ['op' => 'is', 'values' => ['pager']], 'status' => ['op' => 'maybe', 'values' => ['failed']]]),
            'search' => null, 'sort' => 'newest', 'group_by' => 'date', 'density' => 'comfortable', 'position' => 0, 'created_at' => now(), 'updated_at' => now()]);

        expect($this->api($this->token)->getJson('/api/views')->assertOk()->json('data.0.filters'))->toBe(['source' => ['op' => 'is', 'values' => ['whatsapp']]]);
        // Renaming it keeps it as stored; sending the withdrawn option again is refused.
        $this->api($this->token)->patchJson("/api/views/{$id}", ['name' => 'Renamed'])->assertOk()->assertJsonPath('data.filters', ['source' => ['op' => 'is', 'values' => ['whatsapp']]]);
        $this->api($this->token)->patchJson("/api/views/{$id}", ['filters' => ['source' => ['op' => 'is', 'values' => ['fax']]]])->assertStatus(422);
    });
});

it('puts the person\'s lists and saved views of every workspace in the account export', function () {
    Mail::fake();
    Fx::fakeRecordingsDisk();
    app(LabelLists::class)->create($this->ws, $this->me->id, 'Clients', '#e15f56');
    app(SavedViews::class)->create($this->ws, $this->me->id, ['name' => 'WhatsApp calls', 'filters' => ['source' => ['op' => 'is', 'values' => ['whatsapp']]], 'search' => 'invoice']);
    $stranger = Fx::user();
    app(SavedViews::class)->create((int) $stranger->current_workspace_id, $stranger->id, ['name' => 'NOT-MINE']);
    $export = AccountExport::query()->create(['user_id' => $this->me->id, 'status' => 'making']);

    app(AccountExporter::class)->build($export);
    $zip = new ZipArchive;
    $path = (string) tempnam(sys_get_temp_dir(), 'zip');
    app(RecordingFiles::class)->decryptToFile((string) $export->fresh()->path, $path, $export->id);
    expect($zip->open($path))->toBeTrue();
    $library = json_decode((string) $zip->getFromName('library.json'), true);
    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);

    expect($manifest['library'])->toBe('library.json')
        ->and($library['workspaces'])->toHaveCount(1)
        ->and($library['workspaces'][0]['lists'])->toBe([['name' => 'Clients', 'color' => '#e15f56', 'position' => 0]])
        ->and($library['workspaces'][0]['views'][0])->toMatchArray(['name' => 'WhatsApp calls', 'search' => 'invoice', 'filters' => ['source' => ['op' => 'is', 'values' => ['whatsapp']]]])
        ->and((string) $zip->getFromName('library.json'))->not->toContain('NOT-MINE');
    $zip->close();
    @unlink($path);
});
