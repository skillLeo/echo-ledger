<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\Plan;
use App\Models\Workspace;
use App\Services\Assist\RelaySecret;
use App\Services\InterfaceFlags;
use App\Services\SettingsRepository;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/*
 * Shared by the Live Assist tests (v1.9 L1): a person with a device on a plan, the flag on for them, the relay set up.
 */

const ASSIST_SECRET = 'relay-secret-for-the-tests-0123456789-abcdef';

/** The relay set up: its host and its secret. */
function assistRelaySetUp(): void
{
    app(SettingsRepository::class)->set('relay.url', 'relay.example.com');
    app(RelaySecret::class)->rotate(ASSIST_SECRET);
}

/** A person on [$plan] with a device, and the live_assist flag on for them (unless [$flag] is false). */
function assistPerson(string $plan = 'pro', bool $flag = true): Device
{
    $user = Fx::user();
    Workspace::query()->whereKey($user->current_workspace_id)->update([
        'plan_id' => Plan::query()->where('key', $plan)->value('id'),
        'plan_expires_at' => $plan === Plan::FREE ? null : now()->addDays(30),
    ]);
    if ($flag) {
        $flags = app(InterfaceFlags::class);
        $current = $flags->rules()['live_assist'] ?? null;
        $flags->set('live_assist', false, 0, [], [...array_map('intval', $current?->user_ids ?? []), $user->id]);
    }

    return Fx::device($user->fresh(), ['workspace_id' => $user->current_workspace_id]);
}

/**
 * The claims of a relay token, after checking its header and signature as the relay does.
 *
 * @return array<string, mixed>
 */
function relayClaims(string $token, string $secret = ASSIST_SECRET): array
{
    $decode = fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/').str_repeat('=', (4 - strlen($part) % 4) % 4), true);
    [$header, $payload, $signature] = explode('.', $token);
    expect(json_decode($decode($header), true))->toBe(['alg' => 'HS256', 'typ' => 'JWT'])
        ->and(hash_equals(hash_hmac('sha256', $header.'.'.$payload, $secret, true), $decode($signature)))->toBeTrue();

    return json_decode($decode($payload), true);
}

/** The headers of a relay request, signed as services/assist-relay/src/internal.rs signs it. */
function relayHeaders(string $method, string $path, string $body = '', string $secret = ASSIST_SECRET, ?int $timestamp = null): array
{
    $timestamp ??= now()->getTimestamp();

    return [
        'X-Assist-Timestamp' => (string) $timestamp,
        'X-Assist-Signature' => hash_hmac('sha256', $timestamp."\n".$method."\n".$path."\n".$body, $secret),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ];
}
