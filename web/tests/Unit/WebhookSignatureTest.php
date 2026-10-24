<?php

declare(strict_types=1);

use App\Stt\Drivers\AssemblyAiDriver;
use App\Stt\Drivers\ElevenLabsSignature;
use App\Stt\Exceptions\InvalidWebhookSignature;

describe('ElevenLabs HMAC signature', function () {
    $secret = 'whsec_test';
    $body = '{"type":"speech_to_text.completed","data":{"transcription_id":"x"}}';

    it('accepts a correctly signed, fresh payload', function () use ($secret, $body) {
        $now = 1_700_000_000;
        $header = ElevenLabsSignature::sign($body, $secret, $now);
        $decoded = ElevenLabsSignature::verify($body, $header, $secret, $now);
        expect($decoded['data']['transcription_id'])->toBe('x');
    });

    it('rejects a stale timestamp', function () use ($secret, $body) {
        $header = ElevenLabsSignature::sign($body, $secret, 1_700_000_000);
        ElevenLabsSignature::verify($body, $header, $secret, 1_700_000_000 + 4000);
    })->throws(InvalidWebhookSignature::class, 'Stale');

    it('rejects a future timestamp', function () use ($secret, $body) {
        $header = ElevenLabsSignature::sign($body, $secret, 1_700_000_000 + 1000);
        ElevenLabsSignature::verify($body, $header, $secret, 1_700_000_000);
    })->throws(InvalidWebhookSignature::class, 'future');

    it('rejects a tampered body', function () use ($secret, $body) {
        $now = 1_700_000_000;
        $header = ElevenLabsSignature::sign($body, $secret, $now);
        ElevenLabsSignature::verify($body.' ', $header, $secret, $now);
    })->throws(InvalidWebhookSignature::class, 'mismatch');

    it('rejects a malformed header', function () use ($secret, $body) {
        ElevenLabsSignature::verify($body, 'garbage', $secret, 1_700_000_000);
    })->throws(InvalidWebhookSignature::class);
});

describe('AssemblyAI per-recording webhook token', function () {
    it('is deterministic for the same recording and secret', function () {
        $a = AssemblyAiDriver::webhookToken('secret', 'rec_123');
        $b = AssemblyAiDriver::webhookToken('secret', 'rec_123');
        expect($a)->toBe($b);
    });

    it('differs per recording and is URL-safe base64 without padding', function () {
        $a = AssemblyAiDriver::webhookToken('secret', 'rec_123');
        $b = AssemblyAiDriver::webhookToken('secret', 'rec_456');
        expect($a)->not->toBe($b);
        expect($a)->toMatch('/^[A-Za-z0-9_-]+$/');
    });
});
