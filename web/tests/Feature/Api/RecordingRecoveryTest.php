<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Exceptions\AssemblyDataFault;
use App\Exceptions\MissingPhpExtension;
use App\Jobs\AssembleRecordingJob;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\ChunkedUploadService;
use App\Services\FinalTranscriptCoordinator;
use App\Services\Health\PhpExtensions;
use App\Services\RecordingCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\UnableToReadFile;
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

    it('discards the parts with a fixed reason when the upload itself is at fault', function () {
        $stuck = stuckAssembling($this);

        (new AssembleRecordingJob($stuck['id']))->failed(new AssemblyDataFault('fopen(C:\\secret\\path\\0.part): Failed to open stream'));

        $recording = Recording::query()->find($stuck['id']);
        expect($recording->upload_status)->toBe(UploadStatus::Failed)
            ->and($recording->received_chunks)->toBe([])
            ->and($recording->error)->toBe('Upload could not be finished. Send the file again.')
            ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeFalse();
    });

    it('keeps the parts when the server itself failed, and the recovery finishes it once the server is fixed (W-INC-1124)', function () {
        $stuck = stuckAssembling($this);

        // What the queue threw for six recordings when the hosting's PHP lost sodium.
        (new AssembleRecordingJob($stuck['id']))->failed(new Error('Undefined constant "SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES"'));

        $recording = Recording::query()->find($stuck['id']);
        expect($recording->upload_status)->toBe(UploadStatus::Assembling)
            ->and($recording->received_chunks)->not->toBe([])
            ->and($recording->error)->toBeNull()
            ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeTrue();
        $this->getJson("/api/recordings/{$stuck['id']}")->assertOk()->assertJsonPath('upload.status', 'assembling');

        recoverRecordings();

        $recording->refresh();
        expect($recording->upload_status)->toBe(UploadStatus::Ready)
            ->and(Transcript::query()->where('kind', 'final')->count())->toBe(1);
        $temp = app(ChunkedUploadService::class)->decryptToTemp($recording);
        expect(hash_file('sha256', $temp))->toBe(hash('sha256', $stuck['bytes']));
        @unlink($temp);
    });

    it('keeps the parts for every fault of the server: an extension, an error in the code, the storage', function (?Throwable $fault) {
        $stuck = stuckAssembling($this);

        (new AssembleRecordingJob($stuck['id']))->failed($fault);

        expect(Recording::query()->find($stuck['id'])->upload_status)->toBe(UploadStatus::Assembling)
            ->and(Storage::disk('recordings')->files("parts/{$stuck['id']}"))->not->toBe([]);
    })->with([
        'a missing extension' => fn () => MissingPhpExtension::named('sodium'),
        'a type error' => fn () => new TypeError('Argument #1 must be of type string, null given'),
        'the storage' => fn () => UnableToReadFile::fromLocation('parts/x/0.part', 'Service Unavailable'),
        'no reason given' => fn () => null,
    ]);

    it('discards the parts at once when a part is missing, without trying again', function () {
        $stuck = stuckAssembling($this);
        Storage::disk('recordings')->delete("parts/{$stuck['id']}/0.part");

        (new AssembleRecordingJob($stuck['id']))->handle(app(ChunkedUploadService::class), app(FinalTranscriptCoordinator::class));

        $recording = Recording::query()->find($stuck['id']);
        expect($recording->upload_status)->toBe(UploadStatus::Failed)
            ->and($recording->received_chunks)->toBe([])
            ->and($recording->error)->toBe(ChunkedUploadService::NOT_FINISHED)
            ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeFalse();
    });

    it('keeps an upload made while PHP lacks sodium and finishes it once sodium is back, with no new upload', function () {
        config(['queue.default' => 'database']);
        app()->instance(PhpExtensions::class, new PhpExtensions(['sodium']));
        $bytes = Fixtures::audio(300_000);
        $id = (string) $this->createSession($bytes)->assertCreated()->json('recordings.0.id');
        $this->uploadAll($id, $bytes, (int) Recording::query()->findOrFail($id)->chunk_size_bytes);

        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(202)->assertJsonPath('status', 'assembling');
        foreach (range(1, 3) as $try) {
            Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0, '--memory' => 4096]);
        }

        expect(DB::table('failed_jobs')->count())->toBe(1)
            ->and(Recording::query()->findOrFail($id)->upload_status)->toBe(UploadStatus::Assembling)
            ->and(Storage::disk('recordings')->files("parts/{$id}"))->toHaveCount(Recording::query()->findOrFail($id)->total_chunks);

        app()->instance(PhpExtensions::class, new PhpExtensions);
        $this->travel(16)->minutes();
        recoverRecordings();
        Artisan::call('queue:work', ['connection' => 'database', '--once' => true, '--tries' => 3, '--sleep' => 0, '--memory' => 4096]);

        $recording = Recording::query()->findOrFail($id);
        expect($recording->upload_status)->toBe(UploadStatus::Ready);
        $temp = app(ChunkedUploadService::class)->decryptToTemp($recording);
        expect(hash_file('sha256', $temp))->toBe(hash('sha256', $bytes));
        @unlink($temp);
    });

    it('gives a fault that never clears 8 spaced rounds, then asks the device for the file again', function () {
        $stuck = stuckAssembling($this);
        // From here PHP lacks sodium for good: every round fails the same way.
        app()->instance(PhpExtensions::class, new PhpExtensions(['sodium']));
        Recording::query()->whereKey($stuck['id'])->update(['assembling_since' => now()->subMinutes(20), 'updated_at' => now()->subMinutes(20)]);
        $recording = fn (): Recording => Recording::query()->findOrFail($stuck['id']);

        $waits = [];
        for ($round = 1; $round <= ChunkedUploadService::RECOVERY_ROUNDS; $round++) {
            // The round is not due a minute early, then it is.
            $wait = $round === 1 ? 0 : ChunkedUploadService::roundWaitMinutes($round - 1);
            if ($wait > 0) {
                $this->travel($wait - 1)->minutes();
                recoverRecordings();
                expect($recording()->recovery_rounds)->toBe($round - 1, "round {$round} came early");
                $this->travel(1)->minutes();
            }
            recoverRecordings();
            $waits[] = $wait;
            if ($round < ChunkedUploadService::RECOVERY_ROUNDS) {
                expect($recording()->recovery_rounds)->toBe($round)
                    ->and($recording()->upload_status)->toBe(UploadStatus::Assembling)
                    ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeTrue();
            }
        }
        expect($waits)->toBe([0, 30, 60, 120, 240, 480, 480, 480]);

        // The eighth round's failure was the last: the parts go and the device is asked for the file.
        expect($recording()->upload_status)->toBe(UploadStatus::Failed)
            ->and($recording()->error)->toBe(ChunkedUploadService::NOT_FINISHED)
            ->and($recording()->received_chunks)->toBe([])
            ->and(Storage::disk('recordings')->directoryExists("parts/{$stuck['id']}"))->toBeFalse();
        $this->getJson("/api/recordings/{$stuck['id']}")->assertOk()->assertJsonPath('upload.status', 'failed');
    });

    it('asks for the file again 48 hours after complete, in the recovery and in the job\'s last failure', function () {
        $byRecovery = stuckAssembling($this);
        Recording::query()->whereKey($byRecovery['id'])->update(['assembling_since' => now()->subHours(49), 'recovery_rounds' => 2, 'updated_at' => now()->subMinutes(20)]);
        recoverRecordings();
        expect(Recording::query()->findOrFail($byRecovery['id'])->upload_status)->toBe(UploadStatus::Failed);

        $byJob = stuckAssembling($this);
        Recording::query()->whereKey($byJob['id'])->update(['assembling_since' => now()->subHours(49)]);
        (new AssembleRecordingJob($byJob['id']))->failed(new TypeError('Argument #1 must be of type string, null given'));
        expect(Recording::query()->findOrFail($byJob['id'])->upload_status)->toBe(UploadStatus::Failed)
            ->and(Storage::disk('recordings')->directoryExists("parts/{$byJob['id']}"))->toBeFalse();
    });

    it('starts the bound again at each complete', function () {
        $bytes = Fixtures::audio(300_000);
        $id = (string) $this->createSession($bytes)->assertCreated()->json('recordings.0.id');
        $this->uploadAll($id, $bytes, (int) Recording::query()->findOrFail($id)->chunk_size_bytes);
        Queue::fake();

        $this->postJson("/api/recordings/{$id}/complete")->assertStatus(202);

        $recording = Recording::query()->findOrFail($id);
        expect($recording->assembling_since?->toIso8601String())->toBe(now()->toIso8601String())
            ->and($recording->recovery_rounds)->toBe(0);
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
