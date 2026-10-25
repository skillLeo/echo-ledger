<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Services\ChunkedUploadService;
use App\Services\CredentialVault;
use App\Services\Data\CompleteOutcome;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

function resourceFrom(string $bytes)
{
    $handle = fopen('php://temp', 'r+');
    fwrite($handle, $bytes);
    rewind($handle);

    return $handle;
}

function makeRecording(int $chunkSize, string $bytes)
{
    $session = CaptureSession::factory()->create();

    return app(ChunkedUploadService::class)->createRecording($session, [
        'role' => 'primary',
        'mime' => 'audio/ogg',
        'codec' => 'opus',
        'channels' => 2,
        'sample_rate' => 16000,
        'size_bytes' => strlen($bytes),
        'sha256' => hash('sha256', $bytes),
        'duration_ms' => 1000,
    ], $chunkSize);
}

beforeEach(function () {
    Storage::fake('recordings');
});

it('accepts chunks out of order and is idempotent on duplicates', function () {
    $uploads = app(ChunkedUploadService::class);
    $bytes = random_bytes(250);
    $recording = makeRecording(100, $bytes);
    $slices = [substr($bytes, 0, 100), substr($bytes, 100, 100), substr($bytes, 200, 50)];

    foreach ([2, 0, 1] as $index) {
        $uploads->storeChunk($recording->fresh(), $index, hash('sha256', $slices[$index]), resourceFrom($slices[$index]));
    }
    // Re-send chunk 0: still exactly three received.
    $recording = $uploads->storeChunk($recording->fresh(), 0, hash('sha256', $slices[0]), resourceFrom($slices[0]));

    expect($recording->received_chunks)->toBe([0, 1, 2]);
    expect($uploads->missingChunks($recording))->toBe([]);
});

it('rejects a chunk whose checksum does not match', function () {
    $uploads = app(ChunkedUploadService::class);
    $bytes = random_bytes(250);
    $recording = makeRecording(100, $bytes);

    expect(fn () => $uploads->storeChunk($recording, 0, hash('sha256', 'wrong'), resourceFrom(substr($bytes, 0, 100))))
        ->toThrow(ValidationException::class);
});

it('rejects a chunk that is the wrong size', function () {
    $uploads = app(ChunkedUploadService::class);
    $bytes = random_bytes(250);
    $recording = makeRecording(100, $bytes);
    $tooLong = substr($bytes, 0, 100).'extra';

    expect(fn () => $uploads->storeChunk($recording, 0, hash('sha256', $tooLong), resourceFrom($tooLong)))
        ->toThrow(HttpException::class);
});

it('reports missing chunks from markComplete, then assembles inline once everything is uploaded', function () {
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_key');
    Http::fake(['*' => Http::response(['id' => 'job_x', 'status' => 'queued', 'upload_url' => 'https://cdn.assemblyai.com/u'], 200)]);
    $uploads = app(ChunkedUploadService::class);
    $bytes = random_bytes(2500); // above the "no audio" guard, below the sync-assemble cap
    $recording = makeRecording(1000, $bytes);
    $slices = [substr($bytes, 0, 1000), substr($bytes, 1000, 1000), substr($bytes, 2000, 500)];

    $uploads->storeChunk($recording->fresh(), 0, hash('sha256', $slices[0]), resourceFrom($slices[0]));
    expect($uploads->markComplete($recording->fresh()))->toEqual(CompleteOutcome::incomplete([1, 2]));

    $uploads->storeChunk($recording->fresh(), 1, hash('sha256', $slices[1]), resourceFrom($slices[1]));
    $uploads->storeChunk($recording->fresh(), 2, hash('sha256', $slices[2]), resourceFrom($slices[2]));

    // Small recording: markComplete assembles + submits the transcript inline (no queue wait).
    expect($uploads->markComplete($recording->fresh()))->toEqual(CompleteOutcome::ready());
    expect($recording->fresh()->upload_status->value)->toBe('ready');
    expect($recording->captureSession->finalTranscript()->first()?->status->value)->toBe('submitted');
});

it('assembles, encrypts and decrypts back to the original bytes', function () {
    $uploads = app(ChunkedUploadService::class);
    $bytes = random_bytes(250);
    $recording = makeRecording(100, $bytes);
    $slices = [substr($bytes, 0, 100), substr($bytes, 100, 100), substr($bytes, 200, 50)];
    foreach ([0, 1, 2] as $index) {
        $uploads->storeChunk($recording->fresh(), $index, hash('sha256', $slices[$index]), resourceFrom($slices[$index]));
    }

    $uploads->assemble($recording->fresh());

    $recording = $recording->fresh();
    expect($recording->isReady())->toBeTrue();
    expect(Storage::disk('recordings')->exists($recording->stored_path))->toBeTrue();
    // Encrypted at rest: the stored ciphertext differs from the plaintext.
    expect(Storage::disk('recordings')->get($recording->stored_path))->not->toBe($bytes);
    // Raw upload parts are gone.
    expect(Storage::disk('recordings')->directoryExists($recording->partsDirectory()))->toBeFalse();

    $temp = $uploads->decryptToTemp($recording);
    expect(hash_file('sha256', $temp))->toBe(hash('sha256', $bytes));
    @unlink($temp);
});

it('fails assembly when the declared checksum does not match the uploaded bytes', function () {
    $uploads = app(ChunkedUploadService::class);
    $bytes = random_bytes(150);
    // Declare a recording whose sha256 is wrong for the bytes we will upload.
    $session = CaptureSession::factory()->create();
    $recording = $uploads->createRecording($session, [
        'role' => 'primary', 'mime' => 'audio/ogg', 'channels' => 1,
        'size_bytes' => 150, 'sha256' => str_repeat('0', 64),
    ], 100);
    foreach ([substr($bytes, 0, 100), substr($bytes, 100, 50)] as $i => $slice) {
        $uploads->storeChunk($recording->fresh(), $i, hash('sha256', $slice), resourceFrom($slice));
    }

    $uploads->assemble($recording->fresh());
    expect($recording->fresh()->upload_status->value)->toBe('failed')
        ->and($recording->fresh()->received_chunks)->toBe([])
        ->and($recording->fresh()->error)->toBe(ChunkedUploadService::VERIFICATION_FAILED)
        ->and(Storage::disk('recordings')->directoryExists($recording->partsDirectory()))->toBeFalse();
    // A repeated complete gives the same answer until new chunks arrive.
    expect($uploads->markComplete($recording->fresh()))->toEqual(CompleteOutcome::failed());
});

it('takes chunks again after a failed verification and starts the received set afresh', function () {
    $uploads = app(ChunkedUploadService::class);
    $bytes = random_bytes(150);
    $session = CaptureSession::factory()->create();
    $recording = $uploads->createRecording($session, [
        'role' => 'primary', 'mime' => 'audio/ogg', 'channels' => 1,
        'size_bytes' => 150, 'sha256' => str_repeat('0', 64),
    ], 100);
    foreach ([substr($bytes, 0, 100), substr($bytes, 100, 50)] as $i => $slice) {
        $uploads->storeChunk($recording->fresh(), $i, hash('sha256', $slice), resourceFrom($slice));
    }
    $uploads->assemble($recording->fresh());

    $again = $uploads->storeChunk($recording->fresh(), 1, hash('sha256', substr($bytes, 100, 50)), resourceFrom(substr($bytes, 100, 50)));

    expect($again->upload_status->value)->toBe('uploading')
        ->and($again->error)->toBeNull()
        ->and($again->received_chunks)->toBe([1])
        ->and($uploads->markComplete($again->fresh()))->toEqual(CompleteOutcome::incomplete([0]));
});
