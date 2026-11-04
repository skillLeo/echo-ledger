<?php

declare(strict_types=1);

use App\Enums\SessionStatus;
use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Plan;
use App\Models\Recording;
use App\Models\SentItem;
use App\Models\Transcript;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\ChunkedUploadService;
use App\Services\Views\TranscriptViews;
use Filament\Notifications\Notification;
use Illuminate\Notifications\DatabaseNotification;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Sending to a teammate (since v1.5)": send to accounts on this server, Shared with me, the `shared` smart
 * folder (read-only), save an independent copy, remove from the list.
 */

beforeEach(function () {
    $this->ana = User::factory()->create(['name' => 'Ana', 'email' => 'ana@example.com']);
    [$this->device, $this->token] = $this->enroll($this->ana, platform: 'windows');
    $this->omar = User::factory()->create(['name' => 'Omar', 'email' => 'omar@example.com']);
    [$this->omarDevice, $this->omarToken] = $this->enroll($this->omar);
    $this->asToken($this->token);
    $this->audio = Fixtures::audio(5000);
    $this->call = sentRecording($this, 'Call with Omar', $this->audio);
});

function sentRecording(object $test, string $title, string $audio, ?Client $client = null): CaptureSession
{
    $session = $test->makeReadySession($test->device, $audio, ['title' => $title, 'status' => SessionStatus::Completed, 'client_id' => $client?->id]);
    $segments = [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 900, 'text' => "Words of {$title}"]];
    $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'groq', 'status' => TranscriptStatus::Completed, 'language' => 'ur', 'text' => "Words of {$title}",
        'segments' => $segments,
        'meta' => ['views' => ['status' => 'ready', 'fingerprint' => TranscriptViews::fingerprint(new Transcript(['segments' => $segments])), 'roman' => ["Roman {$title}"]],
            'suggestions' => [['line' => 0, 'from' => 'Omer', 'to' => 'Omar', 'reason' => 'name', 'status' => 'open']]]]);
    $session->markers()->create(['at_ms' => 500, 'tag' => 'Budget', 'source' => 'device']);

    return $session;
}

/** "Ana sent you …" items in the person's activity. */
function sharedNotices(User $person): int
{
    return DatabaseNotification::query()->where('notifiable_id', $person->id)->get()->filter(fn ($n): bool => ActivityFeed::kindOf($n) === 'shared_with_you')->count();
}

function received(object $test): array
{
    return $test->asToken($test->omarToken)->getJson('/api/shared-with-me')->assertOk()->json('data');
}

describe('sending', function () {
    it('sends a recording to an account by email, with a note and an activity item', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['Omar@Example.com'], 'note' => 'for tomorrow'])->assertCreated()->assertExactJson(['sent' => 1]);

        $items = received($this);
        expect($items)->toHaveCount(1)
            ->and($items[0])->toMatchArray(['kind' => 'session', 'from' => ['name' => 'Ana', 'email' => 'ana@example.com'], 'note' => 'for tomorrow', 'title' => 'Call with Omar', 'saved_at' => null, 'client' => null])
            ->and($items[0]['session'])->toMatchArray(['id' => $this->call->id, 'title' => 'Call with Omar', 'client' => null, 'device' => null, 'filed_by' => null]);
        $note = DatabaseNotification::query()->where('notifiable_id', $this->omar->id)->get()->sole(fn ($n): bool => ActivityFeed::kindOf($n) === 'shared_with_you');
        expect(ActivityFeed::payload($note))->toMatchArray(['kind' => 'shared_with_you', 'title' => 'Ana sent you Call with Omar', 'body' => 'for tomorrow', 'session_id' => $this->call->id]);
    });

    it('puts a title, note and name with markup into the recipient\'s bell as text, and into the apps as typed', function () {
        // Anyone on the server can send to anyone: the bell renders its texts as HTML, so markup must stay text there.
        $this->ana->forceFill(['name' => 'Ana <b>Boss</b>'])->save();
        $title = '<a href="https://evil.example/login" style="position:fixed;inset:0">Your session ended, sign in again</a>';
        $trap = sentRecording($this, $title, Fixtures::audio(5050));
        $note = '<div style="position:fixed;inset:0;background:#fff">Sign in at <a href="https://evil.example">here</a> & <i>soon</i></div>';
        $this->postJson("/api/sessions/{$trap->id}/send", ['to' => ['omar@example.com'], 'note' => $note])->assertCreated();

        $stored = DatabaseNotification::query()->where('notifiable_id', $this->omar->id)->get()->sole(fn ($n): bool => ActivityFeed::kindOf($n) === 'shared_with_you');
        $bell = Notification::fromDatabase($stored)->inline()->toEmbeddedHtml();
        expect($bell)->not->toContain('href="https://evil.example')->not->toContain('style="position:fixed')->not->toContain('<b>Boss</b>')
            ->toContain('&lt;a href')->toContain('&lt;b&gt;Boss&lt;/b&gt;');
        // The apps and the console's Activity page get the words as they were typed (they show text, never HTML).
        expect(ActivityFeed::payload($stored))->toMatchArray(['title' => "Ana <b>Boss</b> sent you {$title}", 'body' => $note]);
        $activity = $this->asToken($this->omarToken)->getJson('/api/activity')->assertOk()->json('data');
        expect(collect($activity)->firstWhere('kind', 'shared_with_you'))->toMatchArray(['title' => "Ana <b>Boss</b> sent you {$title}", 'body' => $note]);
    });

    it('sends nothing when one email has no account', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com', 'nobody@example.com']])->assertNotFound()
            ->assertJsonPath('code', 'recipient_not_found')->assertJsonPath('emails', ['nobody@example.com']);

        expect(SentItem::query()->count())->toBe(0)->and(sharedNotices($this->omar))->toBe(0);
    });

    it('validates the body and answers 404 for another workspace', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => []])->assertStatus(422);
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['not-an-email']])->assertStatus(422);
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => array_map(fn (int $i): string => "p{$i}@example.com", range(1, 21))])->assertStatus(422);
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com'], 'note' => str_repeat('x', 501)])->assertStatus(422);

        $foreign = CaptureSession::factory()->create(['user_id' => $this->omar->id, 'device_id' => $this->omarDevice->id]);
        $this->postJson("/api/sessions/{$foreign->id}/send", ['to' => ['omar@example.com']])->assertNotFound()->assertJsonPath('code', 'session_not_found');
        $foreignProject = Client::factory()->create(['user_id' => $this->omar->id]);
        $this->postJson("/api/clients/{$foreignProject->id}/send", ['to' => ['omar@example.com']])->assertNotFound()->assertJsonPath('code', 'client_not_found');
    });

    it('keeps one item per recording and person, refreshed when sent again', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com'], 'note' => 'first'])->assertCreated();
        $this->travel(1)->minutes();
        $this->asToken($this->token)->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com'], 'note' => 'second'])->assertCreated();

        expect(received($this))->toHaveCount(1)->and(received($this)[0]['note'])->toBe('second');
    });
});

describe('reading what was sent', function () {
    it('lists received recordings in the shared folder, readable but not editable', function () {
        $project = Client::factory()->create(['user_id' => $this->ana->id, 'name' => 'Acme']);
        $sub = Client::factory()->create(['user_id' => $this->ana->id, 'name' => 'Design', 'parent_id' => $project->id]);
        $nested = sentRecording($this, 'Design review', Fixtures::audio(5100), $sub);
        $notSent = sentRecording($this, 'Private call', Fixtures::audio(5200));
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $this->postJson("/api/clients/{$project->id}/send", ['to' => ['omar@example.com']])->assertCreated();

        $this->asToken($this->omarToken);
        $shared = $this->getJson('/api/library?folder=shared')->assertOk()->json('data');
        expect(array_column($shared, 'id'))->toEqualCanonicalizing([$this->call->id, $nested->id])
            ->and(collect($shared)->pluck('client')->filter()->all())->toBe([])
            ->and($this->getJson('/api/library/views')->json('folders.shared'))->toBe(2)
            ->and(array_column($this->getJson('/api/library')->json('data'), 'id'))->not->toContain($this->call->id);

        $detail = $this->getJson("/api/sessions/{$this->call->id}")->assertOk();
        expect($detail->json('client'))->toBeNull()->and($detail->json('playback_url'))->toBeString();
        $this->get($detail->json('playback_url'))->assertOk();
        $this->getJson('/api/transcripts/'.$detail->json('transcript.final_transcript_id'))->assertOk()->assertJsonPath('text', 'Words of Call with Omar');
        $this->getJson('/api/transcripts/'.$detail->json('transcript.final_transcript_id').'/views')->assertOk();
        $this->postJson("/api/sessions/{$this->call->id}/opened")->assertNoContent();

        // Read-only: no edits, no deletes, nothing of what was not sent.
        $this->patchJson("/api/sessions/{$this->call->id}", ['title' => 'Mine now'])->assertNotFound();
        $this->deleteJson("/api/sessions/{$this->call->id}")->assertNotFound();
        $this->patchJson('/api/transcripts/'.$detail->json('transcript.final_transcript_id'), ['edits' => [['index' => 0, 'text' => 'x']]])->assertNotFound();
        $this->postJson("/api/sessions/{$this->call->id}/shares", ['audience' => 'public'])->assertNotFound();
        $this->getJson("/api/sessions/{$notSent->id}")->assertNotFound();
        expect($this->call->fresh()->title)->toBe('Call with Omar');
    });

    it('drops a recording from the list once the sender deletes it', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $this->call->delete();

        expect(received($this))->toBe([])
            ->and($this->getJson('/api/library?folder=shared')->json('data'))->toBe([]);
        $this->getJson("/api/sessions/{$this->call->id}")->assertNotFound();
    });
});

describe('saving a copy', function () {
    it('copies a recording with its audio, transcript, views and bookmarks, independent of the original', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $item = received($this)[0];

        $copyId = $this->postJson("/api/shared-with-me/{$item['id']}/save")->assertCreated()->json('session_id');
        $copy = unscoped(fn () => CaptureSession::query()->with(['recordings', 'transcripts', 'markers'])->findOrFail($copyId));
        expect($copy)->workspace_id->toBe($this->omar->current_workspace_id)->user_id->toBe($this->omar->id)
            ->client_id->toBeNull()->origin->toBe('saved')->title->toBe('Call with Omar')
            ->and($copy->recordings)->toHaveCount(1)
            ->and($copy->recordings[0]->id)->not->toBe($this->call->recordings()->value('id'))
            ->and($copy->transcripts->sole()->text)->toBe('Words of Call with Omar')
            ->and(array_keys($copy->transcripts->sole()->meta))->toBe(['views'])
            ->and($copy->markers->sole()->tag)->toBe('Budget')
            ->and(received($this)[0]['saved_at'])->not->toBeNull();
        // The views came with it, still ready (they match the copied words).
        $this->getJson('/api/transcripts/'.$copy->transcripts->sole()->id.'/views')->assertOk()
            ->assertJsonPath('status', 'ready')->assertJsonPath('roman', ['Roman Call with Omar']);
        // It is in Omar's own library now, and the save did not post "Transcript ready".
        expect(array_column($this->getJson('/api/library?folder=inbox')->json('data'), 'id'))->toContain($copyId)
            ->and(DatabaseNotification::query()->where('notifiable_id', $this->omar->id)->get()->map(fn ($n) => ActivityFeed::kindOf($n))->reject(fn (?string $k): bool => $k === 'device_paired')->values()->all())->toBe(['shared_with_you']);

        // The original goes for good; the copy plays on.
        $this->call->delete();
        unscoped(fn () => emptyUndoBin());
        $twin = unscoped(fn () => Recording::query()->where('capture_session_id', $copyId)->sole());
        $plain = app(ChunkedUploadService::class)->decryptToTemp($twin);
        expect(file_get_contents($plain))->toBe($this->audio);
        @unlink($plain);
        $this->getJson("/api/sessions/{$copyId}")->assertOk();
    });

    it('copies a project with its subfolders and recordings', function () {
        $project = Client::factory()->create(['user_id' => $this->ana->id, 'name' => 'Acme', 'color' => '#22d3ee']);
        $sub = Client::factory()->create(['user_id' => $this->ana->id, 'name' => 'Design', 'parent_id' => $project->id]);
        sentRecording($this, 'Kick-off', Fixtures::audio(5300), $project);
        sentRecording($this, 'Design review', Fixtures::audio(5400), $sub);
        Client::factory()->create(['user_id' => $this->omar->id, 'name' => 'Acme']);
        $this->postJson("/api/clients/{$project->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $item = received($this)[0];
        expect($item)->kind->toBe('project')->session->toBeNull()
            ->and($item['client'])->toMatchArray(['id' => $project->id, 'name' => 'Acme', 'path' => [], 'children_count' => 1, 'nested_session_count' => 2]);

        $copyId = $this->postJson("/api/shared-with-me/{$item['id']}/save")->assertCreated()->json('client_id');
        $clients = collect($this->getJson('/api/clients')->json('data'))->keyBy('id');
        expect($clients[$copyId])->toMatchArray(['name' => 'Acme (2)', 'color' => '#22d3ee', 'parent_id' => null, 'children_count' => 1, 'nested_session_count' => 2]);
        $subCopy = $clients->firstWhere('parent_id', $copyId);
        expect($subCopy['name'])->toBe('Design')->and($subCopy['session_count'])->toBe(1);
    });

    it('refuses a copy that does not fit the plan and copies nothing', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $item = received($this)[0];
        $small = Plan::query()->create(['key' => 'tiny', 'name' => 'Tiny', 'storage_bytes' => 10]);
        Workspace::query()->whereKey($this->omar->current_workspace_id)->update(['plan_id' => $small->id]);

        $this->postJson("/api/shared-with-me/{$item['id']}/save")->assertForbidden()->assertJsonPath('code', 'storage_limit_reached');
        expect(unscoped(fn () => CaptureSession::query()->where('workspace_id', $this->omar->current_workspace_id)->count()))->toBe(0)
            ->and(SentItem::query()->sole()->saved_at)->toBeNull();
    });
});

describe('removing', function () {
    it('takes an item off the list and leaves the original; other people\'s items are 404', function () {
        $this->postJson("/api/sessions/{$this->call->id}/send", ['to' => ['omar@example.com']])->assertCreated();
        $item = received($this)[0];
        $this->asToken($this->token)->deleteJson("/api/shared-with-me/{$item['id']}")->assertNotFound();
        $this->asToken($this->token)->postJson("/api/shared-with-me/{$item['id']}/save")->assertNotFound();

        $this->asToken($this->omarToken)->deleteJson("/api/shared-with-me/{$item['id']}")->assertNoContent();
        expect(received($this))->toBe([])->and($this->call->fresh())->not->toBeNull();
        $this->getJson("/api/sessions/{$this->call->id}")->assertNotFound();
    });
});
