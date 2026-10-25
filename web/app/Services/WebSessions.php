<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Browsers signed in to the console (plan B16), read from the `sessions` table of the database session driver.
 * With another driver there is nothing to list and nothing to sign out.
 */
final class WebSessions
{
    public function __construct(private readonly AuditLog $audit) {}

    public static function available(): bool
    {
        return config('session.driver') === 'database' && Schema::hasTable((string) config('session.table', 'sessions'));
    }

    /**
     * The owner's browsers, most recently active first.
     *
     * @return list<array{id: string, ip: string|null, browser: string, last_active: Carbon, current: bool}>
     */
    public function list(int $userId, ?string $currentId): array
    {
        if (! self::available()) {
            return [];
        }

        return DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId)->orderByDesc('last_activity')->get()
            ->map(fn (object $row): array => [
                'id' => (string) $row->id,
                'ip' => isset($row->ip_address) ? (string) $row->ip_address : null,
                'browser' => self::describe(isset($row->user_agent) ? (string) $row->user_agent : ''),
                'last_active' => Carbon::createFromTimestamp((int) $row->last_activity),
                'current' => $row->id === $currentId,
            ])->values()->all();
    }

    /** Signs one browser out; the current one is left alone. Returns whether a browser was signed out. */
    public function revoke(int $userId, string $id, ?string $currentId): bool
    {
        if (! self::available() || $id === $currentId) {
            return false;
        }
        $deleted = DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId)->where('id', $id)->delete() > 0;
        if ($deleted) {
            $this->audit->record('web_session_revoked', $userId);
        }

        return $deleted;
    }

    /** Signs every other browser out; returns how many. */
    public function revokeOthers(int $userId, ?string $currentId): int
    {
        if (! self::available()) {
            return 0;
        }
        $count = DB::table((string) config('session.table', 'sessions'))->where('user_id', $userId)->where('id', '!=', (string) $currentId)->delete();
        if ($count > 0) {
            $this->audit->record('web_session_revoked', $userId, null, $count === 1 ? 'Signed out 1 other browser' : "Signed out {$count} other browsers");
        }

        return $count;
    }

    /** "Chrome on Windows" from a user agent; good enough to recognise a browser, not a fingerprint. */
    public static function describe(string $agent): string
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'A browser',
        };
        $os = match (true) {
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Mac OS X') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => null,
        };

        return $os === null ? $browser : "{$browser} on {$os}";
    }
}
