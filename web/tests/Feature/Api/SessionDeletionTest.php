<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;
use App\Models\WebhookEvent;
use App\Stt\Drivers\AssemblyAiDriver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
});

describe('deleting a finished session', function () {
    beforeEach(function () {
        $this->configureAssemblyAi();
        $this->fakeAssemblyAi('job_1');
        $this->flow = $this->uploadRecording(Fixtures::audio(5000, 'DELETE-ME'), ['client_ref' => 'doomed']);
        $this->session = CaptureSession::query()->findOrFail($this->flow['session']);
        $this->transcript = Transcript::query()->where('capture_session_id', $this->flow['session'])->where('kind', 'final')->sole();
        $this->live = $this->postJson("/api/sessions/{$this->flow['session']}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => [Fixtures::segment(['text' => 'live words'])]])->json('id');
        $this->storedPath = (string) Recording::query()->find($this->flow['recording'])->stored_path;
    });

    it('answers 204 with an empty body', function () {
        $response = $this->deleteJson("/api/sessions/{$this->flow['session']}");

        $response->assertNoContent();
        expect($response->getContent())->toBe('');
    });

    it('turns the session into a tombstone and erases its recordings, transcripts and files at once', function () {
        expect(Storage::disk('recordings')->exists($this->storedPath))->toBeTrue();

        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        $trashed = CaptureSession::withTrashed()->find($this->flow['session']);
        expect($trashed)->not->toBeNull()->and($trashed->trashed())->toBeTrue()
            ->and(Recording::query()->where('capture_session_id', $this->flow['session'])->count())->toBe(0)
            ->and(Transcript::query()->where('capture_session_id', $this->flow['session'])->count())->toBe(0)
            ->and(Storage::disk('recordings')->exists($this->storedPath))->toBeFalse()
            ->and(Storage::disk('recordings')->allFiles())->toBe([]);
    });

    it('keeps the tombstone\'s identity but nothing of its content', function () {
        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        $tombstone = collect($this->getJson('/api/library?since='.urlencode(now()->subHour()->toAtomString()))->json('data'))->firstWhere('id', $this->flow['session']);

        expect($tombstone)->toBe([
            'id' => $this->flow['session'], 'client_ref' => 'doomed', 'deleted' => true,
            'updated_at' => $tombstone['updated_at'],
        ]);
    });

    it('moves the tombstone\'s timestamp forward so every device sees the deletion on its next sync', function () {
        $this->freezeTime();
        $before = $this->session->refresh()->updated_at->getTimestamp();
        $this->travel(10)->minutes();

        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        expect(CaptureSession::withTrashed()->find($this->flow['session'])->updated_at->getTimestamp())->toBeGreaterThan($before);
    });

    it('makes every endpoint that touches the session answer 404', function () {
        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();
        $recording = $this->flow['recording'];

        $this->getJson("/api/sessions/{$this->flow['session']}")->assertNotFound();
        $this->getJson("/api/transcripts/{$this->transcript->id}")->assertNotFound();
        $this->getJson("/api/transcripts/{$this->live}")->assertNotFound();
        $this->getJson("/api/recordings/{$recording}")->assertNotFound();
        $this->postChunk($recording, 0, 'x')->assertNotFound();
        $this->postJson("/api/recordings/{$recording}/complete")->assertNotFound();
        $this->postJson("/api/sessions/{$this->flow['session']}/recordings", Fixtures::artifact('abc', ['role' => 'channel_1']))->assertNotFound();
        $this->postJson("/api/sessions/{$this->flow['session']}/transcripts", ['kind' => 'live', 'provider' => 'assemblyai', 'segments' => []])->assertNotFound();
    });

    it('kills a playback URL that was issued earlier', function () {
        $url = $this->getJson("/api/sessions/{$this->flow['session']}")->json('playback_url');
        $this->get($url)->assertOk();

        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        $this->get($url)->assertNotFound();
    });

    it('drops the session from the plain library listing', function () {
        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        expect($this->getJson('/api/library')->json('data'))->toBe([]);
    });

    it('is not undone by the device retrying its upload', function () {
        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        $this->createSession(Fixtures::audio(5000), ['client_ref' => 'doomed'])->assertOk();

        expect(CaptureSession::query()->count())->toBe(0)->and(Recording::query()->count())->toBe(0)->and(Storage::disk('recordings')->allFiles())->toBe([]);
    });

    it('answers a repeated delete without a server error', function () {
        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();

        $status = $this->deleteJson("/api/sessions/{$this->flow['session']}")->getStatusCode();

        expect($status)->toBeIn([204, 404]);
    });

    it('lets a late provider callback fall through harmlessly', function () {
        $this->deleteJson("/api/sessions/{$this->flow['session']}")->assertNoContent();
        $this->flushHeaders();
        $before = count(Http::recorded());

        $this->postJson(
            '/api/webhooks/stt/assemblyai?rid='.$this->transcript->id,
            ['transcript_id' => 'job_1', 'status' => 'completed'],
            [AssemblyAiDriver::WEBHOOK_HEADER => AssemblyAiDriver::webhookToken('whsec_test_secret', $this->transcript->id)],
        )->assertOk()->assertJsonPath('ignored', 'unknown transcript');

        expect(WebhookEvent::query()->count())->toBe(0)->and(count(Http::recorded()))->toBe($before);
    });
});

describe('deleting a session that is still uploading', function () {
    it('removes the partial upload from disk', function () {
        $bytes = Fixtures::audio(6_000_000);
        $created = $this->createSession($bytes)->assertCreated();
        $recording = $created->json('recordings.0.id');
        $chunk = $created->json('recordings.0.upload.chunk_size_bytes');
        $this->postChunk($recording, 0, substr($bytes, 0, $chunk))->assertOk();
        expect(Storage::disk('recordings')->allFiles())->toHaveCount(1);

        $this->deleteJson('/api/sessions/'.$created->json('session.id'))->assertNoContent();

        expect(Storage::disk('recordings')->allFiles())->toBe([])->and(Storage::disk('recordings')->directoryExists("parts/{$recording}"))->toBeFalse();
    });

    it('removes every artifact of a three-artifact session', function () {
        $primary = Fixtures::audio(3000);
        $left = Fixtures::audio(2000, 'LEFT');
        $right = Fixtures::audio(2100, 'RIGHT');
        $created = $this->postJson('/api/sessions', Fixtures::sessionBody($primary, ['artifacts' => [
            Fixtures::artifact($primary),
            Fixtures::artifact($left, ['role' => 'channel_1', 'channels' => 1, 'mime' => 'audio/wav']),
            Fixtures::artifact($right, ['role' => 'channel_2', 'channels' => 1, 'mime' => 'audio/wav']),
        ]]))->assertCreated();
        foreach ([[$primary, 'primary'], [$left, 'channel_1']] as [$bytes, $role]) {
            $recording = collect($created->json('recordings'))->firstWhere('role', $role);
            $this->uploadAll($recording['id'], $bytes, $recording['upload']['chunk_size_bytes']);
            $this->postJson("/api/recordings/{$recording['id']}/complete")->assertStatus(202);
        }

        $this->deleteJson('/api/sessions/'.$created->json('session.id'))->assertNoContent();

        expect(Recording::query()->count())->toBe(0)->and(Storage::disk('recordings')->allFiles())->toBe([]);
    });
});

describe('what a deletion leaves alone', function () {
    it('keeps other sessions\' files and rows', function () {
        $keep = $this->uploadRecording(Fixtures::audio(4000, 'KEEP'), ['client_ref' => 'keep']);
        $drop = $this->uploadRecording(Fixtures::audio(4000, 'DROP'), ['client_ref' => 'drop']);
        $keptPath = Recording::query()->find($keep['recording'])->stored_path;

        $this->deleteJson("/api/sessions/{$drop['session']}")->assertNoContent();

        expect(Storage::disk('recordings')->allFiles())->toBe([$keptPath])
            ->and(Recording::query()->find($keep['recording'])->isReady())->toBeTrue()
            ->and(array_column($this->getJson('/api/library')->json('data'), 'id'))->toBe([$keep['session']]);
    });

    it('does not touch another owner\'s session, even though the id is valid', function () {
        $foreign = CaptureSession::factory()->create();
        $foreign->transcripts()->create(['kind' => TranscriptKind::Live, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Completed, 'text' => 'theirs']);

        $this->deleteJson("/api/sessions/{$foreign->id}")->assertNotFound();

        expect(CaptureSession::query()->find($foreign->id))->not->toBeNull()->and(Transcript::query()->where('capture_session_id', $foreign->id)->count())->toBe(1);
    });

    it('lets another device of the same owner delete it, since the library is shared', function () {
        $flow = $this->uploadRecording(Fixtures::audio(3000));
        [, $laptop] = $this->enroll($this->device->user, platform: 'windows');

        $this->asToken($laptop)->deleteJson("/api/sessions/{$flow['session']}")->assertNoContent();

        expect(CaptureSession::query()->find($flow['session']))->toBeNull();
    });

    it('answers 404 for an id that never existed', function () {
        $this->deleteJson('/api/sessions/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    });

    it('requires the sessions:write ability', function () {
        $flow = $this->uploadRecording(Fixtures::audio(3000));
        [, $readOnly] = $this->enroll($this->device->user, ['library:read']);

        $this->asToken($readOnly)->deleteJson("/api/sessions/{$flow['session']}")->assertForbidden();

        expect(CaptureSession::query()->find($flow['session']))->not->toBeNull();
    });

    it('tolerates files that are already gone from disk', function () {
        $flow = $this->uploadRecording(Fixtures::audio(3000));
        Storage::disk('recordings')->delete(Recording::query()->find($flow['recording'])->stored_path);

        $this->deleteJson("/api/sessions/{$flow['session']}")->assertNoContent();

        expect(Recording::query()->count())->toBe(0);
    });
});

describe('provider copies', function () {
    it('asks the provider to erase its copy when a session is deleted while its job is in flight', function () {
        $this->configureAssemblyAi();
        $log = [];
        $this->fakeAssemblyAi('job_1', null, $log);
        $flow = $this->uploadRecording(Fixtures::audio(5000), ['client_ref' => 'in-flight']);
        expect(Transcript::query()->where('capture_session_id', $flow['session'])->where('kind', 'final')->sole()->status)->toBe(TranscriptStatus::Submitted);
        expect($log)->toBe(['POST /v2/upload', 'POST /v2/transcript']);

        $this->deleteJson("/api/sessions/{$flow['session']}")->assertNoContent();

        // The audio was uploaded to AssemblyAI and the job is still running there: deleting the session must delete that too.
        expect($log)->toContain('DELETE /v2/transcript/job_1');
    })->skip('A6.W7: deleting a session mid-transcription never deletes the provider-side copy');
});
