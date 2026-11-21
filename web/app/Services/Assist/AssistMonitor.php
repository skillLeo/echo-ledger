<?php

declare(strict_types=1);

namespace App\Services\Assist;

use App\Models\AssistRelayCheck;
use App\Models\AssistSession;
use App\Models\AssistUsage;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Live Assist right now, for the super admin (plan B4): whether the relay is up (the 5-minute checks, assist:relay-check,
 * and when the relay last called the server), its uptime and outages over 7 days, the sessions running, and the last
 * hour's speed and errors. Numbers, times and states only: never a word anyone said, no token, no key.
 */
class AssistMonitor
{
    /** When the relay last called the server's internal API (VerifyAssistRelay), unix seconds. */
    public const CONTACT_KEY = 'assist.relay_last_contact';

    /** The stamp moves at most once a minute: one write per minute however many suggestions the relay reports. */
    public const CONTACT_EVERY_SECONDS = 60;

    /** A check missing for this long means the checks have stopped (the scheduler is not running). */
    public const CHECKS_STALE_MINUTES = 15;

    public function __construct(private readonly RelayHealth $health, private readonly AssistTransport $transport, private readonly SettingsRepository $settings) {}

    /** Notes that the relay just called the server, signed (once a minute at most). */
    public static function sawRelay(): void
    {
        // A note for the admin only: a cache that fails must never fail the relay's own call.
        try {
            $last = Cache::get(self::CONTACT_KEY);
            $now = now()->getTimestamp();
            if (! is_int($last) || $now - $last >= self::CONTACT_EVERY_SECONDS) {
                Cache::put(self::CONTACT_KEY, $now, now()->addDays(AssistRelayCheck::KEEP_DAYS));
            }
        } catch (\Throwable) {
            // The next call tries again.
        }
    }

    /**
     * Asks the relay once now (RelayHealth::probe: public addresses only, pinned, no redirects, 3 s, no retry) and keeps
     * the result: the scheduled check every 5 minutes and "Check the relay now" both come here. Null with no relay set.
     *
     * @return array{ok: bool, host: string, version: ?string, ms: int, error: ?string, at: int}|null
     */
    public function checkNow(string $source = AssistRelayCheck::MANUAL): ?array
    {
        if ($this->health->host() === null) {
            return null;
        }
        $result = $this->health->check();
        AssistRelayCheck::query()->create([
            'host' => $result['host'],
            'checked_at' => Carbon::createFromTimestamp($result['at']),
            'source' => $source === AssistRelayCheck::SCHEDULED ? AssistRelayCheck::SCHEDULED : AssistRelayCheck::MANUAL,
            'ok' => $result['ok'],
            'ms' => max(0, $result['ms']),
            'version' => $result['ok'] ? $result['version'] : null,
            // The check's own sentence (RelayHealth), never what the relay sent back.
            'error' => $result['ok'] ? null : mb_substr((string) $result['error'], 0, 120),
        ]);

        return $result;
    }

    /** Drops the checks older than 7 days; returns how many. */
    public static function prune(): int
    {
        return AssistRelayCheck::query()->where('checked_at', '<', now()->subDays(AssistRelayCheck::KEEP_DAYS))->delete();
    }

    /** @return array<string, mixed> */
    public function now(): array
    {
        return ['relay' => $this->relay(), 'sessions' => $this->sessions(), 'hour' => $this->lastHour()];
    }

    /** @return array<string, mixed> */
    private function relay(): array
    {
        $host = $this->health->host();
        $contact = Cache::get(self::CONTACT_KEY);
        $relay = [
            'host' => $host,
            'connection' => AssistTransport::CHOICES[$this->transport->choice()],
            'secret_set' => $this->transport->relayConfigured(),
            'last_contact' => is_int($contact) ? Carbon::createFromTimestamp($contact) : null,
            'status' => $host === null ? 'not_set' : 'unknown',
            'latest' => null, 'checks_stale' => false,
            'uptime_24h' => null, 'uptime_7d' => null, 'checks_24h' => 0, 'outages' => [],
        ];
        if ($host === null) {
            return $relay;
        }

        // Uptime, outages and whether the checks stopped come from the scheduled checks only: a check by hand would
        // weigh a moment twice and hide a scheduler that stopped. The newest answer of any kind says up or down now.
        $checks = AssistRelayCheck::query()->where('host', $host)->where('source', AssistRelayCheck::SCHEDULED)
            ->where('checked_at', '>=', now()->subDays(AssistRelayCheck::KEEP_DAYS))
            ->orderBy('checked_at')->get(['checked_at', 'ok', 'ms', 'version', 'error']);
        $last = $checks->last();
        $newest = AssistRelayCheck::query()->where('host', $host)->latest('checked_at')->first(['checked_at', 'ok', 'ms', 'version', 'error']);
        $recent = $this->health->recent();
        if ($recent !== null && ($newest === null || $recent['at'] > $newest->checked_at->getTimestamp())) {
            $relay['latest'] = ['ok' => $recent['ok'], 'ms' => $recent['ms'], 'version' => $recent['version'], 'error' => $recent['error'], 'at' => Carbon::createFromTimestamp($recent['at'])];
        } elseif ($newest !== null) {
            $relay['latest'] = ['ok' => $newest->ok, 'ms' => $newest->ms, 'version' => $newest->version, 'error' => $newest->error, 'at' => $newest->checked_at];
        }
        if ($relay['latest'] !== null) {
            $relay['status'] = $relay['latest']['ok'] ? 'up' : 'down';
        }
        $relay['checks_stale'] = $last === null || $last->checked_at->lt(now()->subMinutes(self::CHECKS_STALE_MINUTES));

        $day = $checks->filter(fn (AssistRelayCheck $c): bool => $c->checked_at->gte(now()->subDay()));
        $relay['checks_24h'] = $day->count();
        $relay['uptime_24h'] = $day->isEmpty() ? null : round($day->where('ok', true)->count() * 100 / $day->count(), 2);
        $relay['uptime_7d'] = $checks->isEmpty() ? null : round($checks->where('ok', true)->count() * 100 / $checks->count(), 2);

        // Outages: runs of failed checks, from the first failure to the first answer after it, newest first.
        $outages = [];
        $open = null;
        foreach ($checks as $check) {
            if (! $check->ok && $open === null) {
                $open = ['from' => $check->checked_at, 'to' => null, 'checks' => 0, 'error' => $check->error];
            }
            if (! $check->ok) {
                $open['checks']++;
            } elseif ($open !== null) {
                $outages[] = [...$open, 'to' => $check->checked_at];
                $open = null;
            }
        }
        if ($open !== null) {
            $outages[] = $open;
        }
        $relay['outages'] = array_map(fn (array $o): array => [...$o,
            'minutes' => (int) round($o['from']->diffInSeconds($o['to'] ?? now(), true) / 60)], array_slice(array_reverse($outages), 0, 10));

        return $relay;
    }

    /** @return array<string, int> */
    private function sessions(): array
    {
        $active = AssistSession::query()->withoutGlobalScopes()->where('status', 'active')->whereNull('ended_at')
            ->selectRaw('transport, practice, COUNT(*) AS n')->groupBy('transport', 'practice')->toBase()->get();
        $count = fn (callable $where): int => (int) $active->filter($where)->sum('n');
        $today = Carbon::now($this->settings->displayTimezone())->startOfDay()->utc();

        return [
            'active' => $count(fn (): bool => true),
            'active_relay' => $count(fn (object $r): bool => $r->transport === AssistTransport::TRANSPORT_RELAY),
            'active_http' => $count(fn (object $r): bool => $r->transport === AssistTransport::TRANSPORT_HTTP),
            'active_practice' => $count(fn (object $r): bool => (bool) $r->practice),
            'started_hour' => AssistSession::query()->withoutGlobalScopes()->withTrashed()->where('started_at', '>=', now()->subHour())->count(),
            'started_today' => AssistSession::query()->withoutGlobalScopes()->withTrashed()->where('started_at', '>=', $today)->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function lastHour(): array
    {
        $usage = AssistUsage::query()->where('occurred_at', '>=', now()->subHour());
        $firstWord = [];
        $overhead = [];
        $errors = 0;
        $timed = 0;
        (clone $usage)->whereIn('kind', AssistStats::TIMED_KINDS)->select(['outcome', 'latency'])->limit(AssistStats::MAX_TIMED_ROWS)
            ->each(function (AssistUsage $row) use (&$firstWord, &$overhead, &$errors, &$timed): void {
                $timed++;
                $errors += $row->outcome === 'error' ? 1 : 0;
                $first = $row->latency['end_of_turn_to_first_token_ms'] ?? null;
                if (is_numeric($first) && $first >= 0) {
                    $firstWord[] = (float) $first;
                }
                $relay = $row->latency['relay_overhead_ms'] ?? null;
                if (is_numeric($relay) && $relay >= 0) {
                    $overhead[] = (float) $relay;
                }
            });

        return [
            'suggestions' => $timed,
            'first_word' => AssistStats::percentiles($firstWord),
            'overhead' => AssistStats::percentiles($overhead),
            'error_share' => $timed > 0 ? round($errors * 100 / $timed, 1) : null,
            'cost_usd' => round((float) (clone $usage)->sum('cost_usd'), 4),
        ];
    }
}
