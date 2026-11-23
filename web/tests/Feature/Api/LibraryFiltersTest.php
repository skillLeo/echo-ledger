<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\WorkspaceRole;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\SessionLabel;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Carbon;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

/*
 * docs/API.md "Library filters (since 2.0.0-p)": every section with "is" and "is not" on GET /api/library and
 * GET /api/library/facets, the facet rule (a section's counts leave its own filter out), Needs you, and that nothing of a
 * teammate or another workspace is ever counted. The shared cases are in LibraryFacetsFixtureTest.
 */

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-11-23 10:00:00', 'UTC'));
    $this->me = Fx::user();
    $this->token = Fx::token(Fx::device($this->me));
    $this->workspace = Workspace::query()->findOrFail($this->me->current_workspace_id);
});

/** A recording of [$owner] with a finished transcript in [$language] unless the state says otherwise. */
function filtered(User $owner, array $state = [], array $labels = [], ?string $final = 'completed', ?string $language = 'ur'): CaptureSession
{
    $session = CaptureSession::factory()->create(['user_id' => $owner->id, ...$state]);
    if ($final !== null) {
        Transcript::query()->forceCreate(['capture_session_id' => $session->id, 'kind' => TranscriptKind::Final, 'provider' => 'assemblyai',
            'status' => $final, 'language' => $language, 'text' => 'Hello there.', 'segments' => []]);
    }
    foreach ($labels as $label) {
        SessionLabel::query()->create(['capture_session_id' => $session->id, 'label' => $label]);
    }

    return $session;
}

/** A request as my device (a fresh guard each time). */
function asMyDevice(object $test): object
{
    app('auth')->forgetGuards();

    return $test->flushHeaders()->withHeaders(['Accept' => 'application/json'])->withToken($test->token);
}

/** The ids GET /api/library lists for these filters, sorted. */
function listedFor(object $test, array $filters, array $more = []): array
{
    $ids = asMyDevice($test)->getJson('/api/library?limit=100&'.http_build_query(['filters' => json_encode((object) $filters), ...$more]))->assertOk()->json('data.*.id');

    return collect($ids)->sort()->values()->all();
}

/** @return array<string, mixed> GET /api/library/facets */
function facetsFor(object $test, array $filters = [], array $more = []): array
{
    return asMyDevice($test)->getJson('/api/library/facets?'.http_build_query(['filters' => json_encode((object) $filters), ...$more]))->assertOk()->json();
}

function idsOf(CaptureSession ...$sessions): array
{
    return collect($sessions)->map(fn (CaptureSession $s): string => $s->id)->sort()->values()->all();
}

describe('each section, is and is not', function () {
    it('filters by date: today, the last 7 days, this month and a custom range, in the asked time zone', function () {
        $today = filtered($this->me, ['started_at' => '2026-11-23 08:00:00']);
        // 01:30 in Karachi today, still yesterday in UTC.
        $lateNight = filtered($this->me, ['started_at' => '2026-11-22 20:30:00']);
        $week = filtered($this->me, ['started_at' => '2026-11-18 08:00:00']);
        $month = filtered($this->me, ['started_at' => '2026-11-02 08:00:00']);
        $old = filtered($this->me, ['started_at' => '2026-10-02 08:00:00']);
        $tz = ['tz' => 'Asia/Karachi'];

        expect(listedFor($this, ['date' => ['op' => 'is', 'values' => ['today']]], $tz))->toBe(idsOf($today, $lateNight))
            ->and(listedFor($this, ['date' => ['op' => 'is', 'values' => ['today']]], ['tz' => 'UTC']))->toBe(idsOf($today))
            ->and(listedFor($this, ['date' => ['op' => 'is', 'values' => ['last_7_days']]], $tz))->toBe(idsOf($today, $lateNight, $week))
            ->and(listedFor($this, ['date' => ['op' => 'is', 'values' => ['this_month']]], $tz))->toBe(idsOf($today, $lateNight, $week, $month))
            ->and(listedFor($this, ['date' => ['op' => 'is_not', 'values' => ['this_month']]], $tz))->toBe(idsOf($old))
            ->and(listedFor($this, ['date' => ['op' => 'is', 'values' => ['custom'], 'from' => '2026-11-02', 'to' => '2026-11-18']], $tz))->toBe(idsOf($week, $month))
            ->and(listedFor($this, ['date' => ['op' => 'is', 'values' => ['custom'], 'to' => '2026-11-02']], $tz))->toBe(idsOf($month, $old))
            ->and(listedFor($this, ['date' => ['op' => 'is_not', 'values' => ['custom'], 'from' => '2026-11-02']], $tz))->toBe(idsOf($old))
            // Any time is no filter.
            ->and(listedFor($this, ['date' => ['op' => 'is', 'values' => ['any']]], $tz))->toBe(idsOf($today, $lateNight, $week, $month, $old));
    });

    it('filters by source: WhatsApp Web is WhatsApp, and a file that came in as a file is Imported', function () {
        $whatsapp = filtered($this->me, ['source_app' => 'whatsapp']);
        $web = filtered($this->me, ['source_app' => 'whatsapp_web']);
        $zoom = filtered($this->me, ['source_app' => 'zoom']);
        $imported = filtered($this->me, ['source_app' => 'whatsapp', 'origin' => 'imported']);
        $requested = filtered($this->me, ['source_app' => 'other', 'origin' => 'requested']);

        expect(listedFor($this, ['source' => ['op' => 'is', 'values' => ['whatsapp']]]))->toBe(idsOf($whatsapp, $web))
            ->and(listedFor($this, ['source' => ['op' => 'is', 'values' => ['imported', 'zoom']]]))->toBe(idsOf($zoom, $imported, $requested))
            ->and(listedFor($this, ['source' => ['op' => 'is_not', 'values' => ['whatsapp', 'imported']]]))->toBe(idsOf($zoom));
    });

    it('filters by device', function () {
        $phone = filtered($this->me, ['source_device' => 'phone']);
        $desktop = filtered($this->me, ['source_device' => 'desktop']);
        $web = filtered($this->me, ['source_device' => 'web']);

        expect(listedFor($this, ['device' => ['op' => 'is', 'values' => ['desktop', 'web']]]))->toBe(idsOf($desktop, $web))
            ->and(listedFor($this, ['device' => ['op' => 'is_not', 'values' => ['desktop', 'web']]]))->toBe(idsOf($phone));
    });

    it('filters by project with its subfolders, and by no project', function () {
        $acme = Fx::client($this->me, 'Acme');
        $sub = Client::query()->create(['user_id' => $this->me->id, 'name' => 'Invoices', 'color' => '#26978f', 'parent_id' => $acme->id]);
        $family = Fx::client($this->me, 'Family');
        $inAcme = filtered($this->me, ['client_id' => $acme->id]);
        $inSub = filtered($this->me, ['client_id' => $sub->id]);
        $inFamily = filtered($this->me, ['client_id' => $family->id]);
        $inbox = filtered($this->me);

        expect(listedFor($this, ['project' => ['op' => 'is', 'values' => [$acme->id]]]))->toBe(idsOf($inAcme, $inSub))
            ->and(listedFor($this, ['project' => ['op' => 'is', 'values' => [$sub->id, 'none']]]))->toBe(idsOf($inSub, $inbox))
            ->and(listedFor($this, ['project' => ['op' => 'is_not', 'values' => [$acme->id]]]))->toBe(idsOf($inFamily, $inbox))
            // A project that is gone (or never was the person's) matches nothing.
            ->and(listedFor($this, ['project' => ['op' => 'is', 'values' => [Fx::MISSING_ID]]]))->toBe([]);
    });

    it('filters by language: the transcript\'s, else the chosen one, else Unknown', function () {
        $urdu = filtered($this->me, [], [], 'completed', 'hi');
        $english = filtered($this->me, [], [], 'completed', 'english');
        $chosen = filtered($this->me, ['status' => SessionStatus::AudioOnly, 'language' => 'ar'], [], null);
        $unknown = filtered($this->me, ['status' => SessionStatus::AudioOnly, 'language' => null], [], null);

        expect(listedFor($this, ['language' => ['op' => 'is', 'values' => ['ur']]]))->toBe(idsOf($urdu))
            ->and(listedFor($this, ['language' => ['op' => 'is', 'values' => ['ar', 'unknown']]]))->toBe(idsOf($chosen, $unknown))
            ->and(listedFor($this, ['language' => ['op' => 'is_not', 'values' => ['ur', 'unknown']]]))->toBe(idsOf($english, $chosen));
    });

    it('filters by status: transcribed, not transcribed, processing and failed', function () {
        $done = filtered($this->me);
        $audio = filtered($this->me, ['status' => SessionStatus::AudioOnly], [], null);
        $moving = filtered($this->me, ['status' => SessionStatus::Processing], [], TranscriptStatus::Processing->value);
        $failed = filtered($this->me, [], [], TranscriptStatus::Failed->value);
        $sessionFailed = filtered($this->me, ['status' => SessionStatus::Failed], [], null);

        expect(listedFor($this, ['status' => ['op' => 'is', 'values' => ['transcribed']]]))->toBe(idsOf($done))
            ->and(listedFor($this, ['status' => ['op' => 'is', 'values' => ['not_transcribed', 'processing']]]))->toBe(idsOf($audio, $moving))
            ->and(listedFor($this, ['status' => ['op' => 'is', 'values' => ['failed']]]))->toBe(idsOf($failed, $sessionFailed))
            ->and(listedFor($this, ['status' => ['op' => 'is_not', 'values' => ['transcribed', 'failed']]]))->toBe(idsOf($audio, $moving));
    });

    it('filters by labels, capitals ignored; is not keeps unlabelled recordings', function () {
        $clients = filtered($this->me, [], ['Clients']);
        $shout = filtered($this->me, [], ['CLIENTS', 'Invoice']);
        $invoice = filtered($this->me, [], ['Invoice']);
        $none = filtered($this->me);

        expect(listedFor($this, ['labels' => ['op' => 'is', 'values' => ['Clients']]]))->toBe(idsOf($clients, $shout))
            ->and(listedFor($this, ['labels' => ['op' => 'is', 'values' => ['clients', 'invoice']]]))->toBe(idsOf($clients, $shout, $invoice))
            ->and(listedFor($this, ['labels' => ['op' => 'is_not', 'values' => ['clients']]]))->toBe(idsOf($invoice, $none));
    });

    it('filters by marked: pinned, important and keep forever', function () {
        $pinned = filtered($this->me, ['pinned' => true]);
        $important = filtered($this->me, ['important' => true]);
        $kept = filtered($this->me, ['keep_forever' => true]);
        $plain = filtered($this->me);

        expect(listedFor($this, ['marked' => ['op' => 'is', 'values' => ['pinned', 'keep_forever']]]))->toBe(idsOf($pinned, $kept))
            ->and(listedFor($this, ['marked' => ['op' => 'is_not', 'values' => ['pinned', 'important']]]))->toBe(idsOf($kept, $plain));
    });

    it('filters by bookmarks: any bookmark, or a tag, capitals ignored', function () {
        $decision = filtered($this->me);
        $decision->markers()->create(['at_ms' => 1000, 'tag' => 'Decision']);
        $untagged = filtered($this->me);
        $untagged->markers()->create(['at_ms' => 1000, 'tag' => null]);
        $none = filtered($this->me);

        expect(listedFor($this, ['bookmarks' => ['op' => 'is', 'values' => ['any']]]))->toBe(idsOf($decision, $untagged))
            ->and(listedFor($this, ['bookmarks' => ['op' => 'is', 'values' => ['DECISION']]]))->toBe(idsOf($decision))
            ->and(listedFor($this, ['bookmarks' => ['op' => 'is_not', 'values' => ['any']]]))->toBe(idsOf($none))
            ->and(collect(facetsFor($this)['sections']['bookmarks'])->pluck('count', 'value')->all())->toBe(['any' => 2, 'decision' => 1]);
    });

    it('searches titles and transcripts with the filters, and takes the filters in the bracket form too', function () {
        $byTitle = filtered($this->me, ['title' => 'Invoice call', 'source_app' => 'zoom']);
        $byText = filtered($this->me, ['title' => 'Monday', 'source_app' => 'zoom']);
        Transcript::query()->where('capture_session_id', $byText->id)->firstOrFail()->update(['text' => 'The invoice is due.']);
        filtered($this->me, ['title' => 'Invoice too', 'source_app' => 'whatsapp']);
        filtered($this->me, ['title' => 'Something else', 'source_app' => 'zoom']);

        expect(listedFor($this, ['source' => ['op' => 'is', 'values' => ['zoom']]], ['q' => 'invoice']))->toBe(idsOf($byTitle, $byText));
        $bracket = $this->api($this->token)->getJson('/api/library?q=invoice&filters[source][op]=is&filters[source][values][]=zoom')->assertOk()->json('data.*.id');
        expect(collect($bracket)->sort()->values()->all())->toBe(idsOf($byTitle, $byText))
            ->and(facetsFor($this, ['source' => ['op' => 'is', 'values' => ['zoom']]], ['q' => 'invoice'])['total'])->toBe(2);
    });

    it('finds every word somewhere among the title, the transcript and the project path, the words in different places', function () {
        $acme = Fx::client($this->me, 'Acme Holdings');
        $contracts = Fx::client($this->me, 'Contracts');
        $contracts->forceFill(['parent_id' => $acme->id])->save();
        // Mixed places: "contracts" is the project's name, "clause" is in the transcript.
        $clause = filtered($this->me, ['title' => 'Monday', 'client_id' => $contracts->id]);
        Transcript::query()->where('capture_session_id', $clause->id)->firstOrFail()->update(['text' => 'Read the penalty clause again.']);
        $inAcme = filtered($this->me, ['title' => 'Tuesday', 'client_id' => $acme->id]);
        $byTitle = filtered($this->me, ['title' => 'Acme clause review']);
        $elsewhere = filtered($this->me, ['title' => 'Wednesday']);
        Transcript::query()->where('capture_session_id', $elsewhere->id)->firstOrFail()->update(['text' => 'No clause here.']);
        // A teammate's project of the same names finds nothing of theirs, and nothing of mine.
        $mate = Fx::user();
        app(Workspaces::class)->addMember($this->workspace, $mate, WorkspaceRole::Member);
        $theirs = Fx::client($mate, 'Contracts');
        $theirs->forceFill(['workspace_id' => $this->workspace->id])->save();
        filtered($mate, ['workspace_id' => $this->workspace->id, 'client_id' => $theirs->id, 'title' => 'Their clause']);

        expect(listedFor($this, [], ['q' => 'contracts clause']))->toBe(idsOf($clause))
            // A subfolder: "acme" is the name of the folder above Contracts; capitals ignored.
            ->and(listedFor($this, [], ['q' => 'ACME']))->toBe(idsOf($clause, $inAcme, $byTitle))
            ->and(listedFor($this, [], ['q' => 'acme clause']))->toBe(idsOf($clause, $byTitle))
            ->and(listedFor($this, [], ['q' => 'holdings tuesday']))->toBe(idsOf($inAcme))
            ->and(listedFor($this, [], ['q' => '"acme holdings" penalty']))->toBe(idsOf($clause))
            // Every word somewhere: "wednesday" is in no project of Acme's.
            ->and(listedFor($this, [], ['q' => 'acme wednesday']))->toBe([])
            ->and(facetsFor($this, [], ['q' => 'acme clause'])['total'])->toBe(2)
            ->and(listedFor($this, ['project' => ['op' => 'is', 'values' => [$acme->id]]], ['q' => 'clause']))->toBe(idsOf($clause));
    });

    it('refuses what is not a filter, with the field named', function () {
        foreach ([
            '{"colour": {"op": "is", "values": ["red"]}}' => 'filters.colour',
            '{"source": {"op": "maybe", "values": ["zoom"]}}' => 'filters.source.op',
            '{"source": {"op": "is", "values": ["fax"]}}' => 'filters.source.values',
            '{"source": {"op": "is", "values": "zoom"}}' => 'filters.source.values',
            '{"date": {"op": "is", "values": ["today", "this_month"]}}' => 'filters.date.values',
            '{"date": {"op": "is", "values": ["custom"]}}' => 'filters.date.from',
            '{"date": {"op": "is", "values": ["custom"], "from": "2026-11-20", "to": "2026-11-02"}}' => 'filters.date.to',
            '{"date": {"op": "is", "values": ["custom"], "from": "2026-02-30"}}' => 'filters.date.from',
            '{"project": {"op": "is", "values": ["acme"]}}' => 'filters.project.values',
            '["source"]' => 'filters',
            'not json' => 'filters',
        ] as $filters => $field) {
            foreach (['/api/library', '/api/library/facets'] as $path) {
                $this->api($this->token)->getJson($path.'?'.http_build_query(['filters' => $filters]))->assertStatus(422)
                    ->assertJson(['code' => 'validation_failed'])->assertJsonValidationErrors([$field]);
            }
        }
        $many = json_encode(['labels' => ['op' => 'is', 'values' => array_map(fn (int $i): string => "label {$i}", range(1, 51))]]);
        $this->api($this->token)->getJson('/api/library/facets?'.http_build_query(['filters' => $many]))->assertStatus(422);
    });
});

describe('the facets', function () {
    it('counts each section with the other sections\' filters and not its own, so every option stays selectable', function () {
        filtered($this->me, ['source_app' => 'whatsapp', 'source_device' => 'phone']);
        filtered($this->me, ['source_app' => 'whatsapp', 'source_device' => 'phone', 'pinned' => true]);
        filtered($this->me, ['source_app' => 'whatsapp', 'source_device' => 'desktop']);
        filtered($this->me, ['source_app' => 'zoom', 'source_device' => 'desktop']);
        filtered($this->me, ['source_app' => 'zoom', 'source_device' => 'desktop', 'pinned' => true]);
        filtered($this->me, ['source_app' => 'google_meet', 'source_device' => 'web']);

        $answer = facetsFor($this, ['source' => ['op' => 'is', 'values' => ['whatsapp']], 'device' => ['op' => 'is', 'values' => ['desktop']]]);
        $counts = fn (string $section): array => collect($answer['sections'][$section])->pluck('count', 'value')->all();

        expect($answer['total'])->toBe(1)
            // Source leaves its own WhatsApp out: what each app holds on the desktop.
            ->and($counts('source'))->toBe(['whatsapp' => 1, 'google_meet' => 0, 'zoom' => 2])
            // Device leaves its own Desktop out: where the WhatsApp calls were made.
            ->and($counts('device'))->toBe(['phone' => 2, 'desktop' => 1, 'web' => 0])
            // Every other section counts what both filters keep.
            ->and($counts('marked'))->toBe(['pinned' => 0, 'important' => 0, 'keep_forever' => 0])
            ->and($counts('status'))->toBe(['transcribed' => 1, 'not_transcribed' => 0, 'processing' => 0, 'failed' => 0]);
    });

    it('counts "is not" the same way', function () {
        filtered($this->me, ['source_app' => 'whatsapp'], ['Clients']);
        filtered($this->me, ['source_app' => 'zoom'], ['Clients']);
        filtered($this->me, ['source_app' => 'zoom']);

        $answer = facetsFor($this, ['source' => ['op' => 'is_not', 'values' => ['whatsapp']], 'labels' => ['op' => 'is', 'values' => ['clients']]]);
        expect($answer['total'])->toBe(1)
            ->and(collect($answer['sections']['source'])->pluck('count', 'value')->all())->toBe(['whatsapp' => 1, 'zoom' => 1])
            ->and(collect($answer['sections']['labels'])->pluck('count', 'value')->all())->toBe(['clients' => 1]);
    });

    it('answers a change at once, cache or not', function () {
        $session = filtered($this->me);
        expect(facetsFor($this, ['labels' => ['op' => 'is', 'values' => ['clients']]])['total'])->toBe(0);

        $this->api($this->token)->patchJson("/api/sessions/{$session->id}", ['labels' => ['Clients']])->assertOk();
        expect(facetsFor($this, ['labels' => ['op' => 'is', 'values' => ['clients']]])['total'])->toBe(1);

        $this->api($this->token)->deleteJson("/api/sessions/{$session->id}")->assertNoContent();
        expect(facetsFor($this)['total'])->toBe(0);
    });

    it('says what needs the person: a failure, a waiting call, and one stuck for more than 24 hours', function () {
        filtered($this->me, [], [], TranscriptStatus::Failed->value);
        CaptureSession::factory()->open()->create(['user_id' => $this->me->id]);
        filtered($this->me, ['status' => SessionStatus::Uploading, 'started_at' => now()->subHours(30), 'ended_at' => now()->subHours(25)], [], null);
        // Uploading for an hour is not stuck yet.
        filtered($this->me, ['status' => SessionStatus::Uploading, 'started_at' => now()->subHours(2), 'ended_at' => now()->subHour()], [], null);
        filtered($this->me);

        expect(facetsFor($this)['needs_you'])->toBe(['count' => 3, 'failed' => 1, 'waiting' => 1, 'stuck' => 1])
            ->and($this->api($this->token)->getJson('/api/library?view=needs_you')->assertOk()->json('data'))->toHaveCount(3)
            ->and(facetsFor($this)['library']['needs_you'])->toBe(3);
    });
});

describe('tenant isolation', function () {
    it('never counts or lists a teammate\'s or another workspace\'s recordings, projects or labels', function () {
        $mate = Fx::user();
        app(Workspaces::class)->addMember($this->workspace, $mate, WorkspaceRole::Member);
        $theirs = Fx::client($mate, 'Theirs');
        $theirs->forceFill(['workspace_id' => $this->workspace->id])->save();
        filtered($mate, ['workspace_id' => $this->workspace->id, 'client_id' => $theirs->id, 'source_app' => 'zoom', 'pinned' => true], ['Secret']);
        $stranger = Fx::user();
        filtered($stranger, ['source_app' => 'zoom'], ['Secret']);
        // My own recording in my other workspace stays there too.
        $current = (int) $this->me->current_workspace_id;
        $other = app(Workspaces::class)->createFor($this->me, 'Second');
        $this->me->forceFill(['current_workspace_id' => $current])->save();
        filtered($this->me, ['workspace_id' => $other->id, 'source_app' => 'zoom']);
        $mine = filtered($this->me, ['source_app' => 'whatsapp']);

        $answer = facetsFor($this, ['project' => ['op' => 'is', 'values' => [$theirs->id]]]);
        expect($answer['total'])->toBe(0)
            ->and(facetsFor($this)['total'])->toBe(1)
            ->and(collect(facetsFor($this)['sections']['source'])->pluck('value')->all())->toBe(['whatsapp'])
            ->and(collect(facetsFor($this)['sections']['labels'])->pluck('value')->all())->toBe([])
            ->and(collect(facetsFor($this)['sections']['marked'])->sum('count'))->toBe(0)
            ->and(collect(facetsFor($this)['sections']['project'])->pluck('value')->all())->toBe(['none'])
            ->and(facetsFor($this)['needs_you']['count'])->toBe(0)
            ->and(listedFor($this, ['source' => ['op' => 'is', 'values' => ['zoom']]]))->toBe([])
            ->and(listedFor($this, ['labels' => ['op' => 'is_not', 'values' => ['secret']]]))->toBe(idsOf($mine))
            ->and(listedFor($this, [], ['q' => 'Hello there']))->toBe(idsOf($mine));
    });

    it('needs the library ability', function () {
        $token = Fx::token(Fx::device($this->me), ['config:read']);
        $this->api($token)->getJson('/api/library/facets')->assertStatus(403);
        $this->api()->getJson('/api/library/facets')->assertStatus(401);
    });
});
