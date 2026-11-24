<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\ServerHealthFixedMail;
use App\Mail\ServerHealthMail;
use App\Models\User;
use App\Services\ActivityFeed;
use App\Services\Health\ServerHealth;
use Illuminate\Console\Command;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Whether this PHP has every extension the server needs and can encrypt and decrypt a recording (W-INC-1124). Exits 1
 * when not, for deploy/check-php.sh. The scheduler runs it with --report every five minutes: a failure writes one
 * critical log line, stays on the admin dashboard until a check passes, and emails the super admins at most once an
 * hour, also when it flaps. Since W-INC-1125 (the hosting dropped sodium a second time): the email names what is
 * missing in which PHP and the command that turns it back on, the first pass after a mailed failure emails "fixed",
 * and with --web the scheduled run asks the web PHP too (/health/php), which uploads encrypt with.
 */
final class CheckServerHealth extends Command
{
    protected $signature = 'echoledger:health
        {--report : Log a failure, show it on the admin dashboard and email the super admins (the scheduled run)}
        {--web : Also ask the web PHP, at the console\'s /health/php (the scheduled run)}';

    protected $description = 'Checks the PHP extensions the server needs and encrypts and decrypts a small test file';

    public function handle(ServerHealth $health): int
    {
        $result = $health->check();

        $this->line('PHP '.PHP_VERSION.' ('.PHP_SAPI.')');
        if ($result['missing'] === []) {
            $this->info('All required PHP extensions are loaded: '.implode(', ', $health->required()).'.');
        } else {
            $this->error('Missing PHP extensions: '.implode(', ', $result['missing']));
        }
        if ($result['cipher'] === null) {
            $this->info('Recordings cipher: works.');
        } else {
            $this->error('Recordings cipher: fails. '.$result['cipher']);
        }

        $web = $this->option('web') ? $this->askWeb() : null;
        $healthy = ServerHealth::healthy($result) && ($web === null || $web['ok'] !== false);
        if ($this->option('report')) {
            // Only a real answer from the web PHP clears its part (W-INC-1125 review): when it could not be asked (a
            // timeout, a 502) while a fault is still reported, this run neither raises nor clears, so a blip of the
            // site never sends a false "fixed".
            $webUnknown = $web !== null && $web['ok'] === null;
            if ($healthy && $webUnknown && (Cache::has(ServerHealth::ACTIVITY_OPEN) || Cache::has(ServerHealth::MAILED_FAULT))) {
                $this->warn('A fault is still reported and the web PHP could not be asked: left as it is.');
            } else {
                $healthy ? $this->clear() : $this->raise($result, $web);
            }
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /**
     * The web PHP's answer at /health/php: ok, failing (with what it lacks, which it kept in the cache), or null when it
     * could not be asked (the site down, a timeout): logged, never mailed as a PHP fault.
     *
     * @return array{ok: bool|null, missing: list<string>, cipher: string|null}
     */
    private function askWeb(): array
    {
        try {
            $response = Http::timeout(15)->acceptJson()->withHeaders(['Cache-Control' => 'no-cache'])->get(url('/health/php'));
            $status = $response->status();
            // Only the web PHP's own answer counts (W-INC-1125 review): {"ok": true} with 200, {"ok": false} with 503.
            // Any other page with those codes (the hosting's proxy under load, its maintenance page) is "could not ask".
            $said = is_array($response->json()) ? ($response->json()['ok'] ?? null) : null;
        } catch (Throwable $e) {
            $status = 0;
            $said = null;
        }
        if ($status === 200 && $said === true) {
            $this->info('Web PHP: ok.');

            return ['ok' => true, 'missing' => [], 'cipher' => null];
        }
        if ($status === 503 && $said === false) {
            $kept = Cache::get(ServerHealth::WEB);
            $missing = is_array($kept) && is_array($kept['missing'] ?? null) ? array_values($kept['missing']) : [];
            $cipher = is_array($kept) && is_string($kept['cipher'] ?? null) ? $kept['cipher'] : null;
            $this->error('Web PHP: fails.'.($missing !== [] ? ' Missing: '.implode(', ', $missing) : ''));

            return ['ok' => false, 'missing' => $missing, 'cipher' => $cipher ?? ($missing === [] ? 'the web PHP fails its check' : null)];
        }
        Log::warning('Server health check: the web PHP could not be asked at /health/php.', ['status' => $status]);
        $this->warn('Web PHP: not asked (answered '.($status ?: 'nothing').').');

        return ['ok' => null, 'missing' => [], 'cipher' => null];
    }

    /**
     * @param  array{missing: list<string>, cipher: string|null}  $result
     * @param  array{ok: bool|null, missing: list<string>, cipher: string|null}|null  $web
     */
    private function raise(array $result, ?array $web): void
    {
        $webFails = $web !== null && $web['ok'] === false;
        Log::critical('Server health check failed: '.ServerHealth::summary($result).($webFails ? '; the web PHP: '.ServerHealth::summary($web) : '').'.', ['php' => PHP_VERSION]);
        // The dashboard's banner for the scheduled tasks' PHP: only its own fault (the web PHP shows its own there).
        ServerHealth::healthy($result) ? Cache::forget(ServerHealth::SCHEDULED)
            : Cache::forever(ServerHealth::SCHEDULED, [...$result, 'php' => PHP_VERSION, 'checked_at' => now()->toIso8601String()]);

        // Since W-INC-1125: an Activity item for each super admin, every run (it only changes when the words do): the
        // bell and the badge reach them where no email can (production had no real mailer for months).
        $webMissing = $webFails ? $web['missing'] : [];
        [$title, $body] = ServerHealth::alertText([...$result['missing'], ...$webMissing], $result['cipher'] ?? ($webFails ? $web['cipher'] : null));
        app(ActivityFeed::class)->serverProblem($title, $body);
        Cache::forever(ServerHealth::ACTIVITY_OPEN, true);

        if (! Cache::add(ServerHealth::MAILED, true, now()->addHour())) {
            return;
        }
        $fix = ServerHealth::fixCommand([...$result['missing'], ...$webMissing]);
        $sent = $this->mailAdmins(fn (): Mailable => new ServerHealthMail($result['missing'], $result['cipher'], PHP_VERSION, url('/admin'),
            $webMissing, $webFails ? $web['cipher'] : null, $webFails, $fix));
        if ($sent) {
            Cache::forever(ServerHealth::MAILED_FAULT, now()->toIso8601String());
        }
    }

    /**
     * The hour since the last email stays: a check that flaps between failing and passing mails once an hour at most.
     * After a mailed failure, the first pass mails "fixed" (once per mailed failure, so at most once an hour too).
     */
    private function clear(): void
    {
        if (Cache::pull(ServerHealth::SCHEDULED) !== null) {
            Log::info('Server health check passes again.');
        }
        if (Cache::pull(ServerHealth::ACTIVITY_OPEN) !== null) {
            app(ActivityFeed::class)->serverProblemResolved();
        }
        $since = Cache::pull(ServerHealth::MAILED_FAULT);
        if (is_string($since)) {
            $this->mailAdmins(fn (): Mailable => new ServerHealthFixedMail(PHP_VERSION, $since, url('/admin')));
        }
    }

    /**
     * Sent at once to every super admin, not queued: the queue runs on the PHP that may be failing.
     *
     * @param  callable(): Mailable  $mail
     * @return bool whether at least one went out
     */
    private function mailAdmins(callable $mail): bool
    {
        $sent = false;
        User::query()->where('is_super_admin', true)->get()->each(function (User $admin) use ($mail, &$sent): void {
            try {
                Mail::to($admin->email)->send($mail());
                $sent = true;
            } catch (Throwable $e) {
                report($e);
            }
        });

        return $sent;
    }
}
