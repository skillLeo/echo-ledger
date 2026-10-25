<?php

declare(strict_types=1);

use App\Models\Client;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use Tests\Feature\Api\Support\Fixtures;
use Tests\Feature\Api\Support\InteractsWithDeviceApi;

uses(InteractsWithDeviceApi::class);

beforeEach(function () {
    [$this->device, $this->token] = $this->enroll();
    $this->asToken($this->token);
});

describe('the device block', function () {
    it('describes the calling device', function () {
        $this->getJson('/api/config')->assertOk()
            ->assertJsonPath('device.id', $this->device->id)
            ->assertJsonPath('device.name', $this->device->name)
            ->assertJsonPath('device.platform', 'android')
            ->assertJsonPath('device.source_device', 'phone');
    });

    it('derives the source device from the platform', function (string $platform, string $expected) {
        [$device, $token] = $this->enroll(platform: $platform);

        $this->asToken($token)->getJson('/api/config')->assertOk()
            ->assertJsonPath('device.id', $device->id)->assertJsonPath('device.platform', $platform)->assertJsonPath('device.source_device', $expected);
    })->with([['android', 'phone'], ['ios', 'phone'], ['windows', 'desktop'], ['macos', 'desktop'], ['linux', 'desktop']]);

    it('reports the server clock in UTC', function () {
        $this->freezeTime();

        $time = $this->getJson('/api/config')->json('server_time');

        expect($time)->toMatch(Fixtures::ISO_8601)->and(strtotime($time))->toBe(now()->getTimestamp());
    });
});

describe('upload limits', function () {
    it('advertises the chunk size the server will actually accept', function () {
        $advertised = $this->getJson('/api/config')->assertOk()->json('upload.chunk_size_bytes');
        $created = $this->createSession(Fixtures::audio(2000))->assertCreated();

        expect($advertised)->toBe($created->json('recordings.0.upload.chunk_size_bytes'))
            ->and($advertised)->toBeGreaterThanOrEqual(256 * 1024)->toBeLessThanOrEqual(5 * 1024 * 1024)
            ->and($advertised % (256 * 1024))->toBe(0);
    });

    it('keeps the chunk size below the PHP request body limit', function () {
        $advertised = $this->getJson('/api/config')->json('upload.chunk_size_bytes');
        $limit = ini_parse_quantity((string) ini_get('post_max_size'));

        if ($limit > 0) {
            expect($advertised)->toBeLessThan($limit);
        } else {
            expect($advertised)->toBeGreaterThan(0);
        }
    });

    it('advertises the configured recording size cap and accepted types', function () {
        config(['echoledger.max_recording_bytes' => 123_456_789, 'echoledger.accepted_mimes' => ['audio/ogg', 'audio/flac']]);

        $upload = $this->getJson('/api/config')->json('upload');

        expect($upload['max_recording_bytes'])->toBe(123_456_789)->and($upload['accepted_mimes'])->toBe(['audio/ogg', 'audio/flac']);
    });

    it('lists the MIME types documented in API.md', function () {
        $mimes = $this->getJson('/api/config')->json('upload.accepted_mimes');

        expect($mimes)->toContain('audio/ogg', 'audio/opus', 'audio/flac', 'audio/wav', 'audio/x-wav', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'audio/webm', 'audio/amr');
    });
});

describe('providers', function () {
    it('defaults every use case to AssemblyAI', function () {
        $providers = $this->getJson('/api/config')->json('providers');

        foreach (['live_captions', 'dictation', 'final_transcript', 'voice_note'] as $useCase) {
            expect($providers[$useCase]['provider'])->toBe('assemblyai');
        }
        expect($providers['live_captions']['mode'])->toBe('cloud')
            ->and($providers['final_transcript'])->toBe(['provider' => 'assemblyai', 'mode' => 'async_webhook', 'required_artifacts' => ['primary'], 'preferred_primary' => 'ogg_opus'])
            ->and($providers['voice_note'])->toBe(['provider' => 'assemblyai', 'mode' => 'async_webhook', 'required_artifacts' => ['primary'], 'preferred_primary' => 'ogg_opus']);
    });

    it('tells devices which artifacts and format each provider needs', function (string $provider, array $settings, string $mode, array $artifacts, string $primary) {
        app(SettingsRepository::class)->setMany(['provider.final_transcript' => $provider] + $settings);

        $final = $this->getJson('/api/config')->json('providers.final_transcript');

        expect($final)->toBe(['provider' => $provider, 'mode' => $mode, 'required_artifacts' => $artifacts, 'preferred_primary' => $primary]);
    })->with([
        'AssemblyAI' => ['assemblyai', [], 'async_webhook', ['primary'], 'ogg_opus'],
        'ElevenLabs' => ['elevenlabs', [], 'async_webhook', ['primary'], 'ogg_opus'],
        'Azure standard model' => ['azure_mai', ['azure.model' => 'standard'], 'sync', ['primary'], 'ogg_opus'],
        'Azure MAI model' => ['azure_mai', ['azure.model' => 'MAI-Transcribe-2'], 'sync', ['primary', 'channel_1', 'channel_2'], 'flac'],
        'whisper.cpp' => ['whispercpp_local', [], 'sync', ['primary', 'channel_1', 'channel_2'], 'flac'],
        'Parakeet on device' => ['parakeet_local', [], 'device', ['primary'], 'ogg_opus'],
    ]);

    it('reports each use case separately', function () {
        app(SettingsRepository::class)->setMany([
            'provider.live_captions' => 'elevenlabs', 'provider.dictation' => 'azure_mai', 'provider.final_transcript' => 'whispercpp_local', 'provider.voice_note' => 'parakeet_local',
        ]);

        $providers = $this->getJson('/api/config')->json('providers');

        expect(array_map(fn ($p) => $p['provider'], $providers))->toBe([
            'live_captions' => 'elevenlabs', 'dictation' => 'azure_mai', 'final_transcript' => 'whispercpp_local', 'voice_note' => 'parakeet_local',
        ]);
        expect($providers['voice_note']['mode'])->toBe('device')->and($providers['final_transcript']['required_artifacts'])->toHaveCount(3);
    });

    it('reports an on-device live engine as on_device', function () {
        app(SettingsRepository::class)->set('provider.live_captions', 'parakeet_local');

        expect($this->getJson('/api/config')->json('providers.live_captions'))->toBe(['provider' => 'parakeet_local', 'mode' => 'on_device']);
    });

    it('falls back to AssemblyAI for a provider name it does not know', function () {
        app(SettingsRepository::class)->set('provider.final_transcript', 'made_up');

        expect($this->getJson('/api/config')->json('providers.final_transcript.provider'))->toBe('assemblyai');
    });

    it('matches what a created session is told to upload', function () {
        app(SettingsRepository::class)->set('provider.final_transcript', 'whispercpp_local');

        $config = $this->getJson('/api/config')->json('providers.final_transcript.required_artifacts');
        $created = $this->createSession(Fixtures::audio(2000))->assertCreated();

        expect($created->json('required_artifacts'))->toBe($config);
    });
});

describe('captions', function () {
    it('defaults to both channels', function () {
        $this->getJson('/api/config')->assertJsonPath('captions.channels', 'both');
    });

    it('reflects the admin\'s choice', function (string $value) {
        app(SettingsRepository::class)->set('captions.channels', $value);

        $this->getJson('/api/config')->assertJsonPath('captions.channels', $value);
    })->with(['both', 'client_only', 'off']);

    it('never reports a value outside the three the apps understand', function (mixed $stored) {
        app(SettingsRepository::class)->set('captions.channels', $stored);

        $this->getJson('/api/config')->assertJsonPath('captions.channels', 'both');
    })->with(['loud', '', null, 7]);

    it('does not fail on a stored value that is not text', function () {
        app(SettingsRepository::class)->set('captions.channels', ['both']);

        $this->getJson('/api/config')->assertOk()->assertJsonPath('captions.channels', 'both');
    });
});

describe('retention', function () {
    it('defaults to thirty days', function () {
        $this->getJson('/api/config')->assertJsonPath('retention_days', 30);
    });

    it('reflects the setting, clamped to one to 365 days', function (mixed $stored, int $reported) {
        app(SettingsRepository::class)->set('retention_days', $stored);

        $this->getJson('/api/config')->assertJsonPath('retention_days', $reported);
    })->with([
        'a week' => [7, 7],
        'a year' => [365, 365],
        'a day' => [1, 1],
        'zero becomes a day' => [0, 1],
        'negative becomes a day' => [-10, 1],
        'beyond a year is capped' => [1000, 365],
        'numeric string' => ['15', 15],
        'garbage becomes a day' => ['soon', 1],
    ]);

    it('is a JSON integer', function () {
        app(SettingsRepository::class)->set('retention_days', '45');

        expect($this->getJson('/api/config')->json('retention_days'))->toBeInt()->toBe(45);
    });
});

describe('pins', function () {
    it('is an empty list until the admin sets pins', function () {
        expect($this->getJson('/api/config')->json('pins'))->toBe([]);
    });

    it('lists the configured SPKI pins in order', function () {
        $pins = ['sha256/'.str_repeat('A', 43).'=', 'sha256/'.str_repeat('B', 43).'='];
        app(SettingsRepository::class)->set('app.pins', $pins);

        expect($this->getJson('/api/config')->json('pins'))->toBe($pins);
    });

    it('drops blank entries and never emits a non-list', function () {
        app(SettingsRepository::class)->set('app.pins', ['sha256/'.str_repeat('A', 43).'=', '', '   ' => 'x', 0 => '']);
        expect($this->getJson('/api/config')->json('pins'))->toBeArray()->each->toBeString();

        app(SettingsRepository::class)->set('app.pins', 'sha256/not-a-list');
        expect($this->getJson('/api/config')->json('pins'))->toBe([]);
    });

    it('drops stored pins that devices could not use (A6.W9)', function () {
        $valid = 'sha256/'.str_repeat('C', 43).'=';
        app(SettingsRepository::class)->set('app.pins', ['sha256/AAAA=', $valid, 'md5/'.str_repeat('D', 43).'=', ['nested'], 42, 'sha256/'.str_repeat('E', 44)]);

        expect($this->getJson('/api/config')->json('pins'))->toBe([$valid]);
    });
});

describe('secrecy', function () {
    it('never contains a stored credential', function () {
        $vault = app(CredentialVault::class);
        foreach (CredentialVault::FIELDS as $provider => $fields) {
            foreach (array_keys($fields) as $key) {
                $vault->put($provider, $key, "SECRET-{$provider}-{$key}-value");
            }
        }
        Client::factory()->create(['user_id' => $this->device->user_id]);

        foreach (['/api/config', '/api/clients', '/api/library'] as $uri) {
            expect($this->getJson($uri)->assertOk()->getContent())->not->toContain('SECRET-');
        }
    });

    it('is the same for every device of the owner except for the device block', function () {
        [, $other] = $this->enroll($this->device->user, platform: 'windows');
        $mine = $this->getJson('/api/config')->json();
        $theirs = $this->asToken($other)->getJson('/api/config')->json();
        foreach ([&$mine, &$theirs] as &$config) {
            unset($config['device'], $config['server_time']);
        }

        expect($mine)->toBe($theirs);
    });

    it('is not cached by intermediaries as public', function () {
        $header = (string) $this->getJson('/api/config')->headers->get('Cache-Control');

        expect($header)->not->toContain('public');
    });
});
