<?php

declare(strict_types=1);

use App\Enums\SttProviderKey;
use App\Enums\SttUseCase;
use App\Filament\Pages\Settings;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\Setting;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Tests\Feature\Admin\Support\AdminTestHelpers;

uses(AdminTestHelpers::class);

beforeEach(function () {
    Filament::setCurrentPanel('admin');
});

/** A complete, valid form payload that differs from the defaults in every field. */
function adminChangedSettings(array $overrides = []): array
{
    return array_merge([
        'retention_days' => 45,
        'default_language' => 'ur',
        'display_timezone' => 'Asia/Karachi',
        'provider_live_captions' => 'elevenlabs',
        'provider_dictation' => 'azure_mai',
        'provider_final_transcript' => 'whispercpp_local',
        'provider_voice_note' => 'elevenlabs',
        'captions_channels' => 'client_only',
        'assemblyai_region' => 'eu',
        'assemblyai_streaming_model' => 'universal-streaming-english',
        'assemblyai_async_models' => ['universal-3-5-pro'],
        'elevenlabs_base_url' => 'https://eu.api.elevenlabs.io',
        'elevenlabs_model_id' => 'scribe_v2_medical',
        'azure_region' => 'centralindia',
        'azure_endpoint' => 'https://acme.cognitiveservices.azure.com',
        'azure_model' => 'standard',
        'whisper_base_url' => 'https://whisper.example.test',
        'whisper_chunk_seconds' => 300,
        'whisper_vad' => false,
        'app_pins' => ['sha256/'.str_repeat('A', 43).'=', 'sha256/'.str_repeat('B', 43).'='],
    ], $overrides);
}

function adminSettingsPage($test)
{
    return $test->panelComponent(Settings::class);
}

/** Select fields backed by an enum hold the enum case in form state; compare by its value. */
function adminPlainState(array $state): array
{
    return array_map(fn (mixed $value) => $value instanceof BackedEnum ? $value->value : $value, $state);
}

/** @param array<string, mixed> $expected */
function adminAssertFormShows($page, array $expected): void
{
    $page->assertFormSet(function (array $state) use ($expected): void {
        expect(array_intersect_key(adminPlainState($state), $expected))->toEqual($expected);
    });
}

it('renders the settings page for a super admin', function () {
    $this->signInAsAdmin();

    $this->get('/admin/settings')->assertOk()->assertSee('Retention', false)->assertSee('Save settings');
});

it('shows the stored defaults when nothing has been saved', function () {
    $this->signInAsAdmin();

    adminAssertFormShows(adminSettingsPage($this), [
        'retention_days' => 30,
        'default_language' => null,
        'display_timezone' => 'UTC',
        'provider_live_captions' => 'assemblyai',
        'provider_dictation' => 'assemblyai',
        'provider_final_transcript' => 'assemblyai',
        'provider_voice_note' => 'assemblyai',
        'captions_channels' => 'both',
        'assemblyai_region' => 'us',
        'assemblyai_streaming_model' => 'universal-3-6-pro',
        'elevenlabs_base_url' => 'https://api.elevenlabs.io',
        'elevenlabs_model_id' => 'scribe_v2',
        'azure_model' => 'MAI-Transcribe-2',
        'whisper_chunk_seconds' => 600,
        'whisper_vad' => true,
        'app_pins' => [],
    ]);
});

it('saves every field and reads each one back', function () {
    $this->signInAsAdmin();
    $input = adminChangedSettings();

    adminSettingsPage($this)
        ->fillForm($input)
        ->call('save')
        ->assertHasNoFormErrors()
        ->assertNotified('Settings saved');

    $stored = Setting::query()->pluck('value', 'key')->all();
    expect($stored)->toMatchArray([
        'retention_days' => 45,
        'provider.live_captions' => 'elevenlabs',
        'provider.dictation' => 'azure_mai',
        'provider.final_transcript' => 'whispercpp_local',
        'provider.voice_note' => 'elevenlabs',
        'captions.channels' => 'client_only',
        'default_language' => 'ur',
        'display.timezone' => 'Asia/Karachi',
        'assemblyai.region' => 'eu',
        'assemblyai.streaming_model' => 'universal-streaming-english',
        'assemblyai.async_models' => ['universal-3-5-pro'],
        'elevenlabs.base_url' => 'https://eu.api.elevenlabs.io',
        'elevenlabs.model_id' => 'scribe_v2_medical',
        'azure.region' => 'centralindia',
        'azure.endpoint' => 'https://acme.cognitiveservices.azure.com',
        'azure.model' => 'standard',
        'whisper.base_url' => 'https://whisper.example.test',
        'whisper.chunk_seconds' => 300,
        'whisper.vad' => false,
        'app.pins' => $input['app_pins'],
    ]);

    $settings = app(SettingsRepository::class);
    expect($settings->retentionDays())->toBe(45)
        ->and($settings->providerFor(SttUseCase::LiveCaptions))->toBe(SttProviderKey::ElevenLabs)
        ->and($settings->providerFor(SttUseCase::Dictation))->toBe(SttProviderKey::AzureMai)
        ->and($settings->providerFor(SttUseCase::FinalTranscript))->toBe(SttProviderKey::WhisperCppLocal)
        ->and($settings->providerFor(SttUseCase::VoiceNote))->toBe(SttProviderKey::ElevenLabs)
        ->and($settings->captionChannels())->toBe('client_only')
        ->and($settings->displayTimezone())->toBe('Asia/Karachi')
        ->and($settings->pins())->toBe($input['app_pins']);
});

it('shows the saved values when the page is opened again', function () {
    $this->signInAsAdmin();
    adminSettingsPage($this)->fillForm(adminChangedSettings())->call('save')->assertHasNoFormErrors();

    adminAssertFormShows(adminSettingsPage($this), adminChangedSettings());
});

it('hands the saved settings to paired devices', function () {
    $admin = $this->signInAsAdmin();
    adminSettingsPage($this)->fillForm(adminChangedSettings(['provider_final_transcript' => 'assemblyai', 'provider_live_captions' => 'assemblyai']))
        ->call('save')->assertHasNoFormErrors();
    $token = Device::factory()->create(['user_id' => $admin->id])->createToken('device', Device::ABILITIES)->plainTextToken;
    $this->app['auth']->forgetGuards();

    $config = $this->withToken($token)->getJson('/api/config')->assertOk();

    expect($config->json('retention_days'))->toBe(45)
        ->and($config->json('captions.channels'))->toBe('client_only')
        ->and($config->json('pins'))->toBe(adminChangedSettings()['app_pins'])
        ->and($config->json('providers.dictation.provider'))->toBe('azure_mai');
});

it('rejects a time zone that does not exist', function (string $zone) {
    $this->signInAsAdmin();

    adminSettingsPage($this)
        ->fillForm(adminChangedSettings(['display_timezone' => $zone]))
        ->call('save')
        ->assertHasFormErrors(['display_timezone']);

    expect(Setting::query()->count())->toBe(0);
})->with(['made up' => 'Mars/Phobos', 'blank' => '', 'wrong case' => 'asia/karachi', 'offset' => '+05:00', 'script' => '<script>alert(1)</script>']);

it('accepts any real IANA time zone, including UTC', function (string $zone) {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['display_timezone' => $zone]))->call('save')->assertHasNoFormErrors();

    expect(app(SettingsRepository::class)->displayTimezone())->toBe($zone);
})->with(['UTC', 'Asia/Karachi', 'America/New_York', 'Pacific/Kiritimati', 'Europe/London']);

it('offers the full IANA list in the time zone picker', function () {
    $this->signInAsAdmin();

    $options = adminSettingsPage($this)->instance()->getSchema('form')->getFlatFields()['display_timezone']->getOptions();

    expect($options)->toHaveKeys(['UTC', 'Asia/Karachi', 'America/New_York'])
        ->and(count($options))->toBe(count(timezone_identifiers_list()));
});

it('shows times in the newly chosen time zone straight after saving', function () {
    $admin = $this->signInAsAdmin();
    $session = CaptureSession::factory()->create(['user_id' => $admin->id, 'started_at' => Carbon::parse('2026-10-24 09:05:00', 'UTC')]);

    $this->get('/admin/sessions')->assertOk()->assertSee('Oct 24, 2026 · 9:05 AM');
    adminSettingsPage($this)->fillForm(adminChangedSettings(['display_timezone' => 'Asia/Karachi']))->call('save')->assertHasNoFormErrors();

    $this->get('/admin/sessions')->assertOk()->assertSee('Oct 24, 2026 · 2:05 PM')->assertDontSee('Oct 24, 2026 · 9:05 AM');
    expect($session->exists)->toBeTrue();
});

it('rejects a transcription provider that does not exist', function (string $field) {
    $this->signInAsAdmin();

    adminSettingsPage($this)
        ->fillForm(adminChangedSettings([$field => 'skynet']))
        ->call('save')
        ->assertHasFormErrors([$field]);

    expect(Setting::query()->count())->toBe(0);
})->with(['provider_live_captions', 'provider_dictation', 'provider_final_transcript', 'provider_voice_note']);

it('rejects a batch-only provider for live captions and dictation', function (string $field) {
    $this->signInAsAdmin();

    adminSettingsPage($this)
        ->fillForm(adminChangedSettings([$field => 'whispercpp_local']))
        ->call('save')
        ->assertHasFormErrors([$field]);
})->with(['provider_live_captions', 'provider_dictation']);

it('lets every provider be chosen for final transcripts and voice notes', function (string $provider) {
    $this->signInAsAdmin();

    adminSettingsPage($this)
        ->fillForm(adminChangedSettings(['provider_final_transcript' => $provider, 'provider_voice_note' => $provider]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(app(SettingsRepository::class)->providerFor(SttUseCase::FinalTranscript)->value)->toBe($provider);
})->with(array_map(fn (SttProviderKey $key) => $key->value, SttProviderKey::cases()));

it('enforces the 1 to 365 day retention range', function (mixed $days, bool $valid) {
    $this->signInAsAdmin();

    $page = adminSettingsPage($this)->fillForm(adminChangedSettings(['retention_days' => $days]))->call('save');

    $valid ? $page->assertHasNoFormErrors() : $page->assertHasFormErrors(['retention_days']);
    expect(Setting::query()->where('key', 'retention_days')->value('value'))->toBe($valid ? (int) $days : null);
})->with([
    'zero' => [0, false],
    'negative' => [-5, false],
    'too many' => [366, false],
    'text' => ['abc', false],
    'blank' => ['', false],
    'lower bound' => [1, true],
    'upper bound' => [365, true],
    'typical' => [30, true],
]);

it('limits the default language to 16 characters and stores blank as auto-detect', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['default_language' => str_repeat('x', 17)]))->call('save')
        ->assertHasFormErrors(['default_language' => 'max']);

    adminSettingsPage($this)->fillForm(adminChangedSettings(['default_language' => '  ']))->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->get('default_language'))->toBeNull();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['default_language' => ' en ']))->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->get('default_language'))->toBe('en');
});

it('rejects an unknown caption channel mode', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['captions_channels' => 'everyone']))->call('save')
        ->assertHasFormErrors(['captions_channels']);
});

it('rejects options that the choice lists do not offer', function (string $field, string $value) {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings([$field => $value]))->call('save')->assertHasFormErrors([$field]);
})->with([
    ['assemblyai_region', 'moon'],
    ['assemblyai_streaming_model', 'universal-9000'],
    ['elevenlabs_model_id', 'scribe_v9'],
    ['azure_model', 'GPT-Transcribe'],
]);

it('rejects malformed URLs for the provider endpoints', function (string $field) {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings([$field => 'not a url']))->call('save')->assertHasFormErrors([$field]);
})->with(['elevenlabs_base_url', 'azure_endpoint', 'whisper_base_url']);

it('only accepts HTTPS addresses for endpoints that receive API keys and bearer tokens', function (string $field, string $url) {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings([$field => $url]))->call('save')->assertHasFormErrors([$field]);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'whisper.cpp over file' => ['whisper_base_url', 'file:///etc/passwd'],
]);

it('refuses plain http and ftp for endpoints that receive API keys and bearer tokens', function (string $field, string $url) {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings([$field => $url]))->call('save')->assertHasFormErrors([$field]);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'ElevenLabs over http' => ['elevenlabs_base_url', 'http://api.elevenlabs.io'],
    'ElevenLabs over ftp' => ['elevenlabs_base_url', 'ftp://api.elevenlabs.io'],
    'Azure over http' => ['azure_endpoint', 'http://acme.cognitiveservices.azure.com'],
    'whisper.cpp over http' => ['whisper_base_url', 'http://whisper.example.test'],
]);

it('requires the ElevenLabs base URL but lets Azure and whisper.cpp endpoints stay empty', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['elevenlabs_base_url' => '']))->call('save')
        ->assertHasFormErrors(['elevenlabs_base_url' => 'required']);

    adminSettingsPage($this)->fillForm(adminChangedSettings(['azure_endpoint' => '', 'azure_region' => '', 'whisper_base_url' => '']))->call('save')
        ->assertHasNoFormErrors();
    $settings = app(SettingsRepository::class);
    expect($settings->get('azure.endpoint'))->toBeNull()
        ->and($settings->get('azure.region'))->toBeNull()
        ->and($settings->get('whisper.base_url'))->toBeNull();
});

it('strips trailing slashes from endpoint URLs', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings([
        'elevenlabs_base_url' => 'https://api.elevenlabs.io///',
        'azure_endpoint' => 'https://acme.cognitiveservices.azure.com/',
        'whisper_base_url' => 'https://whisper.example.test/',
    ]))->call('save')->assertHasNoFormErrors();

    $settings = app(SettingsRepository::class);
    expect($settings->get('elevenlabs.base_url'))->toBe('https://api.elevenlabs.io')
        ->and($settings->get('azure.endpoint'))->toBe('https://acme.cognitiveservices.azure.com')
        ->and($settings->get('whisper.base_url'))->toBe('https://whisper.example.test');
});

it('keeps the whisper.cpp slice length within 60 to 1800 seconds', function (mixed $seconds, bool $valid) {
    $this->signInAsAdmin();

    $page = adminSettingsPage($this)->fillForm(adminChangedSettings(['whisper_chunk_seconds' => $seconds]))->call('save');

    $valid ? $page->assertHasNoFormErrors() : $page->assertHasFormErrors(['whisper_chunk_seconds']);
})->with([
    'too short' => [59, false],
    'too long' => [1801, false],
    'text' => ['soon', false],
    'lower bound' => [60, true],
    'upper bound' => [1800, true],
]);

it('stores both states of the whisper.cpp VAD toggle', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['whisper_vad' => true]))->call('save');
    expect(app(SettingsRepository::class)->get('whisper.vad'))->toBeTrue();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['whisper_vad' => false]))->call('save');
    expect(app(SettingsRepository::class)->get('whisper.vad'))->toBeFalse();
});

it('falls back to the default AssemblyAI models when the list is emptied', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['assemblyai_async_models' => []]))->call('save')->assertHasNoFormErrors();

    expect(app(SettingsRepository::class)->get('assemblyai.async_models'))->toBe(['universal-3-5-pro', 'universal-2']);
});

it('stores the certificate pin set in order and drops blanks', function () {
    $this->signInAsAdmin();
    $pins = ['sha256/'.str_repeat('C', 43).'=', 'sha256/'.str_repeat('D', 43).'='];

    adminSettingsPage($this)->fillForm(adminChangedSettings(['app_pins' => $pins]))->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->pins())->toBe($pins);

    adminSettingsPage($this)->fillForm(adminChangedSettings(['app_pins' => []]))->call('save')->assertHasNoFormErrors();
    expect(app(SettingsRepository::class)->pins())->toBe([]);
});

it('rejects certificate pins that devices could not use', function (string $pin) {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['app_pins' => ['sha256/'.str_repeat('E', 43).'=', $pin]]))->call('save')
        ->assertHasFormErrors();

    expect(app(SettingsRepository::class)->pins())->toBe([]);
})->with([
    'no prefix' => [str_repeat('F', 43).'='],
    'wrong algorithm' => ['md5/'.str_repeat('F', 43).'='],
    'too short' => ['sha256/abc='],
    'not base64' => ['sha256/<script>alert(1)</script>'],
    'plain text' => ['please pin my certificate'],
]);

it('ignores form keys that are not part of the settings form', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)
        ->fillForm(adminChangedSettings())
        ->set('data.evil_key', 'injected')
        ->set('data.provider.final_transcript', 'skynet')
        ->call('save')
        ->assertHasNoFormErrors();

    expect(Setting::query()->where('key', 'evil_key')->exists())->toBeFalse()
        ->and(Setting::query()->where('key', 'like', '%evil%')->exists())->toBeFalse();
});

it('never shows stored API credentials on the settings page', function () {
    $this->signInAsAdmin();
    $vault = app(CredentialVault::class);
    $vault->put('assemblyai', 'api_key', 'aai-secret-key-1234567890');
    $vault->put('elevenlabs', 'api_key', 'xi-secret-key-0987654321');
    $vault->put('whispercpp', 'bearer_token', 'whisper-bearer-abcdef123456');

    $page = $this->get('/admin/settings')->assertOk()->getContent();
    $component = adminSettingsPage($this)->html();
    $saved = adminSettingsPage($this)->fillForm(adminChangedSettings())->call('save')->html();

    foreach ([$page, $component, $saved] as $output) {
        expect($output)->not->toContain('aai-secret-key-1234567890')
            ->not->toContain('xi-secret-key-0987654321')
            ->not->toContain('whisper-bearer-abcdef123456')
            ->not->toContain('7890')
            ->not->toContain('4321');
    }
});

it('denies the settings page to a non-admin account', function () {
    $this->actingAs($this->makeRegularUser());

    $this->get('/admin/settings')->assertForbidden();
});

it('hides the settings page from the navigation unless the user is a super admin', function () {
    $this->signInAsAdmin();
    expect(Settings::canAccess())->toBeTrue();

    auth()->logout();
    $this->actingAs($this->makeRegularUser());
    expect(Settings::canAccess())->toBeFalse();
});

it('survives a stored time zone that is no longer valid', function () {
    $this->signInAsAdmin();
    app(SettingsRepository::class)->set('display.timezone', 'Mars/Phobos');

    adminSettingsPage($this)->assertFormSet(['display_timezone' => 'UTC']);
    $this->get('/admin/sessions')->assertOk();
});

// --- Monthly limit and prices (A5.2) ---------------------------------------------------------------------------

it('shows no monthly limit, an 80 % warning and the default price table when nothing is saved', function () {
    $this->signInAsAdmin();

    adminAssertFormShows(adminSettingsPage($this), [
        'monthly_minutes' => null,
        'monthly_usd' => null,
        'warn_percent' => 80,
        'price__assemblyai__live_captions' => 0.45,
        'price__assemblyai__final_transcript' => 0.26,
        'price__elevenlabs__dictation' => 0.47,
        'price__elevenlabs__voice_note' => 0.27,
        'price__azure_mai__final_transcript' => 0.10,
    ]);
    expect(array_column(Settings::priceFields(), 2))->toContain('AssemblyAI · Live captions', 'ElevenLabs · Final transcript', 'Azure MAI · Voice notes')
        ->and(Settings::priceFields())->toHaveCount(10);
});

it('renders the monthly limit section with its labels and help text', function () {
    $this->signInAsAdmin();

    $this->get('/admin/settings')->assertOk()
        ->assertSee('Monthly limit')
        ->assertSee('Monthly limit (minutes)')
        ->assertSee('Monthly limit (US$)')
        ->assertSee('Warn at (%)')
        ->assertSee('Billable provider minutes: audio minutes × channels.')
        ->assertSee('Prices (US$ per audio hour per channel)')
        ->assertSee('Defaults follow the providers&#039; list prices. Edit them when you change model or plan.', false);
});

it('saves the monthly limits, the warning threshold and the prices', function () {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings([
        'monthly_minutes' => 3000,
        'monthly_usd' => '49.999',
        'warn_percent' => 75,
        'price__assemblyai__live_captions' => '0.1234',
        'price__azure_mai__voice_note' => 0,
    ]))->call('save')->assertHasNoFormErrors();

    $settings = app(SettingsRepository::class);
    $prices = $settings->get('budget.prices');
    expect($settings->get('budget.monthly_minutes'))->toBe(3000)
        ->and($settings->get('budget.monthly_usd'))->toEqual(50) // JSON keeps 50.0 as 50
        ->and($settings->get('budget.warn_percent'))->toBe(75)
        ->and($prices['assemblyai']['live_captions'])->toBe(0.1234)
        ->and($prices['assemblyai']['final_transcript'])->toBe(0.26)
        ->and($prices['azure_mai']['voice_note'])->toEqual(0.0)
        ->and(array_keys($prices))->toBe(['assemblyai', 'elevenlabs', 'azure_mai']);

    adminAssertFormShows(adminSettingsPage($this), ['monthly_minutes' => 3000, 'monthly_usd' => 50.0, 'warn_percent' => 75, 'price__assemblyai__live_captions' => 0.1234]);
});

it('clears a monthly limit when the field is emptied', function () {
    $this->signInAsAdmin();
    adminSettingsPage($this)->fillForm(adminChangedSettings(['monthly_minutes' => 600, 'monthly_usd' => 20]))->call('save')->assertHasNoFormErrors();

    adminSettingsPage($this)->fillForm(adminChangedSettings(['monthly_minutes' => '', 'monthly_usd' => null]))->call('save')->assertHasNoFormErrors();

    $settings = app(SettingsRepository::class);
    expect(Setting::query()->where('key', 'budget.monthly_minutes')->exists())->toBeTrue()
        ->and($settings->get('budget.monthly_minutes'))->toBeNull()
        ->and($settings->get('budget.monthly_usd'))->toBeNull();
});

it('rejects monthly limits and prices outside their ranges', function (string $field, mixed $value) {
    $this->signInAsAdmin();

    adminSettingsPage($this)->fillForm(adminChangedSettings([$field => $value]))->call('save')->assertHasFormErrors([$field]);

    expect(Setting::query()->count())->toBe(0);
})->with([
    'zero minutes' => ['monthly_minutes', 0],
    'negative minutes' => ['monthly_minutes', -10],
    'fractional minutes' => ['monthly_minutes', '12.5'],
    'text minutes' => ['monthly_minutes', 'lots'],
    'dollars below a cent' => ['monthly_usd', '0.001'],
    'negative dollars' => ['monthly_usd', -5],
    'warning at zero' => ['warn_percent', 0],
    'warning above 100' => ['warn_percent', 101],
    'warning left empty' => ['warn_percent', ''],
    'negative price' => ['price__elevenlabs__live_captions', -0.01],
    'price above 100' => ['price__assemblyai__dictation', 100.5],
    'price left empty' => ['price__azure_mai__final_transcript', ''],
]);

it('shows the defaults again when stored limit values are not usable', function () {
    $this->signInAsAdmin();
    $settings = app(SettingsRepository::class);
    $settings->set('budget.monthly_minutes', 'many');
    $settings->set('budget.warn_percent', 500);
    $settings->set('budget.prices', ['assemblyai' => ['live_captions' => 'free']]);

    adminAssertFormShows(adminSettingsPage($this), ['monthly_minutes' => null, 'warn_percent' => 80, 'price__assemblyai__live_captions' => 0.45]);
});
