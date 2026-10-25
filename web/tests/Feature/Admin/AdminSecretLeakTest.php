<?php

declare(strict_types=1);

use App\Filament\Pages\ApiCredentials;
use App\Filament\Resources\CaptureSessions\Pages\ListCaptureSessions;
use App\Filament\Resources\Devices\Pages\ListDevices;
use App\Models\Client;
use App\Models\Device;
use App\Services\CredentialVault;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\AssertionFailedError;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/**
 * Plants recognisable secrets everywhere the product keeps them and returns name => value.
 *
 * @return array<string, string>
 */
function adminPlantSecrets($test): array
{
    $admin = $test->makeAdmin(['name' => 'Farah Khan']);
    $admin->forceFill(['remember_token' => 'remember-canary-0123456789abcdef'])->save();
    $totp = AppAuthentication::make();
    $totpSecret = $totp->generateSecret();
    $totp->saveSecret($admin, $totpSecret);
    $totp->saveRecoveryCodes($admin, ['recovery-canary-0001', 'recovery-canary-0002']);

    $vault = app(CredentialVault::class);
    $keys = [
        'assemblyai key' => ['assemblyai', 'api_key', 'aai-canary-api-key-ABCDEFGHIJ0001'],
        'assemblyai webhook secret' => ['assemblyai', 'webhook_secret', 'aai-canary-webhook-ABCDEFGHIJ0002'],
        'elevenlabs key' => ['elevenlabs', 'api_key', 'xi-canary-api-key-ABCDEFGHIJ0003'],
        'elevenlabs webhook secret' => ['elevenlabs', 'webhook_secret', 'xi-canary-hmac-ABCDEFGHIJ0004'],
        'elevenlabs webhook id' => ['elevenlabs', 'webhook_id', 'xi-canary-webhook-id-ABCDEFGHIJ0005'],
        'azure key' => ['azure', 'api_key', 'az-canary-speech-key-ABCDEFGHIJ0006'],
        'whisper token' => ['whispercpp', 'bearer_token', 'wh-canary-bearer-ABCDEFGHIJ0007'],
        'openai key' => ['openai', 'api_key', 'sk-canary-openai-ABCDEFGHIJ0008'],
        'groq key' => ['groq', 'api_key', 'gsk-canary-groq-ABCDEFGHIJ0009'],
    ];
    $secrets = [];
    foreach ($keys as $name => [$provider, $key, $value]) {
        $vault->put($provider, $key, $value, $admin->id);
        $secrets[$name] = $value;
    }
    foreach (DB::table('credentials')->pluck('value') as $i => $cipher) {
        $secrets["credential ciphertext {$i}"] = $cipher;
    }

    $device = Device::factory()->create(['user_id' => $admin->id, 'name' => 'Pixel 9']);
    $plainToken = $device->createToken('device', Device::ABILITIES)->plainTextToken;
    $secrets['device token (full)'] = $plainToken;
    $secrets['device token (secret part)'] = explode('|', $plainToken, 2)[1];
    $secrets['device token (stored hash)'] = (string) DB::table('personal_access_tokens')->value('token');

    $recordingsKey = (string) config('echoledger.recordings_key');
    $secrets['recordings key (base64)'] = $recordingsKey;
    $secrets['recordings key (hex)'] = bin2hex((string) base64_decode($recordingsKey, true));
    $appKey = (string) config('app.key');
    $secrets['app key'] = $appKey;
    $secrets['app key (base64 part)'] = str_replace('base64:', '', $appKey);

    $secrets['totp secret'] = $totpSecret;
    $secrets['recovery code 1'] = 'recovery-canary-0001';
    $secrets['recovery code 2'] = 'recovery-canary-0002';
    $secrets['recovery code hashes'] = (string) DB::table('users')->where('id', $admin->id)->value('app_authentication_recovery_codes');
    $secrets['totp secret ciphertext'] = (string) DB::table('users')->where('id', $admin->id)->value('app_authentication_secret');
    $secrets['password hash'] = (string) $admin->fresh()->password;
    $secrets['remember token'] = 'remember-canary-0123456789abcdef';

    $test->actingAs($admin, 'web');
    Filament::setCurrentPanel('admin');

    return $secrets;
}

function adminAssertNoSecrets(array $secrets, string $output, string $where): void
{
    foreach ($secrets as $name => $value) {
        if ($value === '') {
            continue;
        }
        Assert::assertStringNotContainsString($value, $output, "{$name} leaked into {$where}");
    }
}

it('keeps the planted secrets valid so the leak checks are meaningful', function () {
    $secrets = adminPlantSecrets($this);

    expect($secrets['recordings key (base64)'])->not->toBeEmpty()
        ->and($secrets['app key'])->not->toBeEmpty()
        ->and($secrets['device token (secret part)'])->toStartWith('eld_')
        ->and(app(CredentialVault::class)->get('assemblyai', 'api_key'))->toBe('aai-canary-api-key-ABCDEFGHIJ0001');
});

it('would notice a leak: the checker fails when a planted secret is present in the output', function () {
    $secrets = adminPlantSecrets($this);

    foreach (['recordings key (base64)', 'assemblyai key', 'device token (secret part)', 'totp secret', 'app key'] as $name) {
        expect(fn () => adminAssertNoSecrets($secrets, '<p>leaked: '.$secrets[$name].'</p>', 'a canary page'))
            ->toThrow(AssertionFailedError::class);
    }
});

it('never renders tokens, API keys, the recordings key or other secrets on any console page', function () {
    $secrets = adminPlantSecrets($this);
    $admin = auth()->user();
    $client = Client::factory()->create(['user_id' => $admin->id, 'name' => 'Acme Holdings']);
    $client->keyterms()->create(['term' => 'Falcon']);
    $session = $this->sessionWithTranscript($admin, [
        ['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1000, 'text' => 'Hello'],
        ['channel' => 2, 'speaker' => 'Client', 'start_ms' => 1000, 'end_ms' => 2000, 'text' => 'Hi'],
    ], ['client_id' => $client->id, 'device_id' => Device::query()->first()->id], transcript: ['provider_job_id' => 'job-canary-1', 'meta' => ['webhook_token' => 'per-recording-canary']]);
    $recording = $this->readyRecording($session);
    $secrets['per-recording webhook canary'] = 'per-recording-canary';
    $secrets['recording storage path'] = (string) $recording->stored_path;

    $urls = [
        '/admin', '/admin/sessions', '/admin/sessions/'.$session->id, '/admin/clients', '/admin/clients/create',
        '/admin/clients/'.$client->id.'/edit', '/admin/devices', '/admin/settings', '/admin/api-credentials', '/admin/profile',
        '/admin/does-not-exist', '/definitely-not-a-page',
    ];
    foreach ($urls as $url) {
        $response = $this->get($url);
        adminAssertNoSecrets($secrets, (string) $response->getContent(), $url);
        foreach ($response->headers->allPreserveCase() as $header => $values) {
            adminAssertNoSecrets($secrets, $header.': '.implode(', ', $values), "response headers of {$url}");
        }
    }
});

it('never lets the audio route reveal the storage path, key or token', function () {
    $secrets = adminPlantSecrets($this);
    $session = $this->sessionWithTranscript(auth()->user(), [['channel' => 1, 'speaker' => 'Me', 'start_ms' => 0, 'end_ms' => 1, 'text' => 'x']]);
    $recording = $this->readyRecording($session, 'plain-audio-bytes');
    $secrets['recording storage path'] = (string) $recording->stored_path;

    $response = $this->get(route('admin.recordings.stream', $recording));

    foreach ($response->headers->allPreserveCase() as $header => $values) {
        adminAssertNoSecrets($secrets, $header.': '.implode(', ', $values), 'audio response headers');
    }
});

it('never leaks secrets through the login page or a failed login', function () {
    $secrets = adminPlantSecrets($this);
    auth()->logout();

    adminAssertNoSecrets($secrets, $this->get('/admin/login')->getContent(), 'the login page');
    $failed = $this->panelComponent(Login::class)
        ->fillForm(['email' => 'nobody@example.test', 'password' => 'wrong'])
        ->call('authenticate');
    adminAssertNoSecrets($secrets, $failed->html(), 'a failed login');
    adminAssertNoSecrets($secrets, json_encode($failed->snapshot), 'the login snapshot');
});

it('never leaks secrets through the components, their snapshots or modals', function () {
    $secrets = adminPlantSecrets($this);

    $credentials = $this->panelComponent(ApiCredentials::class);
    adminAssertNoSecrets($secrets, $credentials->html(), 'the credentials component');
    adminAssertNoSecrets($secrets, json_encode($credentials->snapshot), 'the credentials snapshot');

    $saved = $credentials->fillForm(['groq__api_key' => 'gsk-new-canary-ZZZZZZ9999'])->call('save');
    adminAssertNoSecrets($secrets + ['typed secret' => 'gsk-new-canary-ZZZZZZ9999'], $saved->html(), 'the credentials component after saving');
    adminAssertNoSecrets($secrets + ['typed secret' => 'gsk-new-canary-ZZZZZZ9999'], json_encode($saved->snapshot), 'the credentials snapshot after saving');

    $devices = $this->panelComponent(ListDevices::class)->mountAction('pair');
    adminAssertNoSecrets($secrets, $devices->html().$this->modalHtml($devices), 'the pairing dialog');
    adminAssertNoSecrets($secrets, json_encode($devices->snapshot), 'the pairing snapshot');

    $sessions = $this->panelComponent(ListCaptureSessions::class);
    adminAssertNoSecrets($secrets, $sessions->html(), 'the sessions list');
    adminAssertNoSecrets($secrets, json_encode($sessions->snapshot), 'the sessions snapshot');
});

it('shows the last four characters of a stored key only on the credentials page', function () {
    $secrets = adminPlantSecrets($this);

    $credentials = $this->get('/admin/api-credentials')->getContent();
    $tails = ['ABCDEFGHIJ0001' => '0001', 'ABCDEFGHIJ0006' => '0006'];
    foreach ($tails as $tail) {
        expect($credentials)->toContain('ends …'.$tail);
    }

    foreach (['/admin', '/admin/settings', '/admin/devices', '/admin/sessions', '/admin/profile'] as $url) {
        expect($this->get($url)->getContent())->not->toContain('ends …');
    }
    expect($secrets)->not->toBeEmpty();
});

it('hides the secret fields\' values from the HTML source of the credentials form', function () {
    adminPlantSecrets($this);

    $html = $this->get('/admin/api-credentials')->getContent();

    expect(preg_match_all('/<input[^>]*type="password"[^>]*>/', $html, $inputs))->toBeGreaterThanOrEqual(9);
    foreach ($inputs[0] as $input) {
        expect($input)->not->toMatch('/\svalue="[^"]+"/');
    }
});
