<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Exceptions\ApiError;
use App\Services\SettingsRepository;

/**
 * How a Live Assist session's live messages travel (docs/API_ASSIST.md 1.9.0-q, "Transport"), from Admin › Settings ›
 * Live Assist connection:
 *
 * - relay (the default; the owner: the live relay runs only on the VPS, never on shared hosting): always the relay;
 *   refused (503 relay_unavailable) while it is down or not set up;
 * - automatic: the relay whenever its address and secret are set, and then refused while it is down, never this
 *   server instead; HTTP mode on this server only while no relay is set up at all;
 * - shared: always HTTP mode on this server. An emergency choice only, made by hand: never picked on its own.
 *
 * Chosen once, at the session's start, for the whole session.
 */
class AssistTransport
{
    public const AUTOMATIC = 'automatic';

    public const SHARED = 'shared';

    public const RELAY = 'relay';

    /** Setting value => label (Admin › Settings). */
    public const CHOICES = [
        self::RELAY => 'Dedicated relay (VPS)',
        self::AUTOMATIC => 'Automatic',
        self::SHARED => 'Shared hosting (emergency only)',
    ];

    /** The admin's default (the owner, 2026-11-18). */
    public const DEFAULT = self::RELAY;

    /** Under the choice when Shared hosting is picked. */
    public const SHARED_HELP = 'Emergency only — live answers run through this website\'s hosting and may be slower or cut off.';

    /** What a session runs on. */
    public const TRANSPORT_RELAY = 'relay';

    public const TRANSPORT_HTTP = 'http';

    public const TRANSPORTS = [self::TRANSPORT_RELAY, self::TRANSPORT_HTTP];

    public function __construct(private readonly SettingsRepository $settings, private readonly RelayHealth $health, private readonly RelaySecret $secret) {}

    /** The admin's choice; anything unknown is the default, Dedicated relay. */
    public function choice(): string
    {
        $value = $this->settings->get('assist.connection');

        return is_string($value) && isset(self::CHOICES[$value]) ? $value : self::DEFAULT;
    }

    /**
     * The transport for a session the app starts, knowing [$known] (`["relay"]` for apps before 1.9.0-q).
     *
     * @param  list<string>  $known
     *
     * @throws ApiError 503 relay_unavailable (Dedicated relay while it is down or not set up), 426 app_update_required
     *                  (the app does not know the transport this server runs)
     */
    public function choose(array $known): string
    {
        $transport = match ($this->choice()) {
            self::SHARED => self::TRANSPORT_HTTP,
            // Automatic never falls back to this server while a relay is set up: down is said, not hidden.
            self::AUTOMATIC => $this->relayConfigured() ? $this->relayOrRefuse() : self::TRANSPORT_HTTP,
            default => $this->relayOrRefuse(),
        };
        if (! in_array($transport, $known, true)) {
            throw new ApiError(426, 'app_update_required', 'Live Assist needs the latest version of the app.');
        }

        return $transport;
    }

    /** The relay is usable: its address and secret are set and it answered its health check within the last minute. */
    public function relayReady(): bool
    {
        return $this->relayConfigured() && $this->health->healthy();
    }

    /** A relay is set up: its address and secret are there (up or down). */
    public function relayConfigured(): bool
    {
        return $this->health->host() !== null && $this->secret->signing() !== null;
    }

    private function relayOrRefuse(): string
    {
        return $this->relayReady() ? self::TRANSPORT_RELAY
            : throw new ApiError(503, 'relay_unavailable', 'Live Assist can\'t connect right now. Try again in a few minutes.');
    }
}
