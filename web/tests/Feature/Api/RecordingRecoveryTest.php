<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Jobs\AssembleRecordingJob;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use App\Services\RecordingCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->asToken($token);
});

/**
 * Every chunk uploaded and the recording left in `assembling`, as when the request handling `complete` dies after
 * the status change and before the encrypted file is stored.
 *
 * @return array{id: string, bytes: string, chunk: int}
 */
function stuckAssembling(object $test, int $size = 300_000, int $minutesAgo = 20): array
{
    $bytes = Fixtures::audio($size);
    $create = $test->createSession($bytes)->assertCreated();
    $id = (string) $create->json('recordings.0.id');
    $chunk = (int) $create->json('recordings.0.upload.chunk_size_bytes');
    $test->uploadAll($id, $bytes, $chunk);
    Recording::query()->whereKey($id)->update(['upload_status' => UploadStatus::Assembling, 'updated_at' => now()->subMinutes($minutesAgo)]);

    return ['id' => $id, 'bytes' => $bytes, 'chunk' => $chunk];
}

function recoverRecordings(array $options = []): void
{
    Artisan::call('recordings:recover', $options);
}

describe('every part still on disk', function () {
    it('assembles the recording again, verifies it and starts the final transcript', function () {
        $stuck = stuckAssembling($this);

        recoverRecordings();

        $recording = Recording::query()->find($stuck['id']);
        expect($recording->upload_status)->toBe(UploadStatus::Ready)
            ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeFalse()
            ->and(Transcript::query()->where('kind', 'final')->count())->toBe(1);
        $temp = app(ChunkedUploadService::class)->decryptToTemp($recording);
        expect(hash_file('sha256', $temp))->toBe(hash('sha256', $stuck['bytes']));
        @unlink($temp);
    });

    it('queues the assembly instead of running it inside the scheduler', function () {
        Queue::fake();
        $stuck = stuckAssembling($this);

        recoverRecordings();

        Queue::assertPushed(AssembleRecordingJob::class, fn (AssembleRecordingJob $job) => $job->recordingId === $stuck['id']);
        // The waiting period starts again, so the next run does not queue a second copy.
        expect(Recording::query()->find($stuck['id'])->updated_at->gt(now()->subMinute()))->toBeTrue();
        recoverRecordings();
        Queue::assertPushed(AssembleRecordingJob::class, 1);
    });

    it('fixes what the device saw: the next complete answers ready', function () {
        $stuck = stuckAssembling($this);
        $this->postJson("/api/recordings/{$stuck['id']}/complete")->assertStatus(202)->assertJsonPath('status', 'assembling');

        recoverRecordings();

        $this->postJson("/api/recordings/{$stuck['id']}/complete")->assertStatus(202)->assertJsonPath('status', 'ready');
        $this->getJson("/api/recordings/{$stuck['id']}")->assertJsonPath('upload.status', 'ready');
    });
});

describe('parts gone', function () {
    it('keeps an encrypted file that was fully written and verifies', function () {
        $stuck = stuckAssembling($this);
        $recording = Recording::query()->find($stuck['id']);
        $relative = ChunkedUploadService::storedPathFor($recording);
        // The process died after encrypting and deleting the parts, before saving the new state.
        $plain = app(ChunkedUploadService::class)->tempPath('test');
        file_put_contents($plain, $stuck['bytes']);
        Storage::disk('recordings')->makeDirectory(dirname($relative));
        RecordingCipher::fromConfig()->encryptFile($plain, Storage::disk('recordings')->path($relative), $recording->id);
        @unlink($plain);
        Storage::disk('recordings')->deleteDirectory("parts/{$stuck['id']}");

        recoverRecordings();

        $recording->refresh();
        expect($recording->upload_status)->toBe(UploadStatus::Ready)
            ->and($recording->stored_path)->toBe($relative)
            ->and($recording->isReady())->toBeTrue()
            ->and(Transcript::query()->where('kind', 'final')->count())->toBe(1);
    });

    it('fails a recording whose encrypted file was cut short, discarding the file', function () {
        $stuck = stuckAssembling($this);
        $recording = Recording::query()->find($stuck['id']);
        $relative = ChunkedUploadService::storedPathFor($recording);
        $plain = app(ChunkedUploadService::class)->tempPath('test');
        file_put_contents($plain, $stuck['bytes']);
        Storage::disk('recordings')->makeDirectory(dirname($relative));
        $full = Storage::disk('recordings')->path($relative);
        RecordingCipher::fromConfig()->encryptFile($plain, $full, $recording->id);
        @unlink($plain);
        file_put_contents($full, substr((string) file_get_contents($full), 0, 70_000));
        Storage::disk('recordings')->deleteDirectory("parts/{$stuck['id']}");

        recoverRecordings();

        $recording->refresh();
        expect($recording->upload_status)->toBe(UploadStatus::Failed)
            ->and($recording->received_chunks)->toBe([])
            ->and($recording->error)->toBe('Upload could not be finished. Send the file again.')
            ->and(Storage::disk('recordings')->exists($relative))->toBeFalse()
            ->and(Transcript::query()->count())->toBe(0);
    });

    it('fails a recording with neither its parts nor a stored file, so the device sends it again', function () {
        $stuck = stuckAssembling($this);
        Storage::disk('recordings')->delete("parts/{$stuck['id']}/0.part");

        recoverRecordings();

        $state = $this->getJson("/api/recordings/{$stuck['id']}")->assertOk()->json('upload');
        expect($state)->toMatchArray(['status' => 'failed', 'received_chunks' => [], 'error' => 'Upload could not be finished. Send the file again.'])
            ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeFalse();
        $this->postJson("/api/recordings/{$stuck['id']}/complete")->assertStatus(422)->assertJsonPath('code', 'verification_failed');

        // The device starts again from chunk 0 and the upload finishes normally.
        $this->uploadAll($stuck['id'], $stuck['bytes'], $stuck['chunk']);
        $this->postJson("/api/recordings/{$stuck['id']}/complete")->assertStatus(202)->assertJsonPath('status', 'ready');
    });
});

describe('what it leaves alone', function () {
    it('waits until the recording has been assembling for longer than the threshold', function () {
        Queue::fake();
        $recent = stuckAssembling($this, minutesAgo: 14);

        recoverRecordings();
        Queue::assertNothingPushed();
        expect(Recording::query()->find($recent['id'])->upload_status)->toBe(UploadStatus::Assembling);

        recoverRecordings(['--minutes' => 10]);
        Queue::assertPushed(AssembleRecordingJob::class, 1);
    });

    it('skips a recording another process is assembling right now', function () {
        Queue::fake();
        $stuck = stuckAssembling($this);
        $lock = AssembleRecordingJob::lock($stuck['id']);
        expect($lock->get())->toBeTrue();

        recoverRecordings();

        Queue::assertNothingPushed();
        expect(Recording::query()->find($stuck['id'])->upload_status)->toBe(UploadStatus::Assembling);
        $lock->release();
    });

    it('does not touch uploads that are pending, uploading, ready or failed', function (UploadStatus $status) {
        Queue::fake();
        $stuck = stuckAssembling($this);
        Recording::query()->whereKey($stuck['id'])->update(['upload_status' => $status, 'updated_at' => now()->subHour()]);

        recoverRecordings();

        Queue::assertNothingPushed();
        expect(Recording::query()->find($stuck['id'])->upload_status)->toBe($status);
    })->with([UploadStatus::Pending, UploadStatus::Uploading, UploadStatus::Ready, UploadStatus::Failed]);
});

describe('the assembly job', function () {
    it('does nothing while another process holds the assembly lock', function () {
        $stuck = stuckAssembling($this);
        $lock = AssembleRecordingJob::lock($stuck['id']);
        $lock->get();

        (new AssembleRecordingJob($stuck['id']))->handle(app(ChunkedUploadService::class), app(FinalTranscriptCoordinator::class));

        expect(Recording::query()->find($stuck['id'])->upload_status)->toBe(UploadStatus::Assembling);
        $lock->release();
        (new AssembleRecordingJob($stuck['id']))->handle(app(ChunkedUploadService::class), app(FinalTranscriptCoordinator::class));
        expect(Recording::query()->find($stuck['id'])->upload_status)->toBe(UploadStatus::Ready);
    });

    it('discards the parts with a fixed reason when every attempt failed', function () {
        $stuck = stuckAssembling($this);

        (new AssembleRecordingJob($stuck['id']))->failed(new RuntimeException('fopen(C:\\secret\\path\\0.part): Failed to open stream'));

        $recording = Recording::query()->find($stuck['id']);
        expect($recording->upload_status)->toBe(UploadStatus::Failed)
            ->and($recording->received_chunks)->toBe([])
            ->and($recording->error)->toBe('Upload could not be finished. Send the file again.')
            ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeFalse();
    });

    it('leaves a recording that is already ready alone when a late attempt fails', function () {
        $flow = $this->uploadRecording(Fixtures::audio(4000));

        (new AssembleRecordingJob($flow['recording']))->failed(new RuntimeException('late'));

        expect(Recording::query()->find($flow['recording'])->isReady())->toBeTrue();
    });
});

it('is scheduled every five minutes', function () {
    Artisan::call('schedule:list');

    expect(Artisan::output())->toMatch('#\*/5\s+\* \* \* \*\s+php artisan recordings:recover#');
});
