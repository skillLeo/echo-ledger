<?php

declare(strict_types=1);

use App\Services\CredentialVault;
use Illuminate\Support\Facades\DB;

it('stores credentials encrypted at rest and round-trips them', function () {
    $vault = app(CredentialVault::class);
    $vault->put('assemblyai', 'api_key', 'super-secret-value');

    $raw = DB::table('credentials')->where(['provider' => 'assemblyai', 'key' => 'api_key'])->value('value');
    expect($raw)->not->toContain('super-secret-value'); // ciphertext at rest
    expect($vault->get('assemblyai', 'api_key'))->toBe('super-secret-value'); // decrypts on read
});

it('describes a configured credential without revealing it', function () {
    $vault = app(CredentialVault::class);
    $vault->put('elevenlabs', 'api_key', 'xi-1234567890');
    $description = $vault->describe('elevenlabs', 'api_key');

    expect($description)->toContain('7890'); // last 4 only
    expect($description)->not->toContain('xi-1234567890');
});

it('reports presence and forgets credentials', function () {
    $vault = app(CredentialVault::class);
    expect($vault->has('azure', 'api_key'))->toBeFalse();
    $vault->put('azure', 'api_key', 'k');
    expect($vault->has('azure', 'api_key'))->toBeTrue();
    $vault->forget('azure', 'api_key');
    expect($vault->has('azure', 'api_key'))->toBeFalse();
});
