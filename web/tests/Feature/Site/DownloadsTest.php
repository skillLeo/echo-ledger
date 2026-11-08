<?php

declare(strict_types=1);

use App\Models\AppRelease;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/*
 * The public site (since v1.5, docs/API.md "Public pages and app downloads"): `echoledger:publish-release`, the
 * downloads page, the phone's android.json, the desktop updater's desktop.json, the files, the landing page.
 */

beforeEach(function (): void {
    Storage::fake('downloads');
});

/** A build file holding [$contents], named build.<extension> in a folder of its own. */
function releaseFile(string $extension, string $contents = 'build bytes'): string
{
    $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'echoledger-release-'.bin2hex(random_bytes(6));
    mkdir($dir);
    $path = $dir.DIRECTORY_SEPARATOR.'build.'.$extension;
    file_put_contents($path, $contents);

    return $path;
}

/** @param array<string, mixed> $options */
function publishRelease(string $file, array $options): void
{
    expect(Artisan::call('echoledger:publish-release', ['file' => $file, ...$options]))->toBe(0, Artisan::output());
}

it('publishes a phone build with its size, SHA-256 and version code, and android.json offers it', function () {
    publishRelease(releaseFile('apk', 'apk-one'), ['--platform' => 'android', '--app-version' => '1.5.0', '--code' => 16, '--notes' => 'Faster uploads.']);

    $release = AppRelease::query()->sole();
    expect($release->file_name)->toBe('EchoLedger-1.5.0.apk')
        ->and($release->bytes)->toBe(7)
        ->and($release->sha256)->toBe(hash('sha256', 'apk-one'))
        ->and($release->code)->toBe(16);
    Storage::disk('downloads')->assertExists('android/EchoLedger-1.5.0.apk');

    $response = $this->getJson('/downloads/android.json')->assertOk()->assertExactJson([
        'version' => '1.5.0',
        'code' => 16,
        'url' => route('downloads.file', ['name' => 'EchoLedger-1.5.0.apk']),
        'sha256' => hash('sha256', 'apk-one'),
        'bytes' => 7,
        'min_sdk' => 29,
        'notes' => 'Faster uploads.',
        'published_at' => $release->published_at->toAtomString(),
    ]);
    expect((string) $response->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=300');
});

it('says not_found while no phone build is published', function () {
    $this->getJson('/downloads/android.json')->assertNotFound()->assertJson(['code' => 'not_found']);
});

it('offers the highest version code, and publishing the same version again replaces its file', function () {
    publishRelease(releaseFile('apk', 'first'), ['--platform' => 'android', '--app-version' => '1.5.0', '--code' => 16]);
    publishRelease(releaseFile('apk', 'second'), ['--platform' => 'android', '--app-version' => '1.5.1', '--code' => 17]);
    expect($this->getJson('/downloads/android.json')->json('code'))->toBe(17);

    publishRelease(releaseFile('apk', 'second, fixed'), ['--platform' => 'android', '--app-version' => '1.5.1', '--code' => 17]);
    expect(AppRelease::query()->count())->toBe(2)
        ->and(Storage::disk('downloads')->get('android/EchoLedger-1.5.1.apk'))->toBe('second, fixed')
        ->and($this->getJson('/downloads/android.json')->json('sha256'))->toBe(hash('sha256', 'second, fixed'));
});

it('refuses a wrong platform, version or file type, a missing file or code, and a signature on a phone build', function () {
    $apk = releaseFile('apk');
    $exe = releaseFile('exe');
    $refused = [
        [$apk, ['--platform' => 'linux', '--app-version' => '1.5.0'], 'Unknown --platform'],
        [$apk, ['--platform' => 'android', '--app-version' => 'one'], 'must look like'],
        [$apk, ['--platform' => 'android', '--app-version' => '1.5.0'], 'needs --code'],
        [$apk, ['--platform' => 'windows', '--app-version' => '1.5.0'], '.exe or .msi'],
        [$apk, ['--platform' => 'android', '--app-version' => '1.5.0', '--code' => 3, '--signature' => releaseFile('sig', 'abc')], 'no updater signature'],
        [$exe, ['--platform' => 'windows', '--app-version' => '1.5.0', '--updater-file' => releaseFile('app.tar.gz')], 'only for Mac'],
    ];
    foreach ($refused as [$file, $options, $words]) {
        expect(Artisan::call('echoledger:publish-release', ['file' => $file, ...$options]))->toBe(1)
            ->and(Artisan::output())->toContain($words);
    }
    expect(Artisan::call('echoledger:publish-release', ['file' => $apk.'.gone', '--platform' => 'android', '--app-version' => '1.5.0', '--code' => 1]))->toBe(1)
        ->and(AppRelease::query()->count())->toBe(0);
});

it('lists the newest signed desktop builds in the updater manifest and leaves unsigned ones out', function () {
    $this->getJson('/downloads/desktop.json')->assertNotFound()->assertJson(['code' => 'not_found']);

    publishRelease(releaseFile('exe', 'win 1.5.0'), ['--platform' => 'windows', '--app-version' => '1.5.0', '--signature' => releaseFile('sig', 'sig-win-150'), '--notes' => 'Screen moments.']);
    publishRelease(releaseFile('dmg', 'mac 1.5.0'), [
        '--platform' => 'macos-arm', '--app-version' => '1.5.0',
        '--updater-file' => releaseFile('app.tar.gz', 'mac app 1.5.0'), '--signature' => releaseFile('sig', 'sig-mac-150'),
    ]);
    publishRelease(releaseFile('exe', 'win 1.5.1 unsigned'), ['--platform' => 'windows', '--app-version' => '1.5.1']);

    $manifest = $this->getJson('/downloads/desktop.json')->assertOk()->json();
    expect($manifest['version'])->toBe('1.5.0')
        ->and($manifest['notes'])->toBe('Screen moments.')
        ->and(array_keys($manifest['platforms']))->toEqualCanonicalizing(['windows-x86_64', 'darwin-aarch64'])
        ->and($manifest['platforms']['windows-x86_64'])->toBe([
            'url' => route('downloads.file', ['name' => 'EchoLedger-1.5.0-setup.exe']), 'signature' => 'sig-win-150',
        ])
        ->and($manifest['platforms']['darwin-aarch64'])->toBe([
            'url' => route('downloads.file', ['name' => 'EchoLedger-1.5.0-mac-apple-silicon.app.tar.gz']), 'signature' => 'sig-mac-150',
        ]);
    $this->get($manifest['platforms']['darwin-aarch64']['url'])->assertOk()->assertHeader('Content-Type', 'application/gzip');

    // Once 1.5.1 is signed it is the update, with only the platforms signed for it.
    publishRelease(releaseFile('exe', 'win 1.5.1'), ['--platform' => 'windows', '--app-version' => '1.5.1', '--signature' => releaseFile('sig', 'sig-win-151')]);
    $manifest = $this->getJson('/downloads/desktop.json')->json();
    expect($manifest['version'])->toBe('1.5.1')->and(array_keys($manifest['platforms']))->toBe(['windows-x86_64']);
});

it('serves a build as a resumable attachment, and nothing else', function () {
    publishRelease(releaseFile('apk', 'abcdefghij'), ['--platform' => 'android', '--app-version' => '1.5.0', '--code' => 16]);

    $response = $this->get('/downloads/files/EchoLedger-1.5.0.apk')->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.android.package-archive')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect((string) $response->headers->get('Content-Disposition'))->toContain('attachment')->toContain('EchoLedger-1.5.0.apk')
        ->and(file_get_contents($response->baseResponse->getFile()->getPathname()))->toBe('abcdefghij');

    $this->get('/downloads/files/EchoLedger-1.5.0.apk', ['Range' => 'bytes=2-4'])
        ->assertStatus(206)
        ->assertHeader('Content-Range', 'bytes 2-4/10');

    $this->get('/downloads/files/EchoLedger-9.9.9.apk')->assertNotFound();
    $this->get('/downloads/files/..%2F..%2F.env')->assertNotFound();
    $this->get('/downloads/files/.env')->assertNotFound();
});

it('says what works where, also before any build is published', function () {
    $this->get('/downloads')->assertOk()
        ->assertSee('What works where')
        ->assertSee('macOS 14.4')
        ->assertSee('only your voice in app calls')
        ->assertSee('Windows 10:')
        ->assertSee('Samsung Galaxy S25 Ultra');
});

it('shows the newest build of each app with its SHA-256, and the older ones folded away', function () {
    $this->get('/downloads')->assertOk()->assertSee('No builds are published yet.');

    publishRelease(releaseFile('apk', 'old phone'), ['--platform' => 'android', '--app-version' => '1.5.0', '--code' => 16]);
    publishRelease(releaseFile('apk', 'new phone'), ['--platform' => 'android', '--app-version' => '1.5.1', '--code' => 17, '--notes' => 'Projects <b>and</b> sharing.']);
    publishRelease(releaseFile('exe', 'windows'), ['--platform' => 'windows', '--app-version' => '1.5.1']);

    $this->get('/downloads')->assertOk()
        ->assertSee('Android phone')
        ->assertSee('Version 1.5.1')
        ->assertSee(hash('sha256', 'new phone'))
        ->assertSee('Windows')
        ->assertSee('Older versions')
        ->assertSee('EchoLedger-1.5.0.apk')
        ->assertSee('Projects &lt;b&gt;and&lt;/b&gt; sharing.', false)
        ->assertSee('noindex, nofollow', false);
});

it('shows the landing page to everyone, hidden from search engines until the launch', function () {
    $this->get('/')->assertOk()
        ->assertSee('Your calls and meetings, written down word for word.')
        ->assertSee('noindex, nofollow', false)
        ->assertDontSee('Get the apps');

    publishRelease(releaseFile('apk'), ['--platform' => 'android', '--app-version' => '1.5.0', '--code' => 16]);
    config(['echoledger.site_indexable' => true]);
    $this->get('/')->assertOk()->assertSee('Get the apps')->assertSee('index, follow', false);
});

it('throttles the public site per address', function () {
    foreach (range(1, 60) as $ignored) {
        $this->getJson('/downloads/android.json')->assertNotFound();
    }
    $this->getJson('/downloads/android.json')->assertTooManyRequests();
});

it('starts queue workers from the scheduler on shared hosting only', function () {
    Artisan::call('schedule:list');
    $worker = collect(app(Schedule::class)->events())->first(fn (Event $event): bool => str_contains((string) $event->command, 'queue:work'));

    expect($worker)->not->toBeNull()->and($worker->filtersPass(app()))->toBeTrue();
    config(['queue.via_scheduler' => false]);
    expect($worker->filtersPass(app()))->toBeFalse();
});

it('says how to get started in three steps (since v1.5, Phase 1C)', function () {
    $html = $this->get('/downloads')->assertOk()->getContent();

    expect($html)->toContain('How to get started')->toContain('Download and install')->toContain('Sign in')->toContain('Run the Ready check')
        ->and(substr_count((string) preg_replace('/.*How to get started(.*?)<\/ol>.*/s', '$1', $html), '<li'))->toBe(3);
});
