<?php

declare(strict_types=1);

use App\Models\CaptureSession;
use App\Models\Recording;
use App\Models\Transcript;
use App\Services\CredentialVault;
use App\Services\SettingsRepository;
use App\Stt\Data\FinalJobRequest;
use App\Stt\Drivers\AzureMaiDriver;
use App\Stt\Drivers\ElevenLabsDriver;
use App\Stt\Drivers\WhisperCppLocalDriver;
use App\Stt\Exceptions\ProviderNotConfigured;
use Illuminate\Support\Facades\Http;

// A6.W10: drivers check a stored provider address again right before they send a key, so a value saved before the
// console validated it (or written straight into the database) never sends the key elsewhere.

function guardFinalRequest(string $path): FinalJobRequest
{
    $recording = new Recording(['mime' => 'audio/wav', 'channels' => 1]);

    return new FinalJobRequest(new Transcript, new CaptureSession, $recording, $path, null, null, null, null, [], null, null);
}

beforeEach(function () {
    Http::fake(['*' => Http::response(['ok' => true], 200)]);
    $this->audio = tempnam(sys_get_temp_dir(), 'eld-guard');
    file_put_contents($this->audio, str_repeat("\0", 64));
    $vault = app(CredentialVault::class);
    $vault->put('azure', 'api_key', 'AZURE-GUARD-SENTINEL');
    $vault->put('elevenlabs', 'api_key', 'EL-GUARD-SENTINEL');
    $vault->put('whispercpp', 'bearer_token', 'WHISPER-GUARD-SENTINEL');
});

afterEach(fn () => @unlink($this->audio));

it('refuses a stored Azure endpoint that is not an Azure host', function (string $endpoint) {
    app(SettingsRepository::class)->set('azure.endpoint', $endpoint);

    expect(fn () => app(AzureMaiDriver::class)->submitFinal(guardFinalRequest($this->audio)))->toThrow(ProviderNotConfigured::class);
    Http::assertNothingSent();
})->with(['https://attacker.example', 'http://acme.cognitiveservices.azure.com', 'https://acme.cognitiveservices.azure.com.attacker.example']);

it('refuses a stored whisper-server address over plain HTTP', function () {
    app(SettingsRepository::class)->set('whisper.base_url', 'http://whisper.example.test');

    expect(fn () => app(WhisperCppLocalDriver::class)->submitFinal(guardFinalRequest($this->audio)))->toThrow(ProviderNotConfigured::class);
    Http::assertNothingSent();
});

it('refuses a stored ElevenLabs address on another host before uploading audio', function () {
    app(SettingsRepository::class)->set('elevenlabs.base_url', 'https://attacker.example');

    expect(fn () => app(ElevenLabsDriver::class)->submitFinal(guardFinalRequest($this->audio)))->toThrow(ProviderNotConfigured::class);
    Http::assertNothingSent();
});

it('refuses a stored value that is not text', function () {
    app(SettingsRepository::class)->set('azure.endpoint', ['https://acme.cognitiveservices.azure.com']);

    expect(fn () => app(AzureMaiDriver::class)->submitFinal(guardFinalRequest($this->audio)))->toThrow(ProviderNotConfigured::class);
    Http::assertNothingSent();
});
