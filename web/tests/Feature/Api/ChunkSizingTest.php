<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

/** A recording slot for $size bytes split into $chunk byte chunks, created the way the API creates it. */
function slotFor(int $size, int $chunk): Recording
{
    $session = CaptureSession::factory()->create();

    return app(ChunkedUploadService::class)->createRecording($session, Fixtures::artifact('x', ['size_bytes' => $size, 'sha256' => str_repeat('a', 64)]), $chunk);
}

describe('chunk boundaries', function () {
    it('splits every size into chunks that add up to exactly the declared size', function (int $size, int $chunk) {
        $recording = slotFor($size, $chunk);
        $service = app(ChunkedUploadService::class);

        $lengths = array_map(fn (int $i) => $service->expectedChunkLength($recording, $i), range(0, $recording->total_chunks - 1));

        expect(array_sum($lengths))->toBe($size)
            ->and(max($lengths))->toBeLessThanOrEqual($chunk)
            ->and(min($lengths))->toBeGreaterThan(0)
            ->and($recording->total_chunks)->toBe((int) ceil($size / $chunk));
        foreach (array_slice($lengths, 0, -1) as $length) {
            expect($length)->toBe($chunk);
        }
    })->with([
        'one byte' => [1, 262144],
        'under a chunk' => [262143, 262144],
        'exactly a chunk' => [262144, 262144],
        'a chunk and a byte' => [262145, 262144],
        'exactly three' => [786432, 262144],
        'three and a bit' => [786433, 262144],
        'a large recording' => [2_000_000_001, 5_242_880],
        'odd chunk size' => [1000, 7],
    ]);

    it('always plans at least one chunk', function () {
        expect(slotFor(1, 5_242_880)->total_chunks)->toBe(1);
    });

    it('stays within the integer column for the largest permitted recording', function () {
        $recording = slotFor(2 * 1024 * 1024 * 1024, 5 * 1024 * 1024);

        expect($recording->total_chunks)->toBe(410)->and($recording->fresh()->total_chunks)->toBe(410);
    });
});

describe('the advertised chunk size', function () {
    it('is a multiple of 256 KiB and never above the configured maximum', function () {
        $size = ChunkedUploadService::advertisedChunkSize();

        expect($size % (256 * 1024))->toBe(0)->and($size)->toBeGreaterThanOrEqual(256 * 1024)->toBeLessThanOrEqual(5 * 1024 * 1024);
    });

    it('follows a lower configured maximum', function () {
        config(['echoledger.max_chunk_bytes' => 1024 * 1024]);

        expect(ChunkedUploadService::advertisedChunkSize())->toBe(1024 * 1024);
    });

    it('never goes below the 256 KiB floor', function () {
        config(['echoledger.max_chunk_bytes' => 1000]);

        expect(ChunkedUploadService::advertisedChunkSize())->toBe(256 * 1024);
    });

    it('is stable between calls within a request', function () {
        expect(ChunkedUploadService::advertisedChunkSize())->toBe(ChunkedUploadService::advertisedChunkSize());
    });
});
