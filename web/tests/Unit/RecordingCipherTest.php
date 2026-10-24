<?php

declare(strict_types=1);

use App\Services\RecordingCipher;

function cipher(): RecordingCipher
{
    return new RecordingCipher(sodium_crypto_secretstream_xchacha20poly1305_keygen());
}

function tmp(string $name): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'elr_'.bin2hex(random_bytes(6)).$name;
}

it('encrypts then decrypts back to the original bytes', function () {
    $c = cipher();
    $plain = tmp('.in');
    $enc = tmp('.elr');
    $out = tmp('.out');
    file_put_contents($plain, random_bytes(200_000)); // spans multiple 64 KiB chunks

    $c->encryptFile($plain, $enc, 'rec_1');
    $c->decryptToFile($enc, $out, 'rec_1');

    expect(hash_file('sha256', $out))->toBe(hash_file('sha256', $plain));
    expect(file_get_contents($enc))->not->toBe(file_get_contents($plain));

    foreach ([$plain, $enc, $out] as $f) {
        @unlink($f);
    }
});

it('refuses to decrypt with a different recording id (bound as AAD)', function () {
    $c = cipher();
    $plain = tmp('.in');
    $enc = tmp('.elr');
    file_put_contents($plain, 'the quick brown fox');
    $c->encryptFile($plain, $enc, 'rec_right');

    expect(fn () => $c->decryptToFile($enc, tmp('.out'), 'rec_wrong'))
        ->toThrow(RuntimeException::class);

    @unlink($plain);
    @unlink($enc);
});

it('detects truncation (missing final tag)', function () {
    $c = cipher();
    $plain = tmp('.in');
    $enc = tmp('.elr');
    file_put_contents($plain, random_bytes(200_000));
    $c->encryptFile($plain, $enc, 'rec_1');

    // Lop off the final chunk so TAG_FINAL never arrives.
    $bytes = (string) file_get_contents($enc);
    file_put_contents($enc, substr($bytes, 0, (int) (strlen($bytes) / 2)));

    expect(fn () => $c->decryptToFile($enc, tmp('.out'), 'rec_1'))
        ->toThrow(RuntimeException::class);

    @unlink($plain);
    @unlink($enc);
});

it('detects tampering of the ciphertext body', function () {
    $c = cipher();
    $plain = tmp('.in');
    $enc = tmp('.elr');
    file_put_contents($plain, str_repeat('A', 1000));
    $c->encryptFile($plain, $enc, 'rec_1');

    $bytes = (string) file_get_contents($enc);
    $bytes[60] = chr(ord($bytes[60]) ^ 0x01); // flip a bit past the magic + header
    file_put_contents($enc, $bytes);

    expect(fn () => $c->decryptToFile($enc, tmp('.out'), 'rec_1'))
        ->toThrow(RuntimeException::class);

    @unlink($plain);
    @unlink($enc);
});

it('rejects a wrong-sized key', function () {
    expect(fn () => new RecordingCipher('too-short'))->toThrow(RuntimeException::class);
});
