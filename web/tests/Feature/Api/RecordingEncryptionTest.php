<?php

declare(strict_types=1);

use App\Models\Recording;
use App\Services\ChunkedUploadService;
use App\Services\RecordingCipher;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

const ENC_MARKER = 'PLAINTEXT-MARKER-0042';

beforeEach(function () {
    [, $token] = $this->enroll();
    $this->asToken($token);
});

/** Absolute path of the encrypted file of a recording on the faked disk. */
function storedFile(string $recordingId): string
{
    return Storage::disk('recordings')->path((string) Recording::query()->findOrFail($recordingId)->stored_path);
}

describe('at rest', function () {
    it('never writes the audio to disk in clear', function () {
        $bytes = Fixtures::audio(300_000, ENC_MARKER);
        $flow = $this->uploadRecording($bytes);

        $stored = (string) file_get_contents(storedFile($flow['recording']));

        expect($stored)->not->toContain(ENC_MARKER)->not->toContain('OggS')
            ->and(str_starts_with($stored, RecordingCipher::MAGIC))->toBeTrue()
            ->and($stored)->not->toBe($bytes);
        // Nothing else on the disk carries the plaintext either (parts and temp files are gone).
        foreach (Storage::disk('recordings')->allFiles() as $file) {
            expect(Storage::disk('recordings')->get($file))->not->toContain(ENC_MARKER);
        }
    });

    it('stores the exact size predicted by the secretstream framing', function (int $size) {
        $bytes = Fixtures::audio($size, ENC_MARKER);
        $flow = $this->uploadRecording($bytes);

        $chunks = intdiv($size, RecordingCipher::CHUNK) + 1;
        $expected = 4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES + $size + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES * $chunks;
        $recording = Recording::query()->find($flow['recording']);

        expect(filesize(storedFile($flow['recording'])))->toBe($expected)->and($recording->stored_size_bytes)->toBe($expected);
    })->with([
        'barely audio' => [64],
        'just under the audio floor' => [1023],
        'one cipher chunk less a byte' => [65535],
        'exactly one cipher chunk' => [65536],
        'one cipher chunk and a byte' => [65537],
        'exactly two cipher chunks' => [131072],
        'several chunks' => [300000],
    ]);

    it('round-trips every size to the identical bytes', function (int $size) {
        $bytes = Fixtures::audio($size, ENC_MARKER);
        $flow = $this->uploadRecording($bytes);

        $temp = app(ChunkedUploadService::class)->decryptToTemp(Recording::query()->find($flow['recording']));
        try {
            expect(file_get_contents($temp))->toBe($bytes);
        } finally {
            @unlink($temp);
        }
    })->with([1024, 65535, 65536, 65537, 131072, 262144, 300001]);

    it('produces different ciphertext for identical audio uploaded twice', function () {
        $bytes = Fixtures::audio(5000, ENC_MARKER);
        $first = $this->uploadRecording($bytes);
        $second = $this->uploadRecording($bytes);

        expect(file_get_contents(storedFile($first['recording'])))->not->toBe(file_get_contents(storedFile($second['recording'])));
    });

    it('keeps the plaintext assembly file and temp directory clean after a successful upload', function () {
        $this->uploadRecording(Fixtures::audio(200_000, ENC_MARKER));

        expect($this->workFiles())->toBe([]);
    });

    it('keeps the temp directory clean when verification fails', function () {
        $this->uploadRecording(Fixtures::audio(200_000, ENC_MARKER), [], ['sha256' => str_repeat('0', 64)]);

        expect($this->workFiles())->toBe([]);
    });

    it('names stored files by server-generated ids whatever the client sends', function () {
        $flow = $this->uploadRecording(Fixtures::audio(4000), ['client_ref' => '../../etc/passwd', 'title' => '..\\..\\evil']);

        $path = Recording::query()->find($flow['recording'])->stored_path;
        expect($path)->toMatch('#^files/\d{4}/\d{2}/[0-9a-z]{26}\.elr$#');
        expect(Storage::disk('recordings')->allFiles())->toBe([$path]);
    });

    it('never reveals the storage path or ciphertext location through the API', function () {
        $flow = $this->uploadRecording(Fixtures::audio(4000));
        $stored = Recording::query()->find($flow['recording'])->stored_path;

        $bodies = [
            $this->getJson("/api/recordings/{$flow['recording']}")->getContent(),
            $this->getJson("/api/sessions/{$flow['session']}")->getContent(),
            $this->getJson('/api/library')->getContent(),
            $flow['create']->getContent(),
            $flow['complete']->getContent(),
        ];
        foreach ($bodies as $body) {
            expect($body)->not->toContain($stored)->not->toContain('.elr')->not->toContain('stored_path')->not->toContain('recordings/');
        }
    });

    it('does not derive the recordings key from the application key', function () {
        $flow = $this->uploadRecording(Fixtures::audio(4000, ENC_MARKER));

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        $temp = app(ChunkedUploadService::class)->decryptToTemp(Recording::query()->find($flow['recording']));
        expect(file_get_contents($temp))->toContain(ENC_MARKER);
        @unlink($temp);
    });
});

describe('tamper detection', function () {
    /** Upload a recording and return its id. */
    function encryptedRecording(object $test, int $size = 150_000): array
    {
        $bytes = Fixtures::audio($size, ENC_MARKER);
        $flow = $test->uploadRecording($bytes);

        return [$flow['recording'], $bytes, $flow['session']];
    }

    /** The playback URL of the session, which streams decrypted audio. */
    function playbackUrl(object $test, string $sessionId): string
    {
        return (string) $test->getJson("/api/sessions/{$sessionId}")->assertOk()->json('playback_url');
    }

    it('refuses to decrypt after a single bit flip anywhere in the body', function (int $offset) {
        [$id, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        $path = storedFile($id);
        $bytes = (string) file_get_contents($path);
        $bytes[$offset] = chr(ord($bytes[$offset]) ^ 0x01);
        file_put_contents($path, $bytes);

        $response = $this->get($url);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
        expect($response->getContent())->not->toContain(ENC_MARKER);
        expect(fn () => app(ChunkedUploadService::class)->decryptToTemp(Recording::query()->find($id)))->toThrow(RuntimeException::class);
    })->with([
        'first cipher byte after the header' => [30],
        'middle of the first chunk' => [20_000],
        'second chunk' => [70_000],
        'last chunk' => [149_000],
    ]);

    it('leaves no decrypted temp file behind when decryption fails', function () {
        [$id] = encryptedRecording($this);
        $path = storedFile($id);
        $bytes = (string) file_get_contents($path);
        $bytes[100] = chr(ord($bytes[100]) ^ 0xFF);
        file_put_contents($path, $bytes);

        expect(fn () => app(ChunkedUploadService::class)->decryptToTemp(Recording::query()->find($id)))->toThrow(RuntimeException::class);

        expect($this->workFiles())->toBe([]);
    });

    it('refuses a truncated file', function () {
        [$id, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        $path = storedFile($id);
        file_put_contents($path, substr((string) file_get_contents($path), 0, 100_000));

        $this->get($url)->assertStatus(500);
        expect(fn () => app(ChunkedUploadService::class)->decryptToTemp(Recording::query()->find($id)))->toThrow(RuntimeException::class);
    });

    it('refuses a file with a chunk cut out of the middle', function () {
        [$id, , $session] = encryptedRecording($this, 300_000);
        $url = playbackUrl($this, $session);
        $path = storedFile($id);
        $bytes = (string) file_get_contents($path);
        $frame = RecordingCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        file_put_contents($path, substr($bytes, 0, 28 + $frame).substr($bytes, 28 + 2 * $frame));

        $this->get($url)->assertStatus(500);
    });

    it('refuses a file with its first two chunks swapped', function () {
        [$id, , $session] = encryptedRecording($this, 300_000);
        $url = playbackUrl($this, $session);
        $path = storedFile($id);
        $bytes = (string) file_get_contents($path);
        $frame = RecordingCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
        $swapped = substr($bytes, 0, 28).substr($bytes, 28 + $frame, $frame).substr($bytes, 28, $frame).substr($bytes, 28 + 2 * $frame);
        file_put_contents($path, $swapped);

        $this->get($url)->assertStatus(500);
    });

    it('refuses extra bytes appended after the final chunk', function () {
        [$id, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        $path = storedFile($id);
        file_put_contents($path, random_bytes(40), FILE_APPEND);

        $this->get($url)->assertStatus(500);
    });

    it('refuses a file that is not an EchoLedger recording', function () {
        [$id, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        file_put_contents(storedFile($id), 'RIFF....WAVEfmt this is just a plain wav file');

        $this->get($url)->assertStatus(500);
    });

    it('refuses an empty file', function () {
        [$id, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        file_put_contents(storedFile($id), '');

        $this->get($url)->assertStatus(500);
    });

    it('refuses ciphertext copied from another recording, because the id is bound into it', function () {
        [$first, , $firstSession] = encryptedRecording($this);
        [$second] = encryptedRecording($this);
        $url = playbackUrl($this, $firstSession);
        copy(storedFile($second), storedFile($first));

        $response = $this->get($url);

        expect($response->getStatusCode())->toBe(500)->and($response->getContent())->not->toContain(ENC_MARKER);
    });

    it('refuses to decrypt under a different recordings key', function () {
        [$id, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        config(['echoledger.recordings_key' => RecordingCipher::generateKey()]);

        $this->get($url)->assertStatus(500);
        expect(fn () => app(ChunkedUploadService::class)->decryptToTemp(Recording::query()->find($id)))->toThrow(RuntimeException::class);
    });

    it('fails cleanly, without leaking the key or a trace, when the key is not configured', function () {
        [, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        $key = (string) config('echoledger.recordings_key');
        config(['echoledger.recordings_key' => '']);

        $response = $this->get($url);

        $response->assertStatus(500);
        expect($response->getContent())->not->toContain($key)->not->toContain('vendor\\')->not->toContain('.php');
    });

    it('does not leak the storage path when the file is missing', function () {
        [$id, , $session] = encryptedRecording($this);
        $url = playbackUrl($this, $session);
        $path = storedFile($id);
        unlink($path);

        $response = $this->get($url);

        expect($response->getStatusCode())->toBeGreaterThanOrEqual(400);
        expect($response->getContent())->not->toContain(basename($path))->not->toContain('framework');
    });
});

describe('the cipher itself', function () {
    it('is the dedicated key that is required, not any 32 characters', function () {
        config(['echoledger.recordings_key' => base64_encode('too short')]);

        expect(fn () => RecordingCipher::fromConfig())->toThrow(RuntimeException::class, 'ECHOLEDGER_RECORDINGS_KEY');
    });

    it('rejects a key that is not valid base64', function () {
        config(['echoledger.recordings_key' => '%%%not-base64%%%']);

        expect(fn () => RecordingCipher::fromConfig())->toThrow(RuntimeException::class);
    });
});
