<?php

declare(strict_types=1);

use App\Services\RecordingCipher;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

/*
 * `echoledger:key` writes to <base path>/.env. These tests point the application at a temporary directory first and
 * refuse to run the command if that did not take effect, so the real environment file is never touched.
 */
beforeEach(function () {
    $this->tempBase = sys_get_temp_dir().DIRECTORY_SEPARATOR.'eld-key-'.bin2hex(random_bytes(6));
    mkdir($this->tempBase, 0700, true);
    $this->realBasePath = base_path();
    $this->realEnvHash = is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null;
    $this->app->setBasePath($this->tempBase);
    $this->env = $this->tempBase.DIRECTORY_SEPARATOR.'.env';
});

afterEach(function () {
    $this->app->setBasePath($this->realBasePath);
    File::deleteDirectory($this->tempBase);
    expect(is_file(base_path('.env')) ? hash_file('sha256', base_path('.env')) : null)->toBe($this->realEnvHash);
});

/** Guard: only run the writing command once the temporary base path is really in effect. */
function assertTempBasePath(object $test): void
{
    expect(base_path('.env'))->toBe($test->env);
}

it('prints a fresh valid key with --show and writes nothing', function () {
    assertTempBasePath($this);

    Artisan::call('echoledger:key', ['--show' => true]);
    $first = trim(Artisan::output());
    Artisan::call('echoledger:key', ['--show' => true]);
    $second = trim(Artisan::output());

    expect(strlen((string) base64_decode($first, true)))->toBe(32)
        ->and($second)->not->toBe($first)
        ->and(is_file($this->env))->toBeFalse();
    expect(new RecordingCipher((string) base64_decode($first, true)))->toBeInstanceOf(RecordingCipher::class);
});

it('creates the environment file with the key when there is none', function () {
    assertTempBasePath($this);

    expect(Artisan::call('echoledger:key'))->toBe(0);

    $env = (string) file_get_contents($this->env);
    expect($env)->toMatch('/^ECHOLEDGER_RECORDINGS_KEY=[A-Za-z0-9+\/]{43}=$/m');
    preg_match('/^ECHOLEDGER_RECORDINGS_KEY=(.+)$/m', $env, $m);
    expect(strlen((string) base64_decode($m[1], true)))->toBe(32);
});

it('appends the key to an existing file without disturbing other settings', function () {
    assertTempBasePath($this);
    file_put_contents($this->env, "APP_NAME=EchoLedger\nDB_CONNECTION=sqlite\n");

    Artisan::call('echoledger:key');

    $env = (string) file_get_contents($this->env);
    expect($env)->toStartWith("APP_NAME=EchoLedger\nDB_CONNECTION=sqlite")->toContain('ECHOLEDGER_RECORDINGS_KEY=');
});

it('refuses to replace an existing key, because that would orphan every stored recording', function () {
    assertTempBasePath($this);
    file_put_contents($this->env, "ECHOLEDGER_RECORDINGS_KEY=existing-key-value\n");

    $status = Artisan::call('echoledger:key');

    expect($status)->toBe(1)->and(file_get_contents($this->env))->toBe("ECHOLEDGER_RECORDINGS_KEY=existing-key-value\n")
        ->and(Artisan::output())->toContain('already set');
});

it('replaces the key only on request and leaves the rest of the file alone', function () {
    assertTempBasePath($this);
    file_put_contents($this->env, "APP_NAME=EchoLedger\nECHOLEDGER_RECORDINGS_KEY=existing-key-value\nQUEUE_CONNECTION=database\n");

    $status = Artisan::call('echoledger:key', ['--force' => true]);

    $env = (string) file_get_contents($this->env);
    expect($status)->toBe(0)
        ->and($env)->not->toContain('existing-key-value')
        ->and($env)->toContain("APP_NAME=EchoLedger\n")->toContain("QUEUE_CONNECTION=database\n")
        ->and(substr_count($env, 'ECHOLEDGER_RECORDINGS_KEY='))->toBe(1);
});

it('treats an empty value as unset and fills it in', function () {
    assertTempBasePath($this);
    file_put_contents($this->env, "ECHOLEDGER_RECORDINGS_KEY=\n");

    $status = Artisan::call('echoledger:key');

    expect($status)->toBe(0)->and((string) file_get_contents($this->env))->toMatch('/^ECHOLEDGER_RECORDINGS_KEY=.{44}$/m');
});

it('warns about backing the key up', function () {
    assertTempBasePath($this);

    Artisan::call('echoledger:key');

    expect(Artisan::output())->toContain('Back it up');
});
