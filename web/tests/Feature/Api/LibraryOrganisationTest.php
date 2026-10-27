<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Enums\UploadStatus;
use App\Http\Controllers\Api\OrganiseController;
use App\Models\AuditEvent;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\User;
use App\Services\LibraryOrganiser;
use Illuminate\Support\Carbon;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Organising the library" (since v1.1, plan B15): client folders, labels, pins, smart views, bulk
 * actions and the zip export.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->owner = $this->device->user_id;
});

function organisedSession(object $test, array $state = []): CaptureSession
{
    return CaptureSession::factory()->create(['user_id' => $test->owner, ...$state]);
}

describe('labels, pins and folders on one recording', function () {
    it('sets the title, folder, pin and labels and returns the library item', function () {
        $client = Client::factory()->create(['user_id' => $this->owner, 'name' => 'Acme', 'color' => '#22d3ee', 'avatar' => 'AC']);
        $session = organisedSession($this);
        $before = $session->updated_at;
        $this->travel(5)->seconds();

        $response = $this->patchJson("/api/sessions/{$session->id}", [
            'title' => ' Kickoff ', 'client_id' => $client->id, 'pinned' => true, 'labels' => [' Follow  up ', 'invoice', 'INVOICE', '', str_repeat('x', 41)],
        ])->assertOk();

        expect($response->json('data.title'))->toBe('Kickoff')
            ->and($response->json('data.client'))->toBe(['id' => $client->id, 'name' => 'Acme', 'color' => '#22d3ee', 'avatar' => 'AC'])
            ->and($response->json('data.pinned'))->toBeTrue()
            ->and($response->json('data.labels'))->toBe(['Follow up', 'invoice'])
            ->and($session->fresh()->updated_at->gt($before))->toBeTrue();
    });

    it('changes only the fields sent and clears the folder with null', function () {
        $client = Client::factory()->create(['user_id' => $this->owner]);
        $session = organisedSession($this, ['client_id' => $client->id, 'pinned' => true]);
        $session->labels()->create(['label' => 'keep']);

        $this->patchJson("/api/sessions/{$session->id}", ['client_id' => null])->assertOk()
            ->assertJsonPath('data.client', null)->assertJsonPath('data.pinned', true)->assertJsonPath('data.labels', ['keep']);
    });

    it('refuses another owner\'s client or recording and too many labels', function () {
        $foreign = Client::factory()->create();
        $session = organisedSession($this);
        $this->patchJson("/api/sessions/{$session->id}", ['client_id' => $foreign->id])->assertNotFound()->assertJsonPath('code', 'client_not_found');
        $other = CaptureSession::factory()->create();
        $this->patchJson("/api/sessions/{$other->id}", ['pinned' => true])->assertNotFound();
        $labels = array_map(fn (int $i): string => "label {$i}", range(1, 21));
        $this->patchJson("/api/sessions/{$session->id}", ['labels' => $labels])->assertStatus(422)->assertJsonPath('code', 'too_many_labels');
    });

    it('cleans labels the same way everywhere', function () {
        expect(LibraryOrganiser::cleanLabel("  a\u{0007}b   c "))->toBe('ab c')
            ->and(LibraryOrganiser::cleanLabel('   '))->toBeNull()
            ->and(LibraryOrganiser::cleanLabel(str_repeat('é', 40)))->toBe(str_repeat('é', 40))
            ->and(LibraryOrganiser::cleanLabel(42))->toBeNull()
            ->and(OrganiseController::avatar(' acme '))->toBe('AC')
            ->and(OrganiseController::avatar('🦊 fox'))->toBe('🦊 ')
            ->and(OrganiseController::avatar(''))->toBeNull();
    });
});

describe('smart views and filters', function () {
    beforeEach(function () {
        Carbon::setTestNow(Carbon::parse('2026-10-28 15:00:00', 'UTC')); // a Wednesday
        $this->today = organisedSession($this, ['started_at' => now()->subHour(), 'title' => 'today']);
        $this->monday = organisedSession($this, ['started_at' => Carbon::parse('2026-10-26 09:00:00', 'UTC'), 'title' => 'monday', 'pinned' => true]);
        $this->old = organisedSession($this, ['started_at' => now()->subDays(20), 'title' => 'old', 'status' => SessionStatus::Failed]);
        $this->waiting = CaptureSession::factory()->open()->create(['user_id' => $this->owner, 'title' => 'waiting']);
        $this->old->markers()->create(['at_ms' => 1000, 'tag' => 'Budget', 'source' => 'device']);
        $this->monday->labels()->create(['label' => 'Invoice']);
        $this->uploadFailed = organisedSession($this, ['started_at' => now()->subDays(30), 'title' => 'upload failed']);
        $this->makeReadyRecording($this->uploadFailed, Fixtures::audio(1000))->update(['upload_status' => UploadStatus::Failed]);
    });

    afterEach(fn () => Carbon::setTestNow());

    function titlesFor(object $test, string $query): array
    {
        return collect($test->getJson("/api/library?{$query}")->assertOk()->json('data'))->pluck('title')->sort()->values()->all();
    }

    it('lists each smart view', function () {
        expect(titlesFor($this, 'view=today'))->toBe(['today'])
            ->and(titlesFor($this, 'view=this_week'))->toBe(['monday', 'today'])
            ->and(titlesFor($this, 'view=waiting'))->toBe(['waiting'])
            ->and(titlesFor($this, 'view=bookmarked'))->toBe(['old'])
            ->and(titlesFor($this, 'view=failed'))->toBe(['old', 'upload failed'])
            ->and(titlesFor($this, 'view=pinned'))->toBe(['monday']);
    });

    it('counts today in the viewer\'s time zone', function () {
        // 15:00 UTC is midnight in Tokyo: the call an hour ago was yesterday there.
        expect(titlesFor($this, 'view=today&tz=Asia/Tokyo'))->toBe([]);
        $this->getJson('/api/library?view=today&tz=Mars/Base')->assertStatus(422);
        $this->getJson('/api/library?view=someday')->assertStatus(422);
    });

    it('filters by label, ignoring capitals, and by pin', function () {
        expect(titlesFor($this, 'label=invoice'))->toBe(['monday'])
            ->and(titlesFor($this, 'pinned=1'))->toBe(['monday'])
            ->and(titlesFor($this, 'pinned=0'))->toBe(['old', 'today', 'upload failed']);
    });

    it('reports the view counts and the labels in use', function () {
        CaptureSession::factory()->create(['pinned' => true])->labels()->create(['label' => 'Other owner']);

        $this->getJson('/api/library/views')->assertOk()->assertExactJson([
            'views' => ['today' => 1, 'this_week' => 2, 'waiting' => 1, 'bookmarked' => 1, 'failed' => 2, 'pinned' => 1],
            'labels' => [['label' => 'Invoice', 'count' => 1]],
        ]);
    });
});

describe('bulk actions', function () {
    beforeEach(function () {
        $this->a = organisedSession($this);
        $this->b = organisedSession($this);
        $this->foreign = CaptureSession::factory()->create();
        $this->ids = [$this->a->id, $this->b->id, $this->foreign->id];
    });

    it('moves recordings to a folder and back out', function () {
        $client = Client::factory()->create(['user_id' => $this->owner]);
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'move', 'client_id' => $client->id])->assertOk()->assertExactJson(['changed' => 2]);
        expect($this->a->fresh()->client_id)->toBe($client->id)->and($this->foreign->fresh()->client_id)->toBeNull();
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'move', 'client_id' => null])->assertOk();
        expect($this->b->fresh()->client_id)->toBeNull();
    });

    it('labels, unlabels, pins and unpins', function () {
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'label', 'label' => ' Q4 '])->assertOk();
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'label', 'label' => 'q4'])->assertOk();
        expect($this->a->fresh()->labelList())->toBe(['Q4'])->and($this->foreign->labels()->count())->toBe(0);
        $this->postJson('/api/sessions/bulk', ['ids' => [$this->a->id], 'action' => 'unlabel', 'label' => 'q4'])->assertOk();
        expect($this->a->fresh()->labelList())->toBe([])->and($this->b->fresh()->labelList())->toBe(['Q4']);
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'pin'])->assertOk();
        expect($this->a->fresh()->pinned)->toBeTrue()->and($this->foreign->fresh()->pinned)->toBeFalse();
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'unpin'])->assertOk();
        expect($this->b->fresh()->pinned)->toBeFalse();
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'label'])->assertStatus(422);
    });

    it('deletes only the owner\'s recordings, as tombstones', function () {
        $this->postJson('/api/sessions/bulk', ['ids' => $this->ids, 'action' => 'delete'])->assertOk()->assertExactJson(['changed' => 2]);
        expect(CaptureSession::withTrashed()->find($this->a->id)?->trashed())->toBeTrue()
            ->and($this->foreign->fresh()->trashed())->toBeFalse();
    });

    it('validates the request', function () {
        $this->postJson('/api/sessions/bulk', ['ids' => [], 'action' => 'pin'])->assertStatus(422);
        $this->postJson('/api/sessions/bulk', ['ids' => [$this->a->id], 'action' => 'explode'])->assertStatus(422);
        $this->postJson('/api/sessions/bulk', ['ids' => array_fill(0, 201, $this->a->id), 'action' => 'pin'])->assertStatus(422);
        $this->postJson('/api/sessions/bulk', ['ids' => [$this->a->id], 'action' => 'move', 'client_id' => Client::factory()->create()->id])
            ->assertNotFound()->assertJsonPath('code', 'client_not_found');
    });
});

describe('zip export', function () {
    it('puts each finished transcript in one zip and skips unfinished ones', function () {
        $a = organisedSession($this, ['title' => 'Call']);
        $b = organisedSession($this, ['title' => 'Call', 'started_at' => $a->started_at]);
        $c = organisedSession($this, ['title' => 'Pending']);
        foreach ([$a, $b] as $session) {
            $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'x',
                'segments' => [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => 'Hello there']]]);
        }
        $c->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Processing, 'text' => null, 'segments' => []]);

        $response = $this->postJson('/api/sessions/export', ['ids' => [$a->id, $b->id, $c->id], 'format' => 'txt'])->assertOk()
            ->assertHeader('Content-Type', 'application/zip')->assertHeader('X-Export-Count', '2');
        expect($response->headers->get('Content-Disposition'))->toContain('EchoLedger transcripts');

        $path = tempnam(sys_get_temp_dir(), 'zip-test-');
        file_put_contents($path, $response->getContent());
        $zip = new ZipArchive;
        $zip->open($path);
        $names = array_map(fn (int $i): string => (string) $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        expect($names)->toHaveCount(2)->and(count(array_unique($names)))->toBe(2)
            ->and((string) $zip->getFromIndex(0))->toContain('Hello there');
        $zip->close();
        unlink($path);

        // The export is written to the audit log (B16) with how many recordings left, never with their content.
        $event = AuditEvent::query()->where('event', 'sessions_exported')->sole();
        expect(json_encode($event->toArray()))->not->toContain('Hello there');
    });

    it('says when nothing can be exported and refuses other owners\' recordings', function () {
        $foreign = CaptureSession::factory()->create();
        $this->postJson('/api/sessions/export', ['ids' => [$foreign->id], 'format' => 'txt'])->assertStatus(409)->assertJsonPath('code', 'nothing_to_export');
        $this->postJson('/api/sessions/export', ['ids' => [$foreign->id], 'format' => 'html'])->assertStatus(422);
    });
});

describe('client folders', function () {
    it('creates, lists pinned first, and updates folders', function () {
        Client::factory()->create(['user_id' => $this->owner, 'name' => 'Zeta']);
        $created = $this->postJson('/api/clients', ['name' => ' Acme ', 'color' => '#22D3EE', 'avatar' => 'acme'])->assertCreated();
        expect($created->json('data'))->toMatchArray(['name' => 'Acme', 'color' => '#22d3ee', 'avatar' => 'AC', 'pinned' => false, 'session_count' => 0]);
        $id = $created->json('data.id');

        $this->patchJson("/api/clients/{$id}", ['pinned' => true, 'avatar' => null])->assertOk()->assertJsonPath('data.pinned', true)->assertJsonPath('data.avatar', null);
        organisedSession($this, ['client_id' => $id]);
        $list = $this->getJson('/api/clients')->assertOk()->json('data');
        expect(array_column($list, 'name'))->toBe(['Acme', 'Zeta'])->and($list[0]['session_count'])->toBe(1);
    });

    it('rejects duplicate names, bad colours and other owners\' folders', function () {
        Client::factory()->create(['user_id' => $this->owner, 'name' => 'Acme']);
        $this->postJson('/api/clients', ['name' => 'Acme'])->assertStatus(422);
        $this->postJson('/api/clients', ['name' => 'New', 'color' => 'red'])->assertStatus(422);
        $foreign = Client::factory()->create(['user_id' => User::factory()]);
        $this->patchJson("/api/clients/{$foreign->id}", ['pinned' => true])->assertNotFound();
    });
});
