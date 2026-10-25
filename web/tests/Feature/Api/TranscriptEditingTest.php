<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\Transcript;
use App\Models\TranscriptRevision;
use App\Services\TranscriptEditor;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/*
 * docs/API.md "Editing transcripts" (since v1.1): segment edits keep timings, find and replace, restore the original,
 * the revision list, and speaker names per session remembered per client.
 */

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll(platform: 'windows');
    $this->asToken($this->token);
    $this->session = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id]);
    $this->transcript = editableTranscript($this->session);
});

function editableTranscript(CaptureSession $session, TranscriptStatus $status = TranscriptStatus::Completed): Transcript
{
    return $session->transcripts()->create([
        'kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => $status, 'language' => 'en',
        'text' => "Me: Hello Acme.\nClient: We need the invoice by friday.",
        'segments' => [
            ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1200, 'text' => 'Hello Acme.', 'words' => [['text' => 'Hello', 'start_ms' => 0, 'end_ms' => 400]]],
            ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1500, 'end_ms' => 4000, 'text' => 'We need the invoice by friday.'],
        ],
    ]);
}

describe('editing segments', function () {
    it('changes the text, keeps the timings, drops stale word timings and marks the transcript edited', function () {
        $response = $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 0, 'text' => "  Hello\n ACME team. "]]])->assertOk();

        expect($response->json('segments.0.text'))->toBe('Hello ACME team.')
            ->and($response->json('segments.0.start_ms'))->toBe(0)
            ->and($response->json('segments.0.end_ms'))->toBe(1200)
            ->and($response->json('segments.0'))->not->toHaveKey('words')
            ->and($response->json('segments.1.text'))->toBe('We need the invoice by friday.')
            ->and($response->json('edited_at'))->toBeString()
            ->and($response->json('text'))->toContain('Hello ACME team.');
    });

    it('keeps the original as the first revision and records each edit with its author', function () {
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 0, 'text' => 'Hi Acme.']]])->assertOk();
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 1, 'text' => 'We need it by Friday.']]])->assertOk();

        $revisions = $this->getJson("/api/transcripts/{$this->transcript->id}/revisions")->assertOk()->json('data');
        expect(array_column($revisions, 'source'))->toBe(['edit', 'edit', 'original'])
            ->and($revisions[0]['author'])->toBe($this->device->name);
        $original = TranscriptRevision::query()->where('source', 'original')->sole();
        expect($original->segments[0]['text'])->toBe('Hello Acme.')
            ->and($original->segments[0])->toHaveKey('words');
    });

    it('saves nothing when the text is unchanged', function () {
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 0, 'text' => 'Hello Acme.']]])->assertOk()
            ->assertJsonPath('edited_at', null);
        expect(TranscriptRevision::query()->count())->toBe(0);
    });

    it('refuses an unknown segment, an empty text and an unfinished transcript', function () {
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 7, 'text' => 'x']]])
            ->assertUnprocessable()->assertJsonPath('code', 'segment_not_found');
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 0, 'text' => '   ']]])
            ->assertUnprocessable();
        $pending = editableTranscript(CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id]), TranscriptStatus::Processing);
        $this->patchJson("/api/transcripts/{$pending->id}", ['edits' => [['index' => 0, 'text' => 'x']]])
            ->assertStatus(409)->assertJsonPath('code', 'transcript_not_ready');
    });

    it('validates the request shape', function (array $body) {
        $this->patchJson("/api/transcripts/{$this->transcript->id}", $body)->assertUnprocessable();
    })->with([
        'no edits' => [[]],
        'empty list' => [['edits' => []]],
        'negative index' => [['edits' => [['index' => -1, 'text' => 'x']]]],
        'text too long' => [['edits' => [['index' => 0, 'text' => str_repeat('a', 5001)]]]],
    ]);

    it('hides another owner\'s transcript', function () {
        [$other] = $this->enroll();
        $foreign = editableTranscript(CaptureSession::factory()->create(['user_id' => $other->user_id, 'device_id' => $other->id]));

        $this->patchJson("/api/transcripts/{$foreign->id}", ['edits' => [['index' => 0, 'text' => 'x']]])
            ->assertNotFound()->assertJsonPath('code', 'transcript_not_found');
        $this->getJson("/api/transcripts/{$foreign->id}/revisions")->assertNotFound();
    });

    it('bumps the session so other devices sync the edit', function () {
        $before = $this->session->fresh()->updated_at;
        $this->travel(2)->minutes();
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 0, 'text' => 'Hi.']]])->assertOk();

        expect($this->session->fresh()->updated_at->greaterThan($before))->toBeTrue();
    });
});

describe('find and replace', function () {
    it('replaces case-insensitively by default and says how many', function () {
        $response = $this->postJson("/api/transcripts/{$this->transcript->id}/replace", ['find' => 'acme', 'replace' => 'Acme Ltd'])->assertOk();

        expect($response->json('replaced'))->toBe(1)
            ->and($response->json('transcript.segments.0.text'))->toBe('Hello Acme Ltd.');
        expect(TranscriptRevision::query()->where('source', 'replace')->count())->toBe(1);
    });

    it('honours match case and whole word', function () {
        $this->postJson("/api/transcripts/{$this->transcript->id}/replace", ['find' => 'ACME', 'replace' => 'x', 'match_case' => true])
            ->assertOk()->assertJsonPath('replaced', 0);
        $this->postJson("/api/transcripts/{$this->transcript->id}/replace", ['find' => 'in', 'replace' => 'x', 'whole_word' => true])
            ->assertOk()->assertJsonPath('replaced', 0);
        $this->postJson("/api/transcripts/{$this->transcript->id}/replace", ['find' => 'friday', 'replace' => 'Friday', 'whole_word' => true])
            ->assertOk()->assertJsonPath('replaced', 1)->assertJsonPath('transcript.segments.1.text', 'We need the invoice by Friday.');
    });

    it('treats the search text literally and supports Urdu text', function () {
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 0, 'text' => 'قیمت (price) ٹھیک ہے']]])->assertOk();

        $this->postJson("/api/transcripts/{$this->transcript->id}/replace", ['find' => '(price)', 'replace' => ''])
            ->assertOk()->assertJsonPath('replaced', 1)->assertJsonPath('transcript.segments.0.text', 'قیمت ٹھیک ہے');
        $this->postJson("/api/transcripts/{$this->transcript->id}/replace", ['find' => 'قیمت', 'replace' => 'دام', 'whole_word' => true])
            ->assertOk()->assertJsonPath('replaced', 1);
    });

    it('needs something to find', function () {
        $this->postJson("/api/transcripts/{$this->transcript->id}/replace", ['find' => '', 'replace' => 'x'])->assertUnprocessable();
    });
});

describe('restoring the original', function () {
    it('puts the provider text back and clears the edited mark', function () {
        $this->patchJson("/api/transcripts/{$this->transcript->id}", ['edits' => [['index' => 0, 'text' => 'Changed.']]])->assertOk();

        $response = $this->postJson("/api/transcripts/{$this->transcript->id}/restore")->assertOk();

        expect($response->json('segments.0.text'))->toBe('Hello Acme.')
            ->and($response->json('segments.0.words'))->toHaveCount(1)
            ->and($response->json('edited_at'))->toBeNull();
        expect(TranscriptRevision::query()->where('source', 'restore')->count())->toBe(1);
    });

    it('leaves a never-edited transcript alone', function () {
        $this->postJson("/api/transcripts/{$this->transcript->id}/restore")->assertOk()->assertJsonPath('segments.0.text', 'Hello Acme.');
        expect(TranscriptRevision::query()->count())->toBe(0);
    });

    it('keeps the original and the newest fifty revisions', function () {
        $editor = app(TranscriptEditor::class);
        for ($i = 0; $i < TranscriptEditor::MAX_REVISIONS + 5; $i++) {
            $editor->edit($this->transcript->fresh(), [0 => "Version {$i}"]);
        }

        expect(TranscriptRevision::query()->where('source', 'original')->count())->toBe(1)
            ->and(TranscriptRevision::query()->where('source', 'edit')->count())->toBe(TranscriptEditor::MAX_REVISIONS);
        $this->postJson("/api/transcripts/{$this->transcript->id}/restore")->assertOk()->assertJsonPath('segments.0.text', 'Hello Acme.');
    });
});

describe('speaker names', function () {
    it('renames speakers for the session and shows them in the session detail', function () {
        $this->putJson("/api/sessions/{$this->session->id}/speakers", ['names' => ['Client' => 'Jane (Acme)', 'Me' => 'Sam']])
            ->assertOk()->assertJsonPath('speaker_names.Client', 'Jane (Acme)');

        $this->getJson("/api/sessions/{$this->session->id}")->assertOk()
            ->assertJsonPath('speaker_names.Client', 'Jane (Acme)')
            ->assertJsonPath('speaker_names.Me', 'Sam');
    });

    it('remembers names for the client so its next recording uses them', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id]);
        $this->session->update(['client_id' => $client->id]);
        $this->putJson("/api/sessions/{$this->session->id}/speakers", ['names' => ['Client' => 'Jane'], 'remember_for_client' => true])->assertOk();

        $next = CaptureSession::factory()->create(['user_id' => $this->device->user_id, 'device_id' => $this->device->id, 'client_id' => $client->id]);
        $this->getJson("/api/sessions/{$next->id}")->assertOk()->assertJsonPath('speaker_names.Client', 'Jane');
        expect($client->fresh()->speaker_names)->toBe(['Client' => 'Jane']);
    });

    it('lets a session override the client and a blank name remove the rename', function () {
        $client = Client::factory()->create(['user_id' => $this->device->user_id, 'speaker_names' => ['Client' => 'Jane']]);
        $this->session->update(['client_id' => $client->id]);

        $this->putJson("/api/sessions/{$this->session->id}/speakers", ['names' => ['Client' => 'Omar']])->assertOk()->assertJsonPath('speaker_names.Client', 'Omar');
        $this->putJson("/api/sessions/{$this->session->id}/speakers", ['names' => ['Client' => '']])->assertOk()->assertJsonPath('speaker_names.Client', 'Jane');
        expect($this->session->fresh()->speaker_names)->toBeNull();
    });

    it('validates names and hides other owners\' sessions', function () {
        $this->putJson("/api/sessions/{$this->session->id}/speakers", ['names' => ['Client' => str_repeat('n', 61)]])->assertUnprocessable();
        $this->putJson("/api/sessions/{$this->session->id}/speakers", ['names' => []])->assertUnprocessable();
        [$other] = $this->enroll();
        $foreign = CaptureSession::factory()->create(['user_id' => $other->user_id, 'device_id' => $other->id]);
        $this->putJson("/api/sessions/{$foreign->id}/speakers", ['names' => ['Me' => 'x']])->assertNotFound();
    });

    it('answers an empty object when nobody was renamed', function () {
        expect($this->getJson("/api/sessions/{$this->session->id}")->assertOk()->getContent())->toContain('"speaker_names":{}');
    });
});

it('rolls the editing migration back and forth', function () {
    $migration = 'database/migrations/2026_10_27_000001_create_transcript_revisions_table.php';
    Artisan::call('migrate:rollback', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('transcript_revisions'))->toBeFalse()
        ->and(Schema::hasColumn('capture_sessions', 'speaker_names'))->toBeFalse()
        ->and(Schema::hasColumn('transcripts', 'edited_at'))->toBeFalse();

    Artisan::call('migrate', ['--path' => $migration, '--force' => true]);
    expect(Schema::hasTable('transcript_revisions'))->toBeTrue()
        ->and(Schema::hasColumn('clients', 'speaker_names'))->toBeTrue();
});
