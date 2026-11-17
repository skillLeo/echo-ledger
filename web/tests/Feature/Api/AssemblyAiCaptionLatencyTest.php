<?php

declare(strict_types=1);

use App\Filament\Admin\Pages\Settings;
use App\Models\CaptureSession;
use App\Models\Device;
use App\Models\User;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Services\SettingsTransfer;
use App\Stt\Drivers\AssemblyAiDriver;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;

/*
 * Live captions on Universal-3.6 Pro followed speech by 30 to 60 s: the grant set no turn settings, so the server's
 * slower preset decided when a line ended. The grant now asks for the fastest preset (mode=min_latency) and its turn
 * silences, and keeps the chosen languages (Urdu included) in UpdateConfiguration.
 */

function captionLatencyGrant(array $body = []): array
{
    app(CredentialVault::class)->put('assemblyai', 'api_key', 'aai_test_key');
    Http::fake(['*' => Http::response(['token' => 'tmp_tok'], 200)]);
    $device = Device::factory()->create();
    Sanctum::actingAs($device, Device::ABILITIES);
    $session = CaptureSession::factory()->open()->create(['user_id' => $device->user_id, 'device_id' => $device->id]);

    $response = test()->postJson('/api/stt/token', ['use_case' => 'live_captions', 'session_id' => $session->id, 'channels' => ['me', 'client'], ...$body]);
    $response->assertOk()->assertJsonPath('provider', 'assemblyai');

    return $response->json();
}

/** @return array<string, string> */
function captionWsQuery(string $url): array
{
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    return $query;
}

it('asks Universal-3.6 Pro for the fastest turn preset by default, on every channel', function () {
    $grant = captionLatencyGrant();

    expect($grant['grants'])->toHaveCount(2);
    foreach ($grant['grants'] as $channel) {
        $q = captionWsQuery($channel['ws_url']);
        expect($q['speech_model'])->toBe('universal-3-6-pro')
            ->and($q['mode'])->toBe('min_latency')
            ->and($q['min_turn_silence'])->toBe('80')
            ->and($q['max_turn_silence'])->toBe('640')
            ->and($q['token'])->toBe('tmp_tok')
            // Not a Pro parameter (Universal-Streaming only, absent from the current spec): never sent.
            ->and($q)->not->toHaveKey('end_of_turn_confidence_threshold')
            ->and($q)->not->toHaveKey('format_turns');
    }
});

it('keeps Urdu steering on the low-latency stream', function () {
    $grant = captionLatencyGrant(['language' => 'ur']);

    $q = captionWsQuery($grant['grants'][0]['ws_url']);
    expect($q['speech_model'])->toBe('universal-3-6-pro')->and($q['mode'])->toBe('min_latency');
    $update = collect($grant['grants'][0]['init_messages'])->firstWhere('type', 'UpdateConfiguration');
    expect($update)->not->toBeNull()->and($update['language_codes'])->toContain('ur');
});

it('follows the caption speed chosen in Admin › Settings', function (string $mode, int $min, int $max) {
    app(SettingsRepository::class)->set('assemblyai.streaming_mode', $mode);

    $q = captionWsQuery(captionLatencyGrant()['grants'][0]['ws_url']);

    expect($q['mode'])->toBe($mode)
        ->and((int) $q['min_turn_silence'])->toBe($min)
        ->and((int) $q['max_turn_silence'])->toBe($max);
})->with([
    'balanced' => ['balanced', 128, 1280],
    'max_accuracy' => ['max_accuracy', 512, 2560],
]);

it('falls back to the fastest preset for an unknown stored value', function () {
    app(SettingsRepository::class)->set('assemblyai.streaming_mode', 'warp_speed');

    $q = captionWsQuery(captionLatencyGrant()['grants'][0]['ws_url']);

    expect($q['mode'])->toBe('min_latency')->and($q['max_turn_silence'])->toBe('640');
});

it('sends only the turn silences to the budget Universal-Streaming models, which have no presets', function () {
    app(SettingsRepository::class)->set('assemblyai.streaming_model', 'universal-streaming-multilingual');

    $q = captionWsQuery(captionLatencyGrant()['grants'][0]['ws_url']);

    expect($q)->not->toHaveKey('mode')
        ->and($q['min_turn_silence'])->toBe('80')
        ->and($q['max_turn_silence'])->toBe('640');
});

it('steers languages and sets presets on Universal-3.6 Pro only', function () {
    $driver = app(AssemblyAiDriver::class);

    expect(AssemblyAiDriver::streamingSupportsLanguages('universal-3-6-pro'))->toBeTrue()
        ->and(AssemblyAiDriver::streamingSupportsLanguages('assemblyai-stt-3-7-realtime'))->toBeFalse()
        ->and(AssemblyAiDriver::streamingSupportsLanguages('universal-streaming-english'))->toBeFalse()
        ->and($driver->turnSettings('universal-3-6-pro'))->toHaveKey('mode')
        ->and($driver->turnSettings('assemblyai-stt-3-7-realtime'))->not->toHaveKey('mode');
});

it('imports only a known caption speed from a settings file', function () {
    $transfer = app(SettingsTransfer::class);
    $read = fn (mixed $v): array => $transfer->read(json_encode(['format' => SettingsTransfer::FORMAT, 'settings' => ['assemblyai.streaming_mode' => $v, 'assemblyai.region' => 'eu']]));

    expect($read('balanced'))->toBe(['assemblyai.streaming_mode' => 'balanced', 'assemblyai.region' => 'eu'])
        ->and($read('warp_speed'))->toBe(['assemblyai.region' => 'eu'])
        ->and($read(['min_latency']))->toBe(['assemblyai.region' => 'eu'])
        ->and($read(5))->toBe(['assemblyai.region' => 'eu']);
});

it('saves the caption speed from Admin › Settings and refuses an unknown one', function () {
    $admin = User::factory()->create(['is_super_admin' => true]);
    $this->actingAs($admin);
    Filament::setCurrentPanel('admin');

    expect(app(SettingsRepository::class)->get('assemblyai.streaming_mode'))->toBe('min_latency');

    Livewire::test(Settings::class)
        ->assertSet('data.assemblyai_streaming_mode', 'min_latency')
        ->set('data.assemblyai_streaming_mode', 'nope')
        ->call('save')
        ->assertHasErrors(['data.assemblyai_streaming_mode']);

    Livewire::test(Settings::class)
        ->set('data.assemblyai_streaming_mode', 'balanced')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SettingsRepository::class)->get('assemblyai.streaming_mode'))->toBe('balanced');
});

describe('Fastest\'s quiet before a line may end (target: words on screen within a second)', function () {
    it('is 80 ms by default and follows the admin\'s value, only under Fastest', function () {
        expect(captionWsQuery(captionLatencyGrant()['grants'][0]['ws_url'])['min_turn_silence'])->toBe('80');

        app(SettingsRepository::class)->set('assemblyai.fastest_min_silence_ms', 50);
        expect(captionWsQuery(captionLatencyGrant()['grants'][0]['ws_url'])['min_turn_silence'])->toBe('50');

        app(SettingsRepository::class)->set('assemblyai.streaming_mode', 'balanced');
        expect(captionWsQuery(captionLatencyGrant()['grants'][0]['ws_url'])['min_turn_silence'])->toBe('128');
    });

    it('never goes under the provider\'s floor of 50 or over 600, and reads anything else as 80', function (mixed $stored, int $sent) {
        app(SettingsRepository::class)->set('assemblyai.fastest_min_silence_ms', $stored);

        expect(captionWsQuery(captionLatencyGrant()['grants'][0]['ws_url'])['min_turn_silence'])->toBe((string) $sent);
    })->with([
        'under the floor' => [10, 50],
        'over the ceiling' => [5000, 600],
        'not a number' => ['soon', 80],
        'a number as text' => ['120', 120],
    ]);

    it('is set in Admin › Settings under Fastest, and refused out of range', function () {
        $admin = User::factory()->create(['is_super_admin' => true]);
        $this->actingAs($admin);
        Filament::setCurrentPanel('admin');

        Livewire::test(Settings::class)
            ->assertSet('data.assemblyai_fastest_min_silence_ms', 80)
            ->set('data.assemblyai_fastest_min_silence_ms', 40)->call('save')->assertHasErrors(['data.assemblyai_fastest_min_silence_ms'])
            ->set('data.assemblyai_fastest_min_silence_ms', 601)->call('save')->assertHasErrors(['data.assemblyai_fastest_min_silence_ms'])
            ->set('data.assemblyai_fastest_min_silence_ms', 60)->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('assemblyai.fastest_min_silence_ms'))->toBe(60);

        // Under another speed the field is hidden: its value is neither checked nor changed.
        Livewire::test(Settings::class)->set('data.assemblyai_streaming_mode', 'balanced')
            ->set('data.assemblyai_fastest_min_silence_ms', 5)->call('save')->assertHasNoErrors();
        expect(app(SettingsRepository::class)->get('assemblyai.streaming_mode'))->toBe('balanced');
    });

    it('imports only whole milliseconds between 50 and 600 from a settings file', function () {
        $read = fn (mixed $v): array => app(SettingsTransfer::class)->read(json_encode(['format' => SettingsTransfer::FORMAT, 'settings' => ['assemblyai.fastest_min_silence_ms' => $v]]));

        expect($read(90))->toBe(['assemblyai.fastest_min_silence_ms' => 90])
            ->and($read(20))->toBe([])->and($read(900))->toBe([])->and($read('90'))->toBe([]);
    });
});
