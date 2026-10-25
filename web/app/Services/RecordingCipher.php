<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;
use SplTempFileObject;

/**
 * Encrypts recordings at rest with libsodium secretstream (XChaCha20-Poly1305).
 * File layout: MAGIC(4) | header(24) | chunk ciphertexts (≤ CHUNK + ABYTES each), last chunk tagged FINAL.
 * The recording id is bound as additional data, so ciphertext files cannot be swapped between records.
 * See docs/research/web-laravel-filament.md §8.
 */
final class RecordingCipher
{
    public const MAGIC = 'ELR1';

    public const CHUNK = 65536;

    public function __construct(private readonly string $key)
    {
        if (strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('Recordings key must be 32 bytes.');
        }
    }

    public static function fromConfig(): self
    {
        $encoded = (string) config('echoledger.recordings_key');
        $key = $encoded === '' ? false : base64_decode($encoded, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('ECHOLEDGER_RECORDINGS_KEY is missing or invalid. Run `php artisan echoledger:key`.');
        }

        return new self($key);
    }

    public static function generateKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    /** @return int bytes of plaintext encrypted */
    public function encryptFile(string $srcPath, string $dstPath, string $recordingId): int
    {
        $in = fopen($srcPath, 'rb');
        $out = fopen($dstPath, 'wb');
        if ($in === false || $out === false) {
            throw new RuntimeException('Cannot open files for encryption.');
        }

        $aad = self::MAGIC.$recordingId;
        $total = 0;
        try {
            [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($this->key);
            fwrite($out, self::MAGIC.$header);
            do {
                $chunk = fread($in, self::CHUNK);
                $chunk = $chunk === false ? '' : $chunk;
                $eof = feof($in);
                $total += strlen($chunk);
                $tag = $eof ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
                fwrite($out, sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, $aad, $tag));
            } while (! $eof);
            sodium_memzero($state);
        } finally {
            fclose($in);
            fclose($out);
        }

        return $total;
    }

    /**
     * Decrypts into a writable stream. Throws on tampering, truncation, or trailing data
     * (already-written bytes must then be discarded by the caller).
     *
     * @param  resource  $out
     * @return int plaintext bytes written
     */
    public function decryptToStream(string $srcPath, $out, string $recordingId): int
    {
        return $this->pull($srcPath, $recordingId, static function (string $plain) use ($out): void {
            fwrite($out, $plain);
        });
    }

    /**
     * SHA-256 and length of the plaintext, computed while decrypting: nothing decrypted is kept in memory or on disk.
     *
     * @return array{sha256: string, size: int}
     */
    public function digest(string $srcPath, string $recordingId): array
    {
        $hash = hash_init('sha256');
        $size = $this->pull($srcPath, $recordingId, static function (string $plain) use ($hash): void {
            hash_update($hash, $plain);
        });

        return ['sha256' => hash_final($hash), 'size' => $size];
    }

    /**
     * Authenticates and decrypts the file one frame at a time, handing each plaintext frame to $sink.
     *
     * @param  callable(string): void  $sink
     * @return int plaintext bytes produced
     */
    private function pull(string $srcPath, string $recordingId, callable $sink): int
    {
        $in = fopen($srcPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Cannot open encrypted recording.');
        }

        $aad = self::MAGIC.$recordingId;
        $written = 0;
        $final = false;
        try {
            if (fread($in, 4) !== self::MAGIC) {
                throw new RuntimeException('Not an EchoLedger encrypted recording.');
            }
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            // init_pull throws SodiumException (not a RuntimeException) for a short header.
            if (strlen($header) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES) {
                throw new RuntimeException('Recording is truncated or has trailing data.');
            }
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $this->key);
            while (! feof($in)) {
                $cipher = fread($in, self::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES);
                if ($cipher === false || $cipher === '') {
                    break;
                }
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher, $aad);
                if ($result === false) {
                    throw new RuntimeException('Recording is corrupt or was tampered with.');
                }
                [$plain, $tag] = $result;
                $sink($plain);
                $written += strlen($plain);
                if ($tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL) {
                    $final = true;
                    break;
                }
            }
            $trailing = ! feof($in) && fread($in, 1) !== '';
            if (! $final || $trailing) {
                throw new RuntimeException('Recording is truncated or has trailing data.');
            }
        } finally {
            fclose($in);
        }

        return $written;
    }

    public function decryptToFile(string $srcPath, string $dstPath, string $recordingId): int
    {
        $out = fopen($dstPath, 'wb');
        if ($out === false) {
            throw new RuntimeException('Cannot create decrypted temp file.');
        }
        try {
            return $this->decryptToStream($srcPath, $out, $recordingId);
        } catch (\Throwable $e) {
            // Whatever failed (tampering, truncation, a full disk), no partial plaintext may stay behind.
            fclose($out);
            @unlink($dstPath);
            throw $e;
        } finally {
            if (is_resource($out)) {
                fclose($out);
            }
        }
    }

    /**
     * Memory-only plaintext (never spills to disk). Holds the whole recording in memory, so playback does not use it
     * (RecordingController::streamDecrypted decrypts to a private temp file instead).
     */
    public function decryptToMemory(string $srcPath, string $recordingId): SplTempFileObject
    {
        $memory = new SplTempFileObject(-1);
        $stream = fopen('php://memory', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('Cannot allocate memory stream.');
        }
        try {
            $this->decryptToStream($srcPath, $stream, $recordingId);
            rewind($stream);
            while (! feof($stream)) {
                $memory->fwrite((string) fread($stream, self::CHUNK));
            }
        } finally {
            fclose($stream);
        }
        $memory->rewind();

        return $memory;
    }
}
