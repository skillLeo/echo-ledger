<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Exceptions\ApiError;
use App\Services\SettingsRepository;

/**
 * How a Live Assist session's live messages travel (docs/API_ASSIST.md 1.9.0-q, "Transport"), from Admin › Settings ›
 * Live Assist connection:
 *
 * - automatic: the relay when its address and secret are set and its health check passed within the last minute,
 *   otherwise HTTP mode on this server;
 * - shared: always HTTP mode (shared hosting, no relay);
 * - relay: always the relay (a VPS); refused while it is down.
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
        self::AUTOMATIC => 'Automatic',
        self::SHARED => 'Shared hosting',
        self::RELAY => 'Dedicated relay (VPS)',
    ];

    /** What a session runs on. */
    public const TRANSPORT_RELAY = 'relay';

    public const TRANSPORT_HTTP = 'http';

    public const TRANSPORTS = [self::TRANSPORT_RELAY, self::TRANSPORT_HTTP];

    public function __construct(private readonly SettingsRepository $settings, private readonly RelayHealth $health, private readonly RelaySecret $secret) {}

    /** The admin's choice; anything unknown is Automatic. */
    public function choice(): string
    {
        $value = $this->settings->get('assist.connection');

        return is_string($value) && isset(self::CHOICES[$value]) ? $value : self::AUTOMATIC;
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
            self::RELAY => $this->relayReady() ? self::TRANSPORT_RELAY : throw new ApiError(503, 'relay_unavailable', 'Live Assist can\'t connect right now. Try again in a few minutes.'),
            default => $this->relayReady() ? self::TRANSPORT_RELAY : self::TRANSPORT_HTTP,
        };
        if (! in_array($transport, $known, true)) {
            throw new ApiError(426, 'app_update_required', 'Live Assist needs the latest version of the app.');
        }

        return $transport;
    }

    /** The relay is usable: its address and secret are set and it answered its health check within the last minute. */
    public function relayReady(): bool
    {
        return $this->health->host() !== null && $this->secret->signing() !== null && $this->health->healthy();
    }
}
