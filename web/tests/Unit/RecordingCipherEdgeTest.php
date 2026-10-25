<?php

declare(strict_types=1);

use App\Services\RecordingCipher;

const CIPHER_FRAME = RecordingCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
const CIPHER_PREFIX = 4 + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES;

function freshCipher(?string $key = null): RecordingCipher
{
    return new RecordingCipher($key ?? sodium_crypto_secretstream_xchacha20poly1305_keygen());
}

/** The exception a callable throws, or null when it completes. */
function thrownBy(callable $fn): ?Throwable
{
    try {
        $fn();
    } catch (Throwable $e) {
        return $e;
    }

    return null;
}

function bytesOf(int $length): string
{
    return $length === 0 ? '' : random_bytes($length);
}

/** A new path in the temp directory, removed after each test; the process id keeps parallel runs from sharing files. */
function cipherTempPath(string $extension = ''): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'cipher_'.getmypid().'_'.bin2hex(random_bytes(6)).$extension;
}

/** @return array{0: string, 1: string} path of a new plaintext file and of its (not yet existing) ciphertext */
function cipherFiles(string $plaintext): array
{
    $base = cipherTempPath();
    file_put_contents($base.'.in', $plaintext);

    return [$base.'.in', $base.'.elr'];
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cipher_'.getmypid().'_*') ?: [] as $file) {
        @unlink($file);
    }
});

describe('round trips', function () {
    it('restores every size, including empty and exact multiples of the chunk', function (int $size) {
        $cipher = freshCipher();
        $plain = bytesOf($size);
        [$in, $enc] = cipherFiles($plain);
        $out = $enc.'.out';

        $written = $cipher->encryptFile($in, $enc, 'rec');
        $read = $cipher->decryptToFile($enc, $out, 'rec');

        expect($written)->toBe($size)->and($read)->toBe($size)->and(file_get_contents($out))->toBe($plain);
    })->with([0, 1, 16, RecordingCipher::CHUNK - 1, RecordingCipher::CHUNK, RecordingCipher::CHUNK + 1, 2 * RecordingCipher::CHUNK, 3 * RecordingCipher::CHUNK + 17]);

    it('frames the file as magic, header and one authenticated frame per chunk', function (int $size, int $frames) {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(bytesOf($size));

        $cipher->encryptFile($in, $enc, 'rec');

        expect(filesize($enc))->toBe(CIPHER_PREFIX + $size + $frames * SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES)
            ->and(file_get_contents($enc, false, null, 0, 4))->toBe('ELR1');
    })->with([
        'empty still carries a final frame' => [0, 1],
        'one byte' => [1, 1],
        'a chunk less a byte' => [RecordingCipher::CHUNK - 1, 1],
        'exactly one chunk adds an empty final frame' => [RecordingCipher::CHUNK, 2],
        'one chunk and a byte' => [RecordingCipher::CHUNK + 1, 2],
        'two exact chunks' => [2 * RecordingCipher::CHUNK, 3],
    ]);

    it('copes with ids of any length and alphabet', function (string $id) {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles('payload');

        $cipher->encryptFile($in, $enc, $id);

        expect($cipher->decryptToFile($enc, $enc.'.out', $id))->toBe(7);
    })->with(['', 'x', '01ARZ3NDEKTSV4RRFFQ69G5FAV', str_repeat('a', 5000), 'ünïcödé-🎙️']);

    it('streams to memory for range playback', function () {
        $cipher = freshCipher();
        $plain = random_bytes(RecordingCipher::CHUNK * 2 + 123);
        [$in, $enc] = cipherFiles($plain);
        $cipher->encryptFile($in, $enc, 'rec');

        $memory = $cipher->decryptToMemory($enc, 'rec');
        $memory->rewind();

        expect($memory->fread(strlen($plain) + 10))->toBe($plain);
    });

    it('produces different bytes each time for the same input', function () {
        $cipher = freshCipher();
        [$in, $first] = cipherFiles('same plaintext');
        $second = $first.'.2';

        $cipher->encryptFile($in, $first, 'rec');
        $cipher->encryptFile($in, $second, 'rec');

        expect(file_get_contents($first))->not->toBe(file_get_contents($second));
    });
});

describe('rejection', function () {
    it('will not decrypt under a different key', function () {
        [$in, $enc] = cipherFiles(random_bytes(1000));
        freshCipher()->encryptFile($in, $enc, 'rec');

        expect(fn () => freshCipher()->decryptToFile($enc, $enc.'.out', 'rec'))->toThrow(RuntimeException::class);
        expect(file_exists($enc.'.out'))->toBeFalse(); // the half-written output is removed
    });

    it('will not decrypt for another recording id', function () {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(random_bytes(1000));
        $cipher->encryptFile($in, $enc, 'rec-A');

        expect(fn () => $cipher->decryptToFile($enc, $enc.'.out', 'rec-B'))->toThrow(RuntimeException::class);
    });

    it('detects a flipped bit in any byte of a small file', function () {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(random_bytes(40));
        $cipher->encryptFile($in, $enc, 'rec');
        $good = (string) file_get_contents($enc);

        foreach (range(0, strlen($good) - 1) as $offset) {
            $bad = $good;
            $bad[$offset] = chr(ord($bad[$offset]) ^ 0x01);
            file_put_contents($enc, $bad);
            $failed = false;
            try {
                $cipher->decryptToFile($enc, $enc.'.out', 'rec');
            } catch (RuntimeException) {
                $failed = true;
            }
            expect($failed)->toBeTrue("flipping byte {$offset} went unnoticed");
        }
    });

    it('detects truncation inside the frames', function () {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(random_bytes(RecordingCipher::CHUNK + 500));
        $cipher->encryptFile($in, $enc, 'rec');
        $good = (string) file_get_contents($enc);

        foreach ([CIPHER_PREFIX, CIPHER_PREFIX + 1, CIPHER_PREFIX + 16, CIPHER_PREFIX + CIPHER_FRAME - 1, CIPHER_PREFIX + CIPHER_FRAME, strlen($good) - 1] as $length) {
            file_put_contents($enc, substr($good, 0, $length));
            expect(fn () => $cipher->decryptToFile($enc, $enc.'.out', 'rec'))->toThrow(RuntimeException::class);
            expect(file_exists($enc.'.out'))->toBeFalse("a partial output survived truncation to {$length} bytes");
        }
    });

    /** Cuts a valid file to $length bytes and expects decryption to fail without leaving an output file. */
    $truncatedLeavesNothing = function (int $length) {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(random_bytes(1000));
        $cipher->encryptFile($in, $enc, 'rec');
        file_put_contents($enc, substr((string) file_get_contents($enc), 0, $length));

        expect(thrownBy(fn () => $cipher->decryptToFile($enc, $enc.'.out', 'rec')))->not->toBeNull();
        expect(file_exists($enc.'.out'))->toBeFalse();
    };

    it('detects truncation inside the magic and leaves no output file behind', $truncatedLeavesNothing)->with([0, 3]);

    it('detects truncation inside the header and leaves no output file behind', $truncatedLeavesNothing)
        ->with([4, 5, 20, CIPHER_PREFIX - 1]);

    it('detects extra bytes after the end of the stream', function (string $suffix) {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(random_bytes(100));
        $cipher->encryptFile($in, $enc, 'rec');
        file_put_contents($enc, $suffix, FILE_APPEND);

        expect(fn () => $cipher->decryptToFile($enc, $enc.'.out', 'rec'))->toThrow(RuntimeException::class);
    })->with(['one byte' => "\0", 'garbage' => 'trailing garbage that is not part of the stream']);

    it('detects a second valid stream appended to the first', function () {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(random_bytes(100));
        $cipher->encryptFile($in, $enc, 'rec');
        file_put_contents($enc, file_get_contents($enc), FILE_APPEND);

        expect(fn () => $cipher->decryptToFile($enc, $enc.'.out', 'rec'))->toThrow(RuntimeException::class);
    });

    it('detects frames that were reordered or dropped', function () {
        $cipher = freshCipher();
        [$in, $enc] = cipherFiles(random_bytes(RecordingCipher::CHUNK * 3 + 10));
        $cipher->encryptFile($in, $enc, 'rec');
        $good = (string) file_get_contents($enc);
        $prefix = substr($good, 0, CIPHER_PREFIX);
        $frames = str_split(substr($good, CIPHER_PREFIX), CIPHER_FRAME);

        $variants = [
            'swapped' => $prefix.$frames[1].$frames[0].$frames[2].$frames[3],
            'dropped middle' => $prefix.$frames[0].$frames[2].$frames[3],
            'duplicated' => $prefix.$frames[0].$frames[0].$frames[1].$frames[2].$frames[3],
            'dropped final' => $prefix.$frames[0].$frames[1].$frames[2],
        ];
        foreach ($variants as $name => $bytes) {
            file_put_contents($enc, $bytes);
            $failed = false;
            try {
                $cipher->decryptToFile($enc, $enc.'.out', 'rec');
            } catch (RuntimeException) {
                $failed = true;
            }
            expect($failed)->toBeTrue("{$name} frames were accepted");
        }
    });

    it('rejects files that are not recordings', function (string $content) {
        $cipher = freshCipher();
        $path = cipherTempPath('.bin');
        file_put_contents($path, $content);

        expect(fn () => $cipher->decryptToFile($path, $path.'.out', 'rec'))->toThrow(RuntimeException::class);
    })->with(['empty' => '', 'plain text' => 'hello world, definitely not ciphertext', 'wrong magic' => 'ELR2'.str_repeat("\0", 100)]);

    it('rejects a file that is nothing but the magic and leaves no output file behind', function () {
        $cipher = freshCipher();
        $path = cipherTempPath('.bin');
        file_put_contents($path, 'ELR1');

        expect(thrownBy(fn () => $cipher->decryptToFile($path, $path.'.out', 'rec')))->not->toBeNull();
        expect(file_exists($path.'.out'))->toBeFalse();
    });

    it('reports a missing file as an error rather than returning nothing', function () {
        set_error_handler(static fn (): bool => true);
        try {
            expect(fn () => freshCipher()->decryptToFile(cipherTempPath('.elr'), cipherTempPath('.out'), 'rec'))->toThrow(RuntimeException::class);
        } finally {
            restore_error_handler();
        }
    });
});

describe('keys', function () {
    it('requires exactly 32 bytes', function (int $length, bool $ok) {
        $make = fn () => new RecordingCipher(str_repeat('k', $length));

        $ok ? expect($make())->toBeInstanceOf(RecordingCipher::class) : expect($make)->toThrow(RuntimeException::class);
    })->with([[0, false], [16, false], [31, false], [32, true], [33, false], [64, false]]);

    it('generates keys that are valid base64 of 32 random bytes', function () {
        $a = RecordingCipher::generateKey();
        $b = RecordingCipher::generateKey();

        expect(strlen((string) base64_decode($a, true)))->toBe(32)->and($a)->not->toBe($b);
        expect(new RecordingCipher((string) base64_decode($a, true)))->toBeInstanceOf(RecordingCipher::class);
    });
});
