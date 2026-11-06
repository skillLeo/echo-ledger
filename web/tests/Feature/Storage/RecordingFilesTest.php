<?php

declare(strict_types=1);

use App\Jobs\AssembleRecordingJob;
use App\Models\Recording;
use App\Models\StorageDeletion;
use App\Services\RecordingCipher;
use App\Services\Storage\RecordingFiles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;
use Symfony\Component\Finder\Finder;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;
use Tests\Feature\Storage\Support\FakeS3Server;

uses(InteractsWithDeviceApi::class);

/*
 * The default stays the server's own disk, exactly as before RECORDINGS_DRIVER=s3 existed, and nothing outside
 * RecordingFiles takes a local path of the recordings disk.
 */

describe('on the server\'s own disk', function () {
    beforeEach(function () {
        config(['echoledger.max_chunk_bytes' => 256 * 1024]);
        [$this->device, $this->token] = $this->enroll(platform: 'android');
        $this->asToken($this->token);
        $this->files = app(RecordingFiles::class);
    });

    it('knows it is local and works in place, without copies', function () {
        $plain = RecordingFiles::tempPath('source');
        file_put_contents($plain, 'SECRET');
        $bytes = $this->files->encryptInto($plain, 'files/a/b.elr', 'id-1');
        $stored = Storage::disk('recordings')->path('files/a/b.elr');
        @unlink($plain);

        expect($this->files->isLocal())->toBeTrue()
            ->and(is_file($stored))->toBeTrue()
            ->and($bytes)->toBe((int) filesize($stored))
            ->and((string) file_get_contents($stored))->toStartWith(RecordingCipher::MAGIC)->not->toContain('SECRET')
            ->and($this->files->withLocalCopy('files/a/b.elr', fn (string $path): string => $path))->toBe($stored);

        $target = RecordingFiles::tempPath('out');
        expect($this->files->decryptToFile('files/a/b.elr', $target, 'id-1'))->toBe(6)
            ->and(file_get_contents($target))->toBe('SECRET');
        @unlink($target);
    });

    it('keeps upload parts as they came and finishes them with a rename beside them', function () {
        $audio = Fixtures::audio(600_000, 'LOCAL-MARKER');
        $created = $this->createSession($audio)->assertSuccessful();
        $id = (string) $created->json('recordings.0.id');
        $chunks = Fixtures::chunks($audio, (int) $created->json('recordings.0.upload.chunk_size_bytes'));
        $this->postChunk($id, 0, $chunks[0])->assertSuccessful();

        expect(Storage::disk('recordings')->get("parts/{$id}/0.part"))->toBe($chunks[0])
            ->and($this->files->partHead("parts/{$id}/0.part", 4))->toBe('OggS')
            ->and($this->files->partHead("parts/{$id}/9.part", 4))->toBe('')
            ->and($this->files->namesIn("parts/{$id}"))->toBe(['0.part'])
            ->and($this->files->namesIn('parts/none'))->toBe([])
            ->and(dirname($this->files->partWorkPath("parts/{$id}/1.part")))->toBe(Storage::disk('recordings')->path("parts/{$id}"))
            ->and($this->workFiles())->toBe([]);
    });

    it('still reports the disk\'s numbers and asks nothing of a bucket', function () {
        $space = $this->files->diskSpace();

        expect($space['free'])->toBeGreaterThan(0)->and($space['total'])->toBeGreaterThan(0)
            ->and($this->files->retryErasures())->toBe(0);
    });

    it('erases a missing file quietly, with nothing to retry', function () {
        $this->files->erase(['files/none.elr', null]);
        $this->files->erase(null);
        $this->files->eraseFolder('parts/none');

        expect(StorageDeletion::query()->count())->toBe(0);
    });

    it('tells the device of a failed assembly at once, without waiting between tries', function () {
        expect((new AssembleRecordingJob('NONE'))->backoff())->toBe([]);
    });

    it('passes the storage check and leaves nothing', function () {
        expect(Artisan::call('recordings:check-storage'))->toBe(0)
            ->and(Artisan::output())->toContain("the server's own disk")->toContain('works');
        expect(Storage::disk('recordings')->allFiles())->toBe([]);
    });
});

describe('the storage check on a bucket', function () {
    beforeEach(function () {
        Sleep::fake();
        $this->bucket = FakeS3Server::install();
    });

    it('passes and leaves nothing', function () {
        expect(Artisan::call('recordings:check-storage'))->toBe(0)
            ->and(Artisan::output())->toContain('object storage')->toContain('works')
            ->and($this->bucket->objects)->toBe([])
            ->and($this->bucket->requests('PUT storage-check/'))->toBe(1);
    });

    it('warns when the bucket keeps old versions, or will not say, and passes all the same', function () {
        expect(Artisan::call('recordings:check-storage'))->toBe(0);
        expect(Artisan::output())->not->toContain('old versions');

        foreach (['Enabled', 'Suspended', null] as $versioning) {
            $this->bucket->versioning = $versioning;
            expect(Artisan::call('recordings:check-storage'))->toBe(0);
            expect(Artisan::output())->toContain('works')->toContain('old versions')->toContain('docs/DEPLOY_VPS.md');
        }
    });

    it('fails with what the bucket said, never the secret', function () {
        $this->bucket->failNext('PUT', 403);

        expect(Artisan::call('recordings:check-storage'))->toBe(1);
        expect(Artisan::output())->toContain('does not work')->toContain('HTTP 403')->not->toContain(FakeS3Server::SECRET);
    });
});

it('takes no local path of the recordings disk outside RecordingFiles', function () {
    // The downloads page has a disk of its own, always on the server (DownloadsController).
    $allowed = ['Services/Storage/RecordingFiles.php', 'Http/Controllers/DownloadsController.php'];
    $found = [];
    foreach (Finder::create()->files()->in(app_path())->name('*.php') as $file) {
        $relative = str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname());
        if (! in_array($relative, $allowed, true) && preg_match('/(disk\(\'recordings\'\)|\$disk|disk\(\))->path\(/', $file->getContents()) === 1) {
            $found[] = $relative;
        }
    }

    expect($found)->toBe([]);
});

it('keeps Recording::deleteFiles quiet about a recording without files', function () {
    (new Recording(['stored_path' => null]))->forceFill(['id' => 'NONE'])->deleteFiles();

    expect(StorageDeletion::query()->count())->toBe(0);
});
