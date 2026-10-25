<?php

declare(strict_types=1);

use App\Enums\UploadStatus;
use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\TempFileSweeper;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $token] = $this->enroll();
    $this->asToken($token);
    $this->work = (string) config('echoledger.tmp_dir');
    mkdir($this->work, 0700, true);
});

/** Writes a file and backdates it by $minutes minutes. */
function agedFile(string $path, int $minutes): string
{
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0700, true);
    }
    file_put_contents($path, random_bytes(512));
    touch($path, time() - $minutes * 60);

    return $path;
}

/** A recording slot with one stored part, backdated by $minutes minutes. */
function partsAged(object $test, int $minutes): Recording
{
    $bytes = Fixtures::audio(3000);
    $created = $test->createSession($bytes, ['client_ref' => 'parts-'.bin2hex(random_bytes(3))])->assertCreated();
    $id = (string) $created->json('recordings.0.id');
    $test->postChunk($id, 0, $bytes)->assertOk();
    $dir = Storage::disk('recordings')->path("parts/{$id}");
    touch($dir.'/0.part', time() - $minutes * 60);
    touch($dir, time() - $minutes * 60);

    return Recording::query()->findOrFail($id);
}

it('removes temp audio older than an hour and keeps younger files and dotfiles', function () {
    $old = agedFile($this->work.'/primary-aaaaaaaaaaaaaaaa.bin', 61);
    $young = agedFile($this->work.'/play-bbbbbbbbbbbbbbbb.bin', 10);
    $keep = agedFile($this->work.'/.gitignore', 60 * 24 * 30);

    $removed = app(TempFileSweeper::class)->sweep();

    expect($removed)->toBe(1)
        ->and(file_exists($old))->toBeFalse()
        ->and(file_exists($young))->toBeTrue()
        ->and(file_exists($keep))->toBeTrue();
});

it('removes the parts of a deleted recording after a day', function () {
    $recording = partsAged($this, 25 * 60);
    Recording::query()->whereKey($recording->id)->delete(); // a row removed without its files

    app(TempFileSweeper::class)->sweep();

    expect(Storage::disk('recordings')->directoryExists("parts/{$recording->id}"))->toBeFalse();
});

it('removes the parts of a failed recording after a day, not before', function () {
    $recording = partsAged($this, 23 * 60);
    Recording::query()->whereKey($recording->id)->update(['upload_status' => UploadStatus::Failed]);

    app(TempFileSweeper::class)->sweep();
    expect(Storage::disk('recordings')->directoryExists("parts/{$recording->id}"))->toBeTrue();

    $dir = Storage::disk('recordings')->path("parts/{$recording->id}");
    touch($dir.'/0.part', time() - 25 * 3600);
    touch($dir, time() - 25 * 3600);
    app(TempFileSweeper::class)->sweep();
    expect(Storage::disk('recordings')->directoryExists("parts/{$recording->id}"))->toBeFalse();
});

it('never removes the parts of an upload that is still running, however old', function () {
    $recording = partsAged($this, 10 * 24 * 60);

    app(TempFileSweeper::class)->sweep();

    expect(Storage::disk('recordings')->exists("parts/{$recording->id}/0.part"))->toBeTrue()
        ->and(app(ChunkedUploadService::class)->missingChunks($recording))->toBe([]);
});

it('removes a half-written chunk file left by a request that died', function () {
    $recording = partsAged($this, 5);
    $dir = Storage::disk('recordings')->path("parts/{$recording->id}");
    $stale = agedFile($dir.'/1.part.abcdef123456.tmp', 90);
    $fresh = agedFile($dir.'/1.part.123456abcdef.tmp', 1);

    app(TempFileSweeper::class)->sweep();

    expect(file_exists($stale))->toBeFalse()->and(file_exists($fresh))->toBeTrue()
        ->and(file_exists($dir.'/0.part'))->toBeTrue();
});

it('runs as part of stt:reconcile', function () {
    $old = agedFile($this->work.'/assemble-cccccccccccccccc.bin', 120);

    Artisan::call('stt:reconcile');

    expect(file_exists($old))->toBeFalse();
});

it('leaves no decrypted copy behind once a playback answer was sent', function () {
    $flow = $this->uploadRecording(Fixtures::audio(5000));
    $url = $this->getJson("/api/sessions/{$flow['session']}")->json('playback_url');
    $this->flushHeaders();

    $this->get($url)->assertOk()->streamedContent();
    $this->withHeaders(['Range' => 'bytes=10-19'])->get($url)->assertStatus(206)->streamedContent();
    $this->withHeaders(['Range' => 'bytes=5000-'])->get($url)->assertStatus(416);

    expect($this->workFiles())->toBe([]);
});
