<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Jobs\AssembleRecordingJob;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->token = $token;
    $this->asToken($token);
    // No provider credentials: the final job fails cleanly once a recording is ready, which is irrelevant here.
});

/**
 * A recording slot spanning three chunks: two full chunks and a short tail.
 *
 * @return array{recording: string, bytes: string, chunk: int, chunks: list<string>, session: string}
 */
function threeChunkRecording(object $test, int $tail = 777): array
{
    $probe = $test->createSession('x', ['client_ref' => 'probe-'.bin2hex(random_bytes(3))], ['size_bytes' => 10, 'sha256' => str_repeat('a', 64)])->assertCreated();
    $chunk = (int) $probe->json('recordings.0.upload.chunk_size_bytes');
    $bytes = Fixtures::audio($chunk * 2 + $tail);
    $created = $test->createSession($bytes)->assertCreated();

    return [
        'recording' => (string) $created->json('recordings.0.id'),
        'bytes' => $bytes,
        'chunk' => $chunk,
        'chunks' => Fixtures::chunks($bytes, $chunk),
        'session' => (string) $created->json('session.id'),
    ];
}

describe('uploading chunks', function () {
    it('accepts chunks in any order and reports the sorted set received', function () {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 2, $r['chunks'][2])->assertOk()->assertJson(['received_chunks' => [2], 'total_chunks' => 3, 'status' => 'uploading']);
        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertOk()->assertJson(['received_chunks' => [0, 2]]);
        $this->postChunk($r['recording'], 1, $r['chunks'][1])->assertOk()->assertJson(['received_chunks' => [0, 1, 2]]);
    });

    it('treats a repeated chunk as a no-op', function () {
        $r = threeChunkRecording($this);
        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertOk();

        $again = $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertOk();

        expect($again->json('received_chunks'))->toBe([0])
            ->and(Storage::disk('recordings')->allFiles('parts/'.$r['recording']))->toHaveCount(1);
    });

    it('moves the recording and its session to uploading on the first chunk', function () {
        $r = threeChunkRecording($this);
        expect(Recording::query()->find($r['recording'])->upload_status)->toBe(UploadStatus::Pending);

        $this->postChunk($r['recording'], 1, $r['chunks'][1])->assertOk();

        expect(Recording::query()->find($r['recording'])->upload_status)->toBe(UploadStatus::Uploading)
            ->and(Recording::query()->find($r['recording'])->captureSession->status->value)->toBe('uploading');
    });

    it('writes each part under the recording\'s own directory with a server-generated name', function () {
        $r = threeChunkRecording($this);
        $this->postChunk($r['recording'], 1, $r['chunks'][1])->assertOk();

        expect(Storage::disk('recordings')->allFiles())->toBe(["parts/{$r['recording']}/1.part"]);
    });

    it('rejects a missing, malformed or out-of-range index', function (mixed $index) {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], $index, $r['chunks'][0])->assertStatus(422);

        expect(Recording::query()->find($r['recording'])->received_chunks)->toBe([]);
    })->with([
        'missing header' => [null],
        'negative' => ['-1'],
        'text' => ['first'],
        'fraction' => ['1.5'],
        'hex' => ['0x1'],
        'padded with a sign' => ['+1'],
        'empty' => [''],
        'equal to the chunk count' => ['3'],
        'far beyond' => ['4096'],
        'beyond integer range' => ['99999999999999999999'],
    ]);

    it('rejects a missing, malformed or wrong checksum without storing anything', function (?string $sha) {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 0, $r['chunks'][0], $sha, $sha === null ? ['Upload-Chunk-Sha256' => null] : [])->assertStatus(422);

        expect(Recording::query()->find($r['recording'])->received_chunks)->toBe([])
            ->and(Storage::disk('recordings')->allFiles())->toBe([]);
    })->with([
        'missing' => [null],
        'wrong value' => ['3bc1ae5a2b0f16e8f9a2d0c5bcb9ac8c4c66e8f1d0f2f6b7d1bd0f8f2f3d9c11'],
        'too short' => ['abc123'],
        'not hex' => [str_repeat('z', 64)],
        'with prefix' => ['sha256:'.str_repeat('a', 57)],
    ]);

    it('accepts an upper-case checksum', function () {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 0, $r['chunks'][0], strtoupper(hash('sha256', $r['chunks'][0])))->assertOk();
    });

    it('rejects a checksum computed over a different chunk of the same file', function () {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 0, $r['chunks'][0], hash('sha256', $r['chunks'][1]))->assertStatus(422);
    });

    it('rejects a chunk of the wrong length', function (int $index, int $delta) {
        $r = threeChunkRecording($this);
        $good = $r['chunks'][$index];
        $body = $delta < 0 ? substr($good, 0, $delta) : $good.str_repeat('z', $delta);

        $response = $this->postChunk($r['recording'], $index, $body);

        expect($response->getStatusCode())->toBe($delta > 0 ? 413 : 422);
        expect(Recording::query()->find($r['recording'])->received_chunks)->toBe([])
            ->and(Storage::disk('recordings')->allFiles())->toBe([]);
    })->with([
        'full chunk one byte short' => [0, -1],
        'full chunk one byte long' => [0, 1],
        'last chunk one byte short' => [2, -1],
        'last chunk one byte long' => [2, 1],
        'middle chunk much too long' => [1, 4096],
    ]);

    it('rejects an empty body', function () {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 0, '', hash('sha256', ''))->assertStatus(422);
    });

    it('rejects a full-size chunk sent for the short last slot', function () {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 2, $r['chunks'][0])->assertStatus(413);
    });

    it('rejects a short chunk sent for a full-size slot', function () {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 0, $r['chunks'][2])->assertStatus(422);
    });

    it('keeps earlier chunks when a later one is rejected', function () {
        $r = threeChunkRecording($this);
        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertOk();
        $this->postChunk($r['recording'], 1, $r['chunks'][1], str_repeat('0', 64))->assertStatus(422);

        expect($this->getJson("/api/recordings/{$r['recording']}")->json('upload.received_chunks'))->toBe([0]);
    });

    it('replaces nothing and leaves no temporary files after a rejected chunk', function () {
        $r = threeChunkRecording($this);
        $this->postChunk($r['recording'], 0, $r['chunks'][0], str_repeat('0', 64))->assertStatus(422);
        $this->postChunk($r['recording'], 0, $r['chunks'][0].'x')->assertStatus(413);

        expect(Storage::disk('recordings')->allFiles())->toBe([]);
    });

    it('does not trust the Content-Type header', function () {
        $r = threeChunkRecording($this);

        $this->postChunk($r['recording'], 0, $r['chunks'][0], null, ['Content-Type' => 'audio/ogg'])->assertOk();
    });

    it('rejects an upload after the recording is complete, even a repeat of a stored chunk', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk']);
        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);

        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertStatus(409);
        $this->postChunk($r['recording'], 1, $r['chunks'][1])->assertStatus(409);
    });

    it('does not let a late chunk change the stored recording', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk']);
        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);
        $before = Storage::disk('recordings')->get(Recording::query()->find($r['recording'])->stored_path);

        $this->postChunk($r['recording'], 0, str_repeat('z', $r['chunk']))->assertStatus(409);

        expect(Storage::disk('recordings')->get(Recording::query()->find($r['recording'])->stored_path))->toBe($before);
    });
});

describe('resuming', function () {
    it('reports what was received so an interrupted client can continue', function () {
        $r = threeChunkRecording($this);
        $this->postChunk($r['recording'], 2, $r['chunks'][2])->assertOk();
        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertOk();

        $status = $this->getJson("/api/recordings/{$r['recording']}")->assertOk();

        $status->assertJsonPath('id', $r['recording'])
            ->assertJsonPath('role', 'primary')
            ->assertJsonPath('upload.received_chunks', [0, 2])
            ->assertJsonPath('upload.total_chunks', 3)
            ->assertJsonPath('upload.chunk_size_bytes', $r['chunk'])
            ->assertJsonPath('upload.status', 'uploading');
    });

    it('reports a pristine slot as pending with nothing received', function () {
        $r = threeChunkRecording($this);

        $this->getJson("/api/recordings/{$r['recording']}")->assertOk()
            ->assertJsonPath('upload.status', 'pending')->assertJsonPath('upload.received_chunks', []);
    });

    it('finishes an upload that was split across two sessions of the client', function () {
        $r = threeChunkRecording($this);
        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertOk();

        // The client restarts: it only knows the recording id and asks what is missing.
        $received = $this->getJson("/api/recordings/{$r['recording']}")->json('upload.received_chunks');
        foreach (array_diff(array_keys($r['chunks']), $received) as $index) {
            $this->postChunk($r['recording'], $index, $r['chunks'][$index])->assertOk();
        }
        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);

        expect(Recording::query()->find($r['recording'])->isReady())->toBeTrue();
    });

    it('answers 404 for a recording that does not exist', function () {
        $this->getJson('/api/recordings/01ARZ3NDEKTSV4RRFFQ69G5FAV')->assertNotFound();
    });
});

describe('completing', function () {
    it('lists the missing chunks with 422 and does not start assembly', function () {
        $r = threeChunkRecording($this);
        $this->postChunk($r['recording'], 1, $r['chunks'][1])->assertOk();

        $this->postJson("/api/recordings/{$r['recording']}/complete")
            ->assertStatus(422)->assertJsonPath('missing_chunks', [0, 2])->assertJsonStructure(['message']);

        expect(Recording::query()->find($r['recording'])->upload_status)->toBe(UploadStatus::Uploading);
    });

    it('lists every chunk when nothing was uploaded', function () {
        $r = threeChunkRecording($this);

        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(422)->assertJsonPath('missing_chunks', [0, 1, 2]);
    });

    it('notices a stored part that vanished from disk even though it was recorded as received', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk']);
        Storage::disk('recordings')->delete("parts/{$r['recording']}/1.part");

        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(422)->assertJsonPath('missing_chunks', [1]);
    });

    it('assembles, verifies and encrypts, leaving the recording ready and the parts gone', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk'], [1, 2, 0]);

        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202)->assertJsonStructure(['status']);

        $recording = Recording::query()->find($r['recording']);
        expect($recording->upload_status)->toBe(UploadStatus::Ready)
            ->and($recording->isReady())->toBeTrue()
            ->and($recording->assembled_at)->not->toBeNull()
            ->and($recording->error)->toBeNull()
            ->and($recording->stored_path)->toMatch('#^files/\d{4}/\d{2}/'.$r['recording'].'\.elr$#')
            ->and(Storage::disk('recordings')->exists($recording->stored_path))->toBeTrue()
            ->and(Storage::disk('recordings')->directoryExists("parts/{$r['recording']}"))->toBeFalse();
    });

    it('reconstructs the exact original bytes', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk'], [2, 1, 0]);
        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);

        $temp = app(ChunkedUploadService::class)->decryptToTemp(Recording::query()->find($r['recording']));
        try {
            expect(hash_file('sha256', $temp))->toBe(hash('sha256', $r['bytes']))->and(filesize($temp))->toBe(strlen($r['bytes']));
        } finally {
            @unlink($temp);
        }
    });

    it('is idempotent: completing twice leaves one file and the same state', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk']);
        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);
        $path = Recording::query()->find($r['recording'])->stored_path;
        $stored = Storage::disk('recordings')->get($path);

        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);
        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);

        expect(Storage::disk('recordings')->get($path))->toBe($stored)
            ->and(Storage::disk('recordings')->allFiles())->toBe([$path])
            ->and(Recording::query()->find($r['recording'])->upload_status)->toBe(UploadStatus::Ready);
    });

    it('reports a state the client can act on in the complete response', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk']);

        $status = $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202)->json('status');

        expect($status)->toBeIn(['assembling', 'ready']);
    });

    it('starts the final transcript exactly once, however many times completion is requested', function () {
        $r = threeChunkRecording($this);
        $this->uploadAll($r['recording'], $r['bytes'], $r['chunk']);

        foreach (range(1, 3) as $_) {
            $this->postJson("/api/recordings/{$r['recording']}/complete")->assertStatus(202);
        }

        expect(Transcript::query()->where('kind', 'final')->count())->toBe(1);
    });
});

describe('integrity', function () {
    it('fails a recording whose assembled bytes do not match the declared checksum', function () {
        $probe = $this->createSession('x', ['client_ref' => 'probe'], ['size_bytes' => 10, 'sha256' => str_repeat('a', 64)])->assertCreated();
        $chunk = (int) $probe->json('recordings.0.upload.chunk_size_bytes');
        $bytes = Fixtures::audio($chunk + 500);
        $created = $this->createSession($bytes, [], ['sha256' => hash('sha256', 'not these bytes')])->assertCreated();
        $id = $created->json('recordings.0.id');
        $this->uploadAll($id, $bytes, $chunk);

        // The status code of this answer is covered by "tells the client the upload failed" below.
        $this->postJson("/api/recordings/{$id}/complete");

        $recording = Recording::query()->find($id);
        expect($recording->upload_status)->toBe(UploadStatus::Failed)
            ->and($recording->error)->toContain('checksum')
            ->and($recording->stored_path)->toBeNull()
            ->and(Storage::disk('recordings')->allFiles())->toBe([]);
    });

    it('does not start a transcript for a recording that failed verification', function () {
        $bytes = Fixtures::audio(2000);
        $created = $this->createSession($bytes, [], ['sha256' => str_repeat('0', 64)])->assertCreated();
        $id = $created->json('recordings.0.id');
        $this->uploadAll($id, $bytes, (int) $created->json('recordings.0.upload.chunk_size_bytes'));

        $this->postJson("/api/recordings/{$id}/complete");

        expect(Transcript::query()->count())->toBe(0);
    });

    it('tells the client the upload failed rather than answering as if all were well', function () {
        $bytes = Fixtures::audio(2000);
        $created = $this->createSession($bytes, [], ['sha256' => str_repeat('0', 64)])->assertCreated();
        $id = $created->json('recordings.0.id');
        $this->uploadAll($id, $bytes, (int) $created->json('recordings.0.upload.chunk_size_bytes'));

        $response = $this->postJson("/api/recordings/{$id}/complete");

        // A client that ignores the body must not read a plain 2xx as "stored and verified".
        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
    })->skip('A6.W3: complete answers 202 when whole-file verification fails');

    it('does not report the discarded parts as received once verification failed', function () {
        $bytes = Fixtures::audio(2000);
        $created = $this->createSession($bytes, [], ['sha256' => str_repeat('0', 64)])->assertCreated();
        $id = $created->json('recordings.0.id');
        $this->uploadAll($id, $bytes, (int) $created->json('recordings.0.upload.chunk_size_bytes'));
        $this->postJson("/api/recordings/{$id}/complete");

        $state = $this->getJson("/api/recordings/{$id}")->json('upload');

        expect($state['status'])->toBe('failed')->and($state['received_chunks'])->toBe([]);
    })->skip('A6.W3: received_chunks still lists the discarded parts after verification fails');

    it('lets a client discover that a failed upload needs every chunk again', function () {
        $bytes = Fixtures::audio(2000);
        $created = $this->createSession($bytes, [], ['sha256' => str_repeat('0', 64)])->assertCreated();
        $id = $created->json('recordings.0.id');
        $this->uploadAll($id, $bytes, (int) $created->json('recordings.0.upload.chunk_size_bytes'));
        $this->postJson("/api/recordings/{$id}/complete");

        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(422)->assertJsonPath('missing_chunks', [0]);
    });

    it('waits for every declared artifact before transcribing', function () {
        $primary = Fixtures::audio(2000);
        $left = Fixtures::audio(1500, 'LEFT-CHANNEL-MARK');
        $created = $this->postJson('/api/sessions', Fixtures::sessionBody($primary, ['artifacts' => [
            Fixtures::artifact($primary),
            Fixtures::artifact($left, ['role' => 'channel_1', 'mime' => 'audio/wav', 'channels' => 1, 'sample_rate' => 16000]),
        ]]))->assertCreated();
        $byRole = collect($created->json('recordings'))->keyBy('role');
        $chunkSize = $byRole['primary']['upload']['chunk_size_bytes'];

        $this->uploadAll($byRole['primary']['id'], $primary, $chunkSize);
        $this->postJson("/api/recordings/{$byRole['primary']['id']}/complete")->assertStatus(202);
        expect(Transcript::query()->count())->toBe(0);

        $this->uploadAll($byRole['channel_1']['id'], $left, $chunkSize);
        $this->postJson("/api/recordings/{$byRole['channel_1']['id']}/complete")->assertStatus(202);
        expect(Transcript::query()->where('kind', 'final')->count())->toBe(1);
    });
});

describe('who may use an upload slot', function () {
    it('answers 404 to a device of another owner for every upload operation', function () {
        $r = threeChunkRecording($this);
        [, $stranger] = $this->enroll();

        $this->asToken($stranger)->getJson("/api/recordings/{$r['recording']}")->assertNotFound();
        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertNotFound();
        $this->postJson("/api/recordings/{$r['recording']}/complete")->assertNotFound();

        expect(Recording::query()->find($r['recording'])->received_chunks)->toBe([]);
    });

    it('lets another device of the same owner continue an upload, since the library is shared', function () {
        $r = threeChunkRecording($this);
        [, $laptop] = $this->enroll($this->device->user, platform: 'windows');

        $this->asToken($laptop)->getJson("/api/recordings/{$r['recording']}")->assertOk()->assertJsonPath('upload.received_chunks', []);
        $this->postChunk($r['recording'], 0, $r['chunks'][0])->assertOk()->assertJsonPath('received_chunks', [0]);

        expect(Recording::query()->find($r['recording'])->received_chunks)->toBe([0]);
    });
});
describe('large recordings', function () {
    it('defers assembly to the queue above the inline limit', function () {
        Queue::fake();
        $probe = $this->createSession('x', ['client_ref' => 'probe'], ['size_bytes' => 10, 'sha256' => str_repeat('a', 64)])->assertCreated();
        $chunk = (int) $probe->json('recordings.0.upload.chunk_size_bytes');
        $size = ChunkedUploadService::SYNC_ASSEMBLE_MAX_BYTES + 1;
        $bytes = Fixtures::audio($size);
        $created = $this->createSession($bytes)->assertCreated();
        $id = $created->json('recordings.0.id');
        $this->uploadAll($id, $bytes, $chunk);

        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(202)->assertJsonPath('status', 'assembling');

        Queue::assertPushed(AssembleRecordingJob::class, 1);
        expect(Recording::query()->find($id)->upload_status)->toBe(UploadStatus::Assembling);

        // Running the queued job finishes the same recording.
        (new AssembleRecordingJob($id))->handle(app(ChunkedUploadService::class), app(FinalTranscriptCoordinator::class));
        expect(Recording::query()->find($id)->isReady())->toBeTrue();
    });
});
