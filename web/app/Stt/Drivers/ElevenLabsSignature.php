<?php

declare(strict_types=1);

namespace App\Stt\Drivers;

use App\Stt\Exceptions\InvalidWebhookSignature;

/**
 * `elevenlabs-signature: t=<unix>,v0=<hex HMAC-SHA256(secret, t.".".rawBody)>` with a 30-minute past tolerance
 * (docs/research/stt-elevenlabs.md R5). Stricter than the SDK: also rejects timestamps > 5 min in the future.
 */
final class ElevenLabsSignature
{
    /** @return array<string, mixed> decoded body */
    public static function verify(string $rawBody, ?string $header, string $secret, int $now, int $tolerance = 1800): array
    {
        if ($header === null || $header === '' || $secret === '') {
            throw new InvalidWebhookSignature('Missing signature header or secret.');
        }
        $timestamp = null;
        $signatures = [];
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            } elseif (str_starts_with($part, 'v0=')) {
                $signatures[] = $part;
            }
        }
        if ($timestamp === null || ! ctype_digit($timestamp) || $signatures === []) {
            throw new InvalidWebhookSignature('Malformed signature header.');
        }
        if ((int) $timestamp < $now - $tolerance) {
            throw new InvalidWebhookSignature('Stale webhook timestamp.');
        }
        if ((int) $timestamp > $now + 300) {
            throw new InvalidWebhookSignature('Webhook timestamp is in the future.');
        }
        $expected = 'v0='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                $decoded = json_decode($rawBody, true);
                if (! is_array($decoded)) {
                    throw new InvalidWebhookSignature('Webhook body is not JSON.');
                }

                return $decoded;
            }
        }

        throw new InvalidWebhookSignature('Signature mismatch.');
    }

    /** The `t=` value verify() signed against (the last one, as there); '' when absent. */
    public static function timestamp(string $header): string
    {
        $timestamp = '';
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if (str_starts_with($part, 't=')) {
                $timestamp = substr($part, 2);
            }
        }

        return $timestamp;
    }

    public static function sign(string $rawBody, string $secret, int $timestamp): string
    {
        return 't='.$timestamp.',v0='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);
    }
}
