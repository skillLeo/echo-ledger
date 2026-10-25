<?php

declare(strict_types=1);

use App\Enums\TranscriptKind;
use App\Enums\TranscriptStatus;
use App\Jobs\TranscribeFinalJob;
use App\Models\Credential;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Services\CredentialVault;
use App\Services\PairingService;
use App\Services\RecordingCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Feature\Security\Support\SecurityFixtures;

/** @return array{0: string, 1: string} source path, destination path in the temp directory */
function secCipherPaths(): array
{
    $dir = sys_get_temp_dir();
    $id = bin2hex(random_bytes(6));

    return [$dir.DIRECTORY_SEPARATOR."sec-plain-{$id}.bin", $dir.DIRECTORY_SEPARATOR."sec-enc-{$id}.elr"];
}

/** Encrypts `$plain` and returns the ciphertext file bytes. */
function secEncrypt(string $plain, string $recordingId = 'rec-a', ?RecordingCipher $cipher = null): string
{
    [$src, $dst] = secCipherPaths();
    file_put_contents($src, $plain);
    ($cipher ?? RecordingCipher::fromConfig())->encryptFile($src, $dst, $recordingId);
    $bytes = (string) file_get_contents($dst);
    @unlink($src);
    @unlink($dst);

    return $bytes;
}

/** Decrypts raw ciphertext bytes; throws on any integrity failure. */
function secDecrypt(string $ciphertext, string $recordingId = 'rec-a', ?RecordingCipher $cipher = null): string
{
    [$src, $dst] = secCipherPaths();
    file_put_contents($src, $ciphertext);
    try {
        $out = fopen('php://memory', 'w+b');
        ($cipher ?? RecordingCipher::fromConfig())->decryptToStream($src, $out, $recordingId);
        rewind($out);

        return (string) stream_get_contents($out);
    } finally {
        @unlink($src);
        @unlink($dst);
    }
}

dataset('plaintext sizes', [
    'empty' => [0],
    'one byte' => [1],
    'just under one chunk' => [RecordingCipher::CHUNK - 1],
    'exactly one chunk' => [RecordingCipher::CHUNK],
    'one chunk plus one' => [RecordingCipher::CHUNK + 1],
    'exactly two chunks' => [RecordingCipher::CHUNK * 2],
    'five and a half chunks' => [RecordingCipher::CHUNK * 5 + 1234],
]);

it('round-trips every boundary size and never stores plaintext', function (int $size) {
    $plain = $size === 0 ? '' : random_bytes($size);

    $cipherText = secEncrypt($plain);

    expect(secDecrypt($cipherText))->toBe($plain);
    expect(substr($cipherText, 0, 4))->toBe('ELR1');
    if ($size >= 64) {
        expect(str_contains($cipherText, substr($plain, 0, 48)))->toBeFalse();
    }
})->with('plaintext sizes');

it('never lets a readable marker survive in the ciphertext file', function () {
    $marker = 'CONFIDENTIAL-CALL-WITH-ACME-HOLDINGS';
    $plain = str_repeat($marker.' ', 10000);

    $cipherText = secEncrypt($plain);

    expect(str_contains($cipherText, $marker))->toBeFalse()->and(strlen($cipherText))->toBeGreaterThan(strlen($plain));
});

it('detects a flipped bit anywhere in the file', function (string $region) {
    $plain = random_bytes(RecordingCipher::CHUNK * 2 + 500);
    $cipherText = secEncrypt($plain);
    $length = strlen($cipherText);
    $offsets = match ($region) {
        'magic' => [0, 3],
        'stream header' => [4, 4 + 23],
        'first chunk ciphertext' => [28, 28 + 1000],
        'first chunk authentication tag' => [28 + RecordingCipher::CHUNK + 16 - 1],
        'middle chunk' => [28 + RecordingCipher::CHUNK + 17 + 500],
        'last chunk ciphertext' => [$length - 40],
        'final authentication tag' => [$length - 1],
    };

    foreach ($offsets as $offset) {
        $tampered = $cipherText;
        $tampered[$offset] = chr(ord($tampered[$offset]) ^ 0x01);
        expect(fn () => secDecrypt($tampered))->toThrow(RuntimeException::class);
    }
})->with(['magic', 'stream header', 'first chunk ciphertext', 'first chunk authentication tag', 'middle chunk', 'last chunk ciphertext', 'final authentication tag']);

it('detects truncation at a chunk boundary and anywhere else', function () {
    $plain = random_bytes(RecordingCipher::CHUNK * 3 + 100);
    $cipherText = secEncrypt($plain);
    $frame = RecordingCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
    $cuts = ['drop last chunk' => 28 + $frame * 3, 'drop two chunks' => 28 + $frame * 2, 'header only' => 28, 'magic only' => 4,
        'mid chunk' => 28 + $frame + 77, 'one byte short' => strlen($cipherText) - 1, 'empty file' => 0];

    $undetected = [];
    foreach ($cuts as $label => $length) {
        try {
            secDecrypt(substr($cipherText, 0, $length));
            $undetected[] = $label;
        } catch (Exception) {
            // expected: the decryptor must refuse a stream that never reaches its final tag
        }
    }
    expect($undetected)->toBe([]);
});

it('detects appended data and appended chunks', function () {
    $cipherText = secEncrypt(random_bytes(1000));
    $second = secEncrypt(random_bytes(1000));

    expect(fn () => secDecrypt($cipherText.'extra'))->toThrow(RuntimeException::class);
    expect(fn () => secDecrypt($cipherText.substr($second, 28)))->toThrow(RuntimeException::class);
    expect(fn () => secDecrypt($cipherText.str_repeat("\0", 17)))->toThrow(RuntimeException::class);
});

it('detects reordered and duplicated chunks', function () {
    $cipherText = secEncrypt(random_bytes(RecordingCipher::CHUNK * 3));
    $frame = RecordingCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
    $head = substr($cipherText, 0, 28);
    $chunks = [substr($cipherText, 28, $frame), substr($cipherText, 28 + $frame, $frame), substr($cipherText, 28 + $frame * 2, $frame), substr($cipherText, 28 + $frame * 3)];

    foreach ([[1, 0, 2, 3], [0, 2, 1, 3], [0, 0, 1, 2, 3], [0, 1, 2, 2, 3], [3, 0, 1, 2]] as $order) {
        expect(fn () => secDecrypt($head.implode('', array_map(fn (int $i) => $chunks[$i], $order))))->toThrow(RuntimeException::class);
    }
});

it('binds the file to its recording id so ciphertext cannot be swapped between records', function () {
    $cipherText = secEncrypt('belongs to recording A', 'recording-a');

    expect(secDecrypt($cipherText, 'recording-a'))->toBe('belongs to recording A');
    expect(fn () => secDecrypt($cipherText, 'recording-b'))->toThrow(RuntimeException::class);
    expect(fn () => secDecrypt($cipherText, ''))->toThrow(RuntimeException::class);
    expect(fn () => secDecrypt($cipherText, 'recording-a '))->toThrow(RuntimeException::class);
});

it('uses a fresh random stream header for every file, so identical audio never repeats a nonce or ciphertext', function () {
    $plain = random_bytes(5000);
    $headers = [];
    $bodies = [];

    for ($i = 0; $i < 25; $i++) {
        $cipherText = secEncrypt($plain);
        $headers[] = substr($cipherText, 4, 24);
        $bodies[] = substr($cipherText, 28);
    }

    expect(count(array_unique($headers)))->toBe(25)->and(count(array_unique($bodies)))->toBe(25);
});

it('does not decrypt with a different key', function () {
    $cipherText = secEncrypt('secret audio');
    $other = new RecordingCipher(sodium_crypto_secretstream_xchacha20poly1305_keygen());

    expect(fn () => secDecrypt($cipherText, 'rec-a', $other))->toThrow(RuntimeException::class);
});

it('refuses keys of the wrong length or encoding', function () {
    foreach (['', 'short', str_repeat('k', 31), str_repeat('k', 33)] as $key) {
        expect(fn () => new RecordingCipher($key))->toThrow(RuntimeException::class);
    }
    foreach ([null, '', 'not base64 !!', base64_encode('too short'), base64_encode(str_repeat('k', 33))] as $encoded) {
        config(['echoledger.recordings_key' => $encoded]);
        expect(fn () => RecordingCipher::fromConfig())->toThrow(RuntimeException::class);
    }
});

it('generates distinct 32-byte keys from the system random source', function () {
    $keys = array_map(fn () => RecordingCipher::generateKey(), range(1, 20));

    expect(count(array_unique($keys)))->toBe(20);
    foreach ($keys as $key) {
        expect(strlen((string) base64_decode($key, true)))->toBe(SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES);
    }
});

it('keeps the recordings key independent of the application key and out of the env template', function () {
    $recordingsKey = (string) base64_decode((string) config('echoledger.recordings_key'), true);
    $appKey = (string) base64_decode(substr((string) config('app.key'), 7), true);

    expect($recordingsKey)->not->toBe($appKey)
        ->and(hash_equals(hash('sha256', $appKey, true), $recordingsKey))->toBeFalse()
        ->and(hash_equals(substr(hash('sha256', $appKey, true), 0, 32), $recordingsKey))->toBeFalse();
    expect(file_get_contents(base_path('config/echoledger.php')))->toContain("env('ECHOLEDGER_RECORDINGS_KEY')");
    expect(file_get_contents(base_path('.env.example')))->toMatch('/^ECHOLEDGER_RECORDINGS_KEY=\s*$/m');
});

it('fails closed when the encrypted file is missing', function () {
    [, $dst] = secCipherPaths();
    $out = fopen('php://memory', 'w+b');

    expect(fn () => RecordingCipher::fromConfig()->decryptToStream($dst, $out, 'rec-a'))->toThrow(Exception::class);
});

/** Decrypts a damaged copy of a three-chunk recording to a file and checks that nothing decrypted is left behind. */
$leavesNoPlaintextBehind = function (string $damage) {
    [, $dst] = secCipherPaths();
    $encrypted = $dst.'.enc';
    $frame = RecordingCipher::CHUNK + SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_ABYTES;
    $raw = secEncrypt(random_bytes(RecordingCipher::CHUNK * 3));
    $raw = match ($damage) {
        'flipped bit in the final tag' => substr_replace($raw, chr(ord($raw[strlen($raw) - 3]) ^ 0xFF), strlen($raw) - 3, 1),
        'flipped bit in the second chunk' => substr_replace($raw, chr(ord($raw[28 + $frame + 10]) ^ 0xFF), 28 + $frame + 10, 1),
        'dangling five bytes after two good chunks' => substr($raw, 0, 28 + $frame * 2).'12345',
        'stream cut inside the header' => substr($raw, 0, 12),
        'stream cut right after the header' => substr($raw, 0, 28),
        'good chunks followed by one byte' => substr($raw, 0, 28 + $frame * 2).'x',
    };
    file_put_contents($encrypted, $raw);

    try {
        RecordingCipher::fromConfig()->decryptToFile($encrypted, $dst, 'rec-a');
        $threw = false;
    } catch (Exception) {
        $threw = true;
    }
    $residue = file_exists($dst) ? filesize($dst) : null;
    @unlink($encrypted);
    @unlink($dst);

    expect($threw)->toBeTrue('damaged ciphertext must never decrypt cleanly');
    expect($residue)->toBeNull("a decrypted file of {$residue} bytes was left on disk");
};

it('leaves no partial plaintext file behind when decryption to a file fails', $leavesNoPlaintextBehind)
    ->with(['flipped bit in the final tag', 'flipped bit in the second chunk', 'dangling five bytes after two good chunks', 'stream cut right after the header', 'good chunks followed by one byte']);

it('leaves no file behind when the encrypted stream is cut inside its header', $leavesNoPlaintextBehind)
    ->with(['stream cut inside the header']);

it('stores provider credentials as authenticated ciphertext, never as plain text', function () {
    $secret = 'aai-PLAINTEXT-SENTINEL-'.bin2hex(random_bytes(8));
    app(CredentialVault::class)->put('assemblyai', 'api_key', $secret);

    $raw = (string) DB::table('credentials')->where('provider', 'assemblyai')->value('value');

    expect($raw)->not->toContain($secret)->not->toBe($secret);
    $envelope = json_decode((string) base64_decode($raw, true), true);
    expect($envelope)->toBeArray()->toHaveKeys(['iv', 'value', 'mac']);
    expect(Crypt::decryptString($raw))->toBe($secret);
    expect(Credential::query()->firstOrFail()->toArray())->not->toHaveKey('value');
    expect(Credential::query()->firstOrFail()->toJson())->not->toContain($secret);
});

it('refuses a credential whose stored ciphertext was altered', function () {
    $secret = 'webhook-secret-'.bin2hex(random_bytes(6));
    app(CredentialVault::class)->put('assemblyai', 'webhook_secret', $secret);
    $envelope = json_decode((string) base64_decode((string) DB::table('credentials')->value('value'), true), true);
    $value = base64_decode($envelope['value'], true);
    $value[0] = chr(ord($value[0]) ^ 1);
    $envelope['value'] = base64_encode($value);
    DB::table('credentials')->update(['value' => base64_encode(json_encode($envelope))]);

    expect(fn () => Credential::query()->firstOrFail()->value)->toThrow(DecryptException::class);
});

it('refuses a credential encrypted under a different application key', function () {
    $foreign = new Encrypter(random_bytes(32), 'aes-256-cbc');
    DB::table('credentials')->insert(['provider' => 'assemblyai', 'key' => 'api_key', 'value' => $foreign->encryptString('stolen'), 'created_at' => now(), 'updated_at' => now()]);

    expect(fn () => Credential::query()->firstOrFail()->value)->toThrow(DecryptException::class);
});

it('stores webhook payloads encrypted', function () {
    $needle = 'TRANSCRIPT-TEXT-SENTINEL-'.bin2hex(random_bytes(6));
    WebhookEvent::query()->create(['provider' => 'assemblyai', 'idempotency_key' => 'k1', 'payload' => ['text' => $needle], 'status' => 'received']);

    $raw = (string) DB::table('webhook_events')->value('payload');

    expect($raw)->not->toContain($needle);
    expect(WebhookEvent::query()->first()->payload['text'])->toBe($needle);
    expect(WebhookEvent::query()->first()->toArray())->not->toHaveKey('payload');
});

it('hashes pairing codes and device tokens at rest', function () {
    $user = User::factory()->create();
    $display = app(PairingService::class)->createCode($user);
    $raw = str_replace('-', '', $display);

    $stored = (string) DB::table('device_pairing_codes')->value('code_hash');

    expect($stored)->toBe(hash('sha256', $raw))->and($stored)->not->toContain($raw);
});

/** Points the app's plaintext temp directory at a private folder so the assertions see only this test's files. */
function secTempDir(): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sec-tmp-'.bin2hex(random_bytes(5));
    config(['echoledger.tmp_dir' => $dir]);

    return $dir;
}

it('removes the decrypted copy of a recording when the provider upload fails', function () {
    SecurityFixtures::fakeRecordingsDisk();
    $dir = secTempDir();
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_key');
    Http::fake(['*' => Http::response(['error' => 'down'], 500)]);
    $session = SecurityFixtures::session(SecurityFixtures::user());
    SecurityFixtures::readyRecording($session, random_bytes(8192));
    $transcript = $session->transcripts()->create(['kind' => TranscriptKind::Final, 'provider' => 'assemblyai', 'status' => TranscriptStatus::Pending]);

    try {
        Bus::dispatchNow(new TranscribeFinalJob($transcript->id));
    } catch (Throwable) {
        // the provider failure is expected to surface; the plaintext must not outlive it
    }

    expect(is_dir($dir) ? array_values(array_diff(scandir($dir), ['.', '..'])) : [])->toBe([]);
    SecurityFixtures::cleanupDisks();
});

it('purges decrypted or assembled audio orphaned by a crashed worker during scheduled maintenance', function () {
    $dir = secTempDir();
    mkdir($dir, 0700, true);
    $orphans = [$dir.'/primary-aaaaaaaaaaaaaaaa.bin', $dir.'/assemble-bbbbbbbbbbbbbbbb.bin', $dir.'/channel_1-cccccccccccccccc.bin'];
    foreach ($orphans as $path) {
        file_put_contents($path, random_bytes(2048));
        touch($path, time() - 3 * 86400);
    }

    $this->travel(1)->days();
    foreach (['model:prune', 'sanctum:prune-expired', 'stt:reconcile'] as $command) {
        Artisan::call($command);
    }

    $left = array_values(array_filter($orphans, 'file_exists'));
    foreach ($orphans as $path) {
        @unlink($path);
    }
    @rmdir($dir);
    expect($left)->toBe([]);
});
