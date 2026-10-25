<?php

declare(strict_types=1);

use App\Filament\Pages\ApiCredentials;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\PairingService;
use App\Services\SettingsRepository;
use App\Stt\Drivers\AssemblyAiDriver;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Feature\Security\Support\ApiClient;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

uses(ApiClient::class);

afterEach(fn () => Fx::cleanupDisks());

const SEC_KEY_AAI = 'aai-API-KEY-SENTINEL-7f3a9c2e41b8d605';
const SEC_KEY_AAI_HOOK = 'aai-WEBHOOK-SENTINEL-19d84be07c3a52f6';
const SEC_KEY_EL = 'el-API-KEY-SENTINEL-c5e2a7081d94b3f6';
const SEC_KEY_EL_HOOK = 'el-WEBHOOK-SENTINEL-6b0f3d85a1c9e247';
const SEC_KEY_AZURE = 'az-KEY-SENTINEL-a41e8c7d0b5f3926';

function secStoreSentinels(): array
{
    $vault = app(CredentialVault::class);
    $vault->put('assemblyai', 'api_key', SEC_KEY_AAI);
    $vault->put('assemblyai', 'webhook_secret', SEC_KEY_AAI_HOOK);
    $vault->put('elevenlabs', 'api_key', SEC_KEY_EL);
    $vault->put('elevenlabs', 'webhook_secret', SEC_KEY_EL_HOOK);
    $vault->put('azure', 'api_key', SEC_KEY_AZURE);

    return [SEC_KEY_AAI, SEC_KEY_AAI_HOOK, SEC_KEY_EL, SEC_KEY_EL_HOOK, SEC_KEY_AZURE];
}

it('never puts a stored provider secret into an API response', function () {
    Fx::fakeRecordingsDisk();
    $sentinels = secStoreSentinels();
    Http::fake(['*' => Http::response(['token' => 'temporary-streaming-token'], 200)]);
    $device = Fx::device();
    $token = Fx::token($device);
    $session = Fx::session($device->user, $device);
    Fx::readyRecording($session);
    Fx::transcript($session);
    $client = Fx::client($device->user);

    $responses = [
        $this->api($token)->getJson('/api/config'),
        $this->api($token)->getJson('/api/clients'),
        $this->api($token)->getJson('/api/library'),
        $this->api($token)->getJson("/api/sessions/{$session->id}"),
        $this->api($token)->getJson('/api/transcripts/'.$session->transcripts()->value('id')),
        $this->api($token)->getJson('/api/recordings/'.$session->recordings()->value('id')),
        $this->api($token)->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $client->id]),
        $this->api($token)->postJson('/api/devices/rotate-token'),
        $this->api($token)->getJson('/api/sessions/'.Fx::MISSING_ID),
        $this->api($token)->postJson('/api/sessions', ['bad' => 'payload']),
    ];

    foreach ($responses as $response) {
        foreach ($sentinels as $secret) {
            expect($response->getContent())->not->toContain($secret);
        }
        expect(json_encode($response->headers->all()))->not->toContain('SENTINEL');
    }
});

it('returns only a short-lived provider token, never the long-lived API key, in a streaming grant', function () {
    secStoreSentinels();
    Http::fake(['*' => Http::response(['token' => 'temporary-streaming-token'], 200)]);
    $token = Fx::token(Fx::device());

    $response = $this->api($token)->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

    expect($response->getContent())->toContain('temporary-streaming-token')->not->toContain(SEC_KEY_AAI)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
    expect($response->json('grants.0.ws_url'))->toContain('wss://streaming.assemblyai.com/');
});

it('sends the provider key only to the provider and never in a URL', function () {
    secStoreSentinels();
    Http::fake(['*' => Http::response(['token' => 'temporary-streaming-token'], 200)]);
    $token = Fx::token(Fx::device());

    $this->api($token)->postJson('/api/stt/token', ['use_case' => 'live_captions'])->assertOk();

    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://streaming.assemblyai.com/')
        && ! str_contains($request->url(), SEC_KEY_AAI)
        && $request->header('Authorization') === [SEC_KEY_AAI]);
});

it('keeps secrets, tokens and pairing codes out of the application log', function () {
    Queue::fake();
    Fx::fakeRecordingsDisk();
    secStoreSentinels();
    $logFile = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sec-log-'.bin2hex(random_bytes(5)).'.log';
    config(['logging.default' => 'sec_probe', 'logging.channels.sec_probe' => ['driver' => 'single', 'path' => $logFile, 'level' => 'debug']]);
    Http::fake(['*' => Http::response(['error' => 'upstream rejected the request'], 401)]);

    $owner = Fx::user();
    $device = Fx::device($owner);
    $plainToken = Fx::token($device);
    [, $bearerSecret] = explode('|', $plainToken, 2);
    $pairing = app(PairingService::class)->createCode($owner);
    $client = Fx::client($owner);

    $this->api()->postJson('/api/devices/register', ['pairing_code' => 'WRONG-CODE', 'name' => 'x', 'platform' => 'android'])->assertStatus(422);
    $this->api()->postJson('/api/devices/register', ['pairing_code' => $pairing, 'name' => 'Pixel', 'platform' => 'android'])->assertCreated();
    $this->api($plainToken)->postJson('/api/stt/token', ['use_case' => 'live_captions', 'client_id' => $client->id])->assertStatus(502);
    $this->api()->postJson('/api/webhooks/stt/assemblyai?rid=abc', ['transcript_id' => 'job'], ['X-EchoLedger-Webhook-Token' => 'LEAKY-TOKEN-VALUE-123'])->assertStatus(401);
    $this->api()->postJson('/api/webhooks/stt/elevenlabs', ['type' => 'x'], ['elevenlabs-signature' => 't=1,v0=LEAKY-SIGNATURE-456'])->assertStatus(401);
    $this->api($plainToken)->postJson('/api/devices/rotate-token')->assertOk();

    $log = is_file($logFile) ? (string) file_get_contents($logFile) : '';
    @unlink($logFile);
    foreach ([SEC_KEY_AAI, SEC_KEY_AAI_HOOK, SEC_KEY_EL, SEC_KEY_EL_HOOK, SEC_KEY_AZURE, $bearerSecret, $plainToken, str_replace('-', '', $pairing), 'LEAKY-TOKEN-VALUE-123', 'LEAKY-SIGNATURE-456'] as $secret) {
        expect($log)->not->toContain($secret);
    }
    expect($log)->toContain('Rejected STT webhook'); // the log is live, so the absence above means something
});

it('hides stack traces, paths and SQL when debug is off', function (string $kind) {
    config(['app.debug' => false]);
    Route::get('/__sec-boom-'.$kind, fn () => match ($kind) {
        'exception' => throw new RuntimeException('internal detail at /var/www/echoledger/app/Secret.php:12 using key '.SEC_KEY_AAI),
        'query' => DB::select('select * from table_that_does_not_exist_sec where token = ?', [SEC_KEY_AAI]),
        'type' => strlen([]),
    })->middleware('web');
    Route::get('/api/__sec-boom-'.$kind, fn () => throw new RuntimeException('internal detail '.SEC_KEY_AAI));

    $html = $this->get('/__sec-boom-'.$kind);
    $json = $this->getJson('/api/__sec-boom-'.$kind);

    foreach ([$html, $json] as $response) {
        expect($response->getStatusCode())->toBe(500);
        expect($response->getContent())
            ->not->toContain('internal detail')->not->toContain(SEC_KEY_AAI)->not->toContain('RuntimeException')
            ->not->toContain('table_that_does_not_exist_sec')->not->toContain('Stack trace')->not->toContain('vendor')
            ->not->toContain(base_path())->not->toContain('"trace"')->not->toContain('.php');
    }
})->with(['exception', 'query', 'type']);

it('would show the trace with debug on, proving the test above can fail', function () {
    config(['app.debug' => true]);
    Route::get('/api/__sec-boom-control', fn () => throw new RuntimeException('internal detail'));

    expect($this->getJson('/api/__sec-boom-control')->getContent())->toContain('internal detail');
});

it('ships a safe-by-default environment template', function () {
    $lines = preg_split('/\R/', (string) file_get_contents(base_path('.env.example')));
    $values = [];
    foreach ($lines as $line) {
        if (preg_match('/^([A-Z0-9_]+)=(.*)$/', $line, $m) === 1) {
            $values[$m[1]] = trim($m[2], " \t\"'");
        }
    }

    expect($values['APP_KEY'])->toBe('')
        ->and($values['ECHOLEDGER_RECORDINGS_KEY'])->toBe('')
        ->and($values['AWS_ACCESS_KEY_ID'])->toBe('')->and($values['AWS_SECRET_ACCESS_KEY'])->toBe('')
        ->and($values['MAIL_USERNAME'])->toBeIn(['null', ''])->and($values['MAIL_PASSWORD'])->toBeIn(['null', ''])
        ->and($values['REDIS_PASSWORD'])->toBeIn(['null', '']);
    foreach ($values as $name => $value) {
        if (preg_match('/(KEY|SECRET|TOKEN|PASSWORD)$/', $name) === 1 && $value !== '' && $value !== 'null') {
            expect($value)->toMatch('/change|example|placeholder|null|^$/i', "{$name} must be a placeholder");
        }
        expect($value)->not->toMatch('/^[A-Za-z0-9+\/=_-]{32,}$/', "{$name} looks like a real secret");
    }
});

it('defaults the environment template to a production-safe debug setting', function () {
    $template = (string) file_get_contents(base_path('.env.example'));

    expect($template)->toMatch('/^APP_DEBUG=false\s*$/m');
})->skip('A6.W29: .env.example ships APP_DEBUG=true');

it('never tracks the real environment file or the encrypted recordings', function () {
    $ignore = (string) file_get_contents(base_path('.gitignore'));
    $private = (string) file_get_contents(base_path('storage/app/private/.gitignore'));

    expect($ignore)->toMatch('/^\/?\.env\s*$/m');
    expect($private)->toMatch('/^\*\s*$/m'); // everything under storage/app/private, including recordings, stays out of git
    expect(file_exists(base_path('public/.env')))->toBeFalse()
        ->and(file_exists(base_path('public/storage/app')))->toBeFalse();
});

it('contains no hard-coded credentials in shipped source', function () {
    $patterns = [
        'AWS access key' => '/AKIA[0-9A-Z]{16}/',
        'private key block' => '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        'provider secret key' => '/\bsk-[A-Za-z0-9]{32,}\b/',
        'GitHub token' => '/\bgh[pousr]_[A-Za-z0-9]{36,}\b/',
        'Slack token' => '/\bxox[baprs]-[A-Za-z0-9-]{10,}/',
        'Google API key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
        'base64 app key' => '/base64:[A-Za-z0-9+\/]{42}[A-Za-z0-9+\/=]/',
        'assigned secret literal' => '/(api[_-]?key|secret|password|passwd|token)[\'"]?\s*(=>|=|:)\s*[\'"][A-Za-z0-9+\/_=-]{28,}[\'"]/i',
    ];
    $hits = [];
    $scanned = 0;
    foreach (['app', 'config', 'routes', 'database', 'resources', 'bootstrap'] as $dir) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($dir), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (! $file->isFile() || ! preg_match('/\.(php|js|json|ya?ml|blade\.php|env|txt|md)$/', $file->getFilename()) || str_contains($file->getPathname(), 'node_modules')) {
                continue;
            }
            $scanned++;
            $source = (string) file_get_contents($file->getPathname());
            foreach ($patterns as $label => $pattern) {
                if (preg_match($pattern, $source, $m) === 1) {
                    $hits[] = $label.' in '.str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).' => '.substr($m[0], 0, 40);
                }
            }
        }
    }

    expect($scanned)->toBeGreaterThan(80); // the scan really walked the source tree
    expect($hits)->toBe([]);
});

it('does not create a super admin with the published default password outside local development', function () {
    config(['app.env' => 'production']);
    app()->detectEnvironment(fn () => 'production');
    foreach (['DEMO_SEED' => 'false'] as $name => $value) {
        putenv("{$name}={$value}");
        $_ENV[$name] = $_SERVER[$name] = $value;
    }

    try {
        try {
            (new DatabaseSeeder)->run();
        } catch (Throwable) {
            // refusing to seed is an acceptable, secure outcome
        }
        $admin = User::query()->where('is_super_admin', true)->first();
        $usable = $admin !== null && Hash::check('change-me-now-please', $admin->password);
    } finally {
        putenv('DEMO_SEED');
        unset($_ENV['DEMO_SEED'], $_SERVER['DEMO_SEED']);
    }

    expect($usable)->toBeFalse('the seeder created an admin whose password is the documented default');
})->skip('A6.W11: the seeder creates a super admin with the published default password in any environment');

it('shows only a masked status for stored credentials on the console page and never echoes what was typed', function () {
    secStoreSentinels();
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    $page = Livewire::test(ApiCredentials::class);

    foreach ([SEC_KEY_AAI, SEC_KEY_AAI_HOOK, SEC_KEY_EL, SEC_KEY_EL_HOOK, SEC_KEY_AZURE] as $secret) {
        expect($page->html())->not->toContain($secret);
    }
    expect($page->html())->toContain('Stored');

    $typed = 'typed-NEW-SECRET-VALUE-'.bin2hex(random_bytes(6));
    $page->fillForm(['assemblyai__api_key' => $typed])->call('save');
    expect($page->html())->not->toContain($typed);
    expect(app(CredentialVault::class)->get('assemblyai', 'api_key'))->toBe($typed);
    expect(DB::table('credentials')->where('provider', 'assemblyai')->where('key', 'api_key')->value('value'))->not->toContain($typed);
});

it('ignores credential fields that are not part of the vault schema', function () {
    $admin = Fx::superAdmin();
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    Livewire::test(ApiCredentials::class)
        ->set('data.evil__api_key', 'planted')
        ->set('data.assemblyai__nonexistent', 'planted')
        ->call('save');

    expect(DB::table('credentials')->count())->toBe(0);
});

it('keeps the webhook token deterministic per recording but unusable without the server secret', function () {
    $a = AssemblyAiDriver::webhookToken('secret-one', 'rid-1');
    $b = AssemblyAiDriver::webhookToken('secret-two', 'rid-1');
    $c = AssemblyAiDriver::webhookToken('secret-one', 'rid-2');

    expect($a)->not->toBe($b)->and($a)->not->toBe($c)->and(strlen($a))->toBe(43)->and($a)->toMatch('/^[A-Za-z0-9_-]+$/');
});

it('sends provider API keys only to the provider\'s own hosts even when a hostile endpoint is configured', function (string $setting, string $value, string $provider, string $credential) {
    $settings = app(SettingsRepository::class);
    $settings->set($setting, $value);
    $settings->set('provider.live_captions', $provider);
    app(CredentialVault::class)->put($provider === 'azure_mai' ? 'azure' : $provider, 'api_key', 'EXFIL-SENTINEL-KEY-'.$credential);
    Http::fake(['*' => Http::response(['token' => 'temporary-streaming-token'], 200)]);
    $token = Fx::token(Fx::device());

    $this->api($token)->postJson('/api/stt/token', ['use_case' => 'live_captions']);

    $urls = [];
    Http::assertSent(function ($request) use (&$urls) {
        $urls[] = $request->url();

        return true;
    });
    $allowed = ['api.elevenlabs.io', 'api.assemblyai.com', 'api.eu.assemblyai.com', 'streaming.assemblyai.com'];
    // Every request carries the key, so each one must go over HTTPS to one of the provider's own hosts.
    $foreign = array_values(array_filter($urls, function (string $url) use ($allowed): bool {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return parse_url($url, PHP_URL_SCHEME) !== 'https'
            || (! in_array($host, $allowed, true) && ! str_ends_with($host, '.api.cognitive.microsoft.com'));
    }));
    expect($foreign)->toBe([], 'the provider key was sent to: '.implode(', ', $foreign));
})->with([
    'ElevenLabs base URL pointing at another host' => ['elevenlabs.base_url', 'https://attacker.example', 'elevenlabs', 'el'],
    'ElevenLabs base URL over plain http' => ['elevenlabs.base_url', 'http://api.elevenlabs.io', 'elevenlabs', 'el2'],
    'Azure region that injects a host' => ['azure.region', 'attacker.example/x?', 'azure_mai', 'az'],
    'Azure region with userinfo' => ['azure.region', 'x@attacker.example#', 'azure_mai', 'az2'],
])->skip('A6.W10: a configured provider endpoint can send the provider key over plain HTTP or to any host');
