<?php

declare(strict_types=1);

use App\Rules\CertificatePin;
use App\Stt\ProviderEndpoints;

// A6.W10: provider addresses that may receive an API key; A6.W9: certificate pin format.

it('allows ElevenLabs only over HTTPS on elevenlabs.io hosts', function (string $url, bool $allowed) {
    expect(ProviderEndpoints::allowsElevenLabs($url))->toBe($allowed);
})->with([
    ['https://api.elevenlabs.io', true],
    ['https://api.eu.residency.elevenlabs.io', true],
    ['HTTPS://API.ELEVENLABS.IO/', true],
    ['https://api.elevenlabs.io/v1', true],
    ['http://api.elevenlabs.io', false],
    ['https://elevenlabs.io.attacker.example', false],
    ['https://attacker-elevenlabs.io', false],
    ['https://api.elevenlabs.io.', false],
    ['https://user:secret@api.elevenlabs.io', false],
    ['https://api.elevenlabs.io?x=1', false],
    ['https://api.elevenlabs.io#frag', false],
    [' https://api.elevenlabs.io', false],
    ['https://api.elevenlabs.io\\@attacker.example', false],
    ['//api.elevenlabs.io', false],
    ['', false],
]);

it('allows Azure endpoints only over HTTPS on Azure Cognitive Services hosts', function (string $url, bool $allowed) {
    expect(ProviderEndpoints::allowsAzureEndpoint($url))->toBe($allowed);
})->with([
    ['https://acme.cognitiveservices.azure.com', true],
    ['https://westeurope.api.cognitive.microsoft.com', true],
    ['http://acme.cognitiveservices.azure.com', false],
    ['https://cognitiveservices.azure.com', false],
    ['https://acme.cognitiveservices.azure.com.attacker.example', false],
    ['https://example.com', false],
]);

it('allows any HTTPS whisper-server and plain HTTP only on this machine', function (string $url, bool $allowed) {
    expect(ProviderEndpoints::allowsWhisper($url))->toBe($allowed);
})->with([
    ['https://whisper.example.test', true],
    ['https://10.0.0.5:8443', true],
    ['http://localhost:8080', true],
    ['http://127.0.0.1:8080', true],
    ['http://whisper.example.test', false],
    ['http://localhost.attacker.example', false],
    ['ftp://whisper.example.test', false],
    ['file:///etc/passwd', false],
]);

it('allows only plain lower-case Azure region names', function (string $region, bool $allowed) {
    expect(ProviderEndpoints::allowsAzureRegion($region))->toBe($allowed);
})->with([
    ['centralindia', true],
    ['eastus2', true],
    ['EASTUS', false],
    ['east.us', false],
    ["eastus\n", false],
    ['x@attacker.example#', false],
    ['a', false],
    [str_repeat('a', 33), false],
]);

it('accepts a certificate pin only as sha256/ plus the base64 of 32 bytes', function (mixed $pin, bool $valid) {
    expect(CertificatePin::isValid($pin))->toBe($valid);
})->with([
    ['sha256/'.base64_encode(str_repeat("\x01", 32)), true],
    ['sha256/'.str_repeat('A', 43).'=', true],
    ['sha256/'.base64_encode(str_repeat("\x01", 31)), false],
    ['sha256/'.base64_encode(str_repeat("\x01", 33)), false],
    ['SHA256/'.str_repeat('A', 43).'=', false],
    ['sha256/'.str_repeat('A', 43).'= ', false],
    ['sha256/'.str_repeat('-', 43).'=', false],
    [null, false],
    [42, false],
    [['sha256/'.str_repeat('A', 43).'='], false],
]);
