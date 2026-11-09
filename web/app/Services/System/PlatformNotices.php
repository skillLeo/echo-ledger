<?php

declare(strict_types=1);

namespace App\Services\System;

use App\Models\Device;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;

/**
 * What the platform tells everyone (since v1.6, P2.7, Admin panel → App versions, Announcement and Maintenance): the
 * lowest app version per platform (older apps are asked to update, and refused when the admin says so), one banner for
 * everyone until a time, and maintenance (the app and the API pause for everyone but staff, with a message).
 */
final class PlatformNotices
{
    public const VERSIONS = 'apps.minimum';

    public const UPDATE_MESSAGE = 'apps.update_message';

    public const REFUSE_OLDER = 'apps.refuse_older';

    public const ANNOUNCEMENT = 'announcement';

    public const MAINTENANCE = 'maintenance';

    /** The apps' platforms with a minimum version. */
    public const PLATFORMS = ['android' => 'Android phone', 'windows' => 'Windows computer', 'macos' => 'Mac'];

    public const LEVELS = ['info' => 'Information', 'warning' => 'Warning'];

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return array<string, string|null> platform => lowest version, null = none */
    public function minimums(): array
    {
        $stored = $this->settings->get(self::VERSIONS);
        $out = [];
        foreach (array_keys(self::PLATFORMS) as $platform) {
            $value = is_array($stored) ? ($stored[$platform] ?? null) : null;
            $out[$platform] = is_string($value) && self::isVersion($value) ? $value : null;
        }

        return $out;
    }

    public static function isVersion(string $value): bool
    {
        return preg_match('/^\d{1,4}(\.\d{1,4}){0,3}$/', $value) === 1;
    }

    /**
     * What the device's app is told (GET /api/config `app_update`): null without a minimum for its platform.
     *
     * @return array{minimum: string, required: bool, refused: bool, message: string, url: string}|null
     */
    public function updateFor(Device $device): ?array
    {
        $minimum = $this->minimums()[$device->platform->value] ?? null;
        if ($minimum === null) {
            return null;
        }
        $version = (string) ($device->app_version ?? '');
        $required = $version === '' || ! self::isVersion(self::core($version)) || version_compare(self::core($version), $minimum, '<');
        $message = $this->settings->get(self::UPDATE_MESSAGE);

        return [
            'minimum' => $minimum,
            'required' => $required,
            'refused' => $required && $this->refusesOlder(),
            'message' => is_string($message) && trim($message) !== '' ? trim($message) : "Please update EchoLedger to {$minimum} or later.",
            'url' => url('/downloads'),
        ];
    }

    public function refusesOlder(): bool
    {
        return (bool) $this->settings->get(self::REFUSE_OLDER, false);
    }

    /** "1.6.0" of "1.6.0-beta+5" or "1.6.0 (17)". */
    private static function core(string $version): string
    {
        return (string) preg_replace('/[^0-9.].*$/', '', trim($version));
    }

    /** @return array{text: string, level: string, until: string|null}|null the banner while it shows */
    public function announcement(): ?array
    {
        $raw = $this->settings->get(self::ANNOUNCEMENT);
        if (! is_array($raw) || ! is_string($raw['text'] ?? null) || trim($raw['text']) === '') {
            return null;
        }
        $until = is_string($raw['until'] ?? null) ? Carbon::parse($raw['until']) : null;
        if ($until !== null && $until->isPast()) {
            return null;
        }

        return ['text' => trim($raw['text']), 'level' => isset(self::LEVELS[$raw['level'] ?? '']) ? (string) $raw['level'] : 'info', 'until' => $until?->toAtomString()];
    }

    /** @return array{message: string, until: string|null}|null maintenance while it is on */
    public function maintenance(): ?array
    {
        $raw = $this->settings->get(self::MAINTENANCE);
        if (! is_array($raw) || ($raw['on'] ?? false) !== true) {
            return null;
        }
        $until = is_string($raw['until'] ?? null) ? Carbon::parse($raw['until']) : null;
        $message = is_string($raw['message'] ?? null) && trim($raw['message']) !== '' ? trim($raw['message']) : 'EchoLedger is being updated. Please try again in a few minutes; your recordings are safe.';

        return ['message' => $message, 'until' => $until?->toAtomString()];
    }

    /** Seconds the apps wait before asking again during maintenance. */
    public function retryAfter(): int
    {
        $until = $this->maintenance()['until'] ?? null;

        return $until === null ? 300 : max(60, min(86400, (int) now()->diffInSeconds(Carbon::parse($until), false)));
    }
}
