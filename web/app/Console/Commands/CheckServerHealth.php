<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\ServerHealthMail;
use App\Models\User;
use App\Services\Health\ServerHealth;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Whether this PHP has every extension the server needs and can encrypt and decrypt a recording (W-INC-1124). Exits 1
 * when not, for deploy/check-php.sh. The scheduler runs it with --report every five minutes: a failure writes one
 * critical log line, stays on the admin dashboard until a check passes, and emails the super admins once an hour.
 */
final class CheckServerHealth extends Command
{
    protected $signature = 'echoledger:health {--report : Log a failure, show it on the admin dashboard and email the super admins (the scheduled run)}';

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

        $healthy = ServerHealth::healthy($result);
        if ($this->option('report')) {
            $healthy ? $this->clear() : $this->raise($result);
        }

        return $healthy ? self::SUCCESS : self::FAILURE;
    }

    /** @param array{missing: list<string>, cipher: string|null} $result */
    private function raise(array $result): void
    {
        Log::critical('Server health check failed: '.ServerHealth::summary($result).'.', ['php' => PHP_VERSION]);
        Cache::forever(ServerHealth::SCHEDULED, [...$result, 'php' => PHP_VERSION, 'checked_at' => now()->toIso8601String()]);

        if (! Cache::add(ServerHealth::MAILED, true, now()->addHour())) {
            return;
        }
        User::query()->where('is_super_admin', true)->get()->each(function (User $admin) use ($result): void {
            try {
                Mail::to($admin->email)->send(new ServerHealthMail($result['missing'], $result['cipher'], PHP_VERSION, url('/admin')));
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    private function clear(): void
    {
        if (Cache::pull(ServerHealth::SCHEDULED) !== null) {
            Log::info('Server health check passes again.');
        }
        Cache::forget(ServerHealth::MAILED);
    }
}
