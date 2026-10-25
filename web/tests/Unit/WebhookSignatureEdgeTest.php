<?php

declare(strict_types=1);

use App\Stt\Drivers\AssemblyAiDriver;
use App\Stt\Drivers\ElevenLabsSignature;
use App\Stt\Exceptions\InvalidWebhookSignature;

const SIG_NOW = 1_800_000_000;
const SIG_SECRET = 'whsec_edge_cases';

/** Verify, returning the exception message or null when the signature was accepted. */
function sigOutcome(string $body, ?string $header, string $secret = SIG_SECRET, int $now = SIG_NOW, int $tolerance = 1800): ?string
{
    try {
        ElevenLabsSignature::verify($body, $header, $secret, $now, $tolerance);

        return null;
    } catch (InvalidWebhookSignature $e) {
        return $e->getMessage();
    }
}

describe('ElevenLabs signature', function () {
    it('signs deterministically as t=<time>,v0=<hex hmac of "time.body">', function () {
        $header = ElevenLabsSignature::sign('{"a":1}', SIG_SECRET, SIG_NOW);

        expect($header)->toBe('t='.SIG_NOW.',v0='.hash_hmac('sha256', SIG_NOW.'.{"a":1}', SIG_SECRET))
            ->and(ElevenLabsSignature::sign('{"a":1}', SIG_SECRET, SIG_NOW))->toBe($header);
    });

    it('accepts the timestamp edges and rejects one second beyond them', function (int $offset, bool $accepted) {
        $header = ElevenLabsSignature::sign('{}', SIG_SECRET, SIG_NOW + $offset);

        expect(sigOutcome('{}', $header) === null)->toBe($accepted);
    })->with([
        'exactly 30 minutes old' => [-1800, true],
        '30 minutes and a second old' => [-1801, false],
        'exactly now' => [0, true],
        'five minutes ahead' => [300, true],
        'five minutes and a second ahead' => [301, false],
    ]);

    it('honours a custom tolerance', function () {
        $header = ElevenLabsSignature::sign('{}', SIG_SECRET, SIG_NOW - 100);

        expect(sigOutcome('{}', $header, tolerance: 60))->toContain('Stale')->and(sigOutcome('{}', $header, tolerance: 120))->toBeNull();
    });

    it('rejects anything but a lower-case hex digest from the right key', function () {
        $good = ElevenLabsSignature::sign('{}', SIG_SECRET, SIG_NOW);
        $upper = 't='.SIG_NOW.',v0='.strtoupper(substr($good, strpos($good, 'v0=') + 3));

        expect(sigOutcome('{}', $upper))->toContain('mismatch')
            ->and(sigOutcome('{}', $good, 'another-secret'))->toContain('mismatch')
            ->and(sigOutcome('{}', 't='.SIG_NOW.',v0=', SIG_SECRET))->not->toBeNull();
    });

    it('rejects a missing header or secret', function () {
        expect(sigOutcome('{}', null))->toContain('Missing')
            ->and(sigOutcome('{}', ''))->toContain('Missing')
            ->and(sigOutcome('{}', ElevenLabsSignature::sign('{}', SIG_SECRET, SIG_NOW), ''))->toContain('Missing');
    });

    it('rejects timestamps that are not plain digits', function (string $timestamp) {
        $digest = hash_hmac('sha256', $timestamp.'.{}', SIG_SECRET);

        expect(sigOutcome('{}', "t={$timestamp},v0={$digest}"))->toContain('Malformed');
    })->with(['', '-1', '1.5', '1e9', '0x10', ' ', 'abc']);

    it('binds the digest to the exact bytes of the body', function (string $signedBody, string $sentBody) {
        $header = ElevenLabsSignature::sign($signedBody, SIG_SECRET, SIG_NOW);

        expect(sigOutcome($sentBody, $header))->toContain('mismatch');
    })->with([
        'a trailing space' => ['{"a":1}', '{"a":1} '],
        'reordered keys' => ['{"a":1,"b":2}', '{"b":2,"a":1}'],
        'pretty printed' => ['{"a":1}', "{\n\"a\":1\n}"],
        'a different value' => ['{"amount":100}', '{"amount":900}'],
    ]);

    it('decodes the body only after it verified', function () {
        $body = '{"type":"speech_to_text_transcription","data":{"request_id":"r1"}}';

        $decoded = ElevenLabsSignature::verify($body, ElevenLabsSignature::sign($body, SIG_SECRET, SIG_NOW), SIG_SECRET, SIG_NOW);

        expect($decoded['data']['request_id'])->toBe('r1');
    });

    it('rejects a correctly signed body that is not a JSON object or array', function (string $body) {
        expect(sigOutcome($body, ElevenLabsSignature::sign($body, SIG_SECRET, SIG_NOW)))->toContain('not JSON');
    })->with(['empty' => '', 'text' => 'plain text', 'number' => '123', 'string' => '"abc"', 'null' => 'null', 'truncated' => '{"a":']);

    it('takes the header parts in any order and with spaces after the commas', function () {
        $digest = hash_hmac('sha256', SIG_NOW.'.{}', SIG_SECRET);

        expect(sigOutcome('{}', 'v0='.$digest.', t='.SIG_NOW))->toBeNull()
            ->and(sigOutcome('{}', ' t='.SIG_NOW.' , v0='.$digest.' '))->toBeNull();
    });

    it('ignores signature schemes it does not know but still needs a v0', function () {
        $digest = hash_hmac('sha256', SIG_NOW.'.{}', SIG_SECRET);

        expect(sigOutcome('{}', 't='.SIG_NOW.',v1=abcdef,v0='.$digest))->toBeNull()
            ->and(sigOutcome('{}', 't='.SIG_NOW.',v1=abcdef'))->toContain('Malformed');
    });

    it('does not accept a signature computed without the timestamp', function () {
        $header = 't='.SIG_NOW.',v0='.hash_hmac('sha256', '{}', SIG_SECRET);

        expect(sigOutcome('{}', $header))->toContain('mismatch');
    });
});

describe('AssemblyAI webhook token', function () {
    it('is url-safe base64 of a SHA-256 HMAC without padding', function () {
        $token = AssemblyAiDriver::webhookToken(SIG_SECRET, '01ARZ3NDEKTSV4RRFFQ69G5FAV');

        expect($token)->toMatch('/^[A-Za-z0-9_-]{43}$/')
            ->and(base64_decode(strtr($token, '-_', '+/').'=', true))->toBe(hash_hmac('sha256', 'assemblyai|01ARZ3NDEKTSV4RRFFQ69G5FAV', SIG_SECRET, true));
    });

    it('depends on both the secret and the recording', function () {
        $base = AssemblyAiDriver::webhookToken('secret-a', 'rec-1');

        expect(AssemblyAiDriver::webhookToken('secret-b', 'rec-1'))->not->toBe($base)
            ->and(AssemblyAiDriver::webhookToken('secret-a', 'rec-2'))->not->toBe($base)
            ->and(AssemblyAiDriver::webhookToken('secret-a', 'rec-1'))->toBe($base);
    });

    it('cannot be reused across providers because the provider name is part of the message', function () {
        $token = AssemblyAiDriver::webhookToken(SIG_SECRET, 'rec-1');

        expect($token)->not->toBe(rtrim(strtr(base64_encode(hash_hmac('sha256', 'elevenlabs|rec-1', SIG_SECRET, true)), '+/', '-_'), '='));
    });
});
