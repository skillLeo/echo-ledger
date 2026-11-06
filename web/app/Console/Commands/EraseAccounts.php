<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Accounts\AccountEraser;
use Illuminate\Console\Command;
use Throwable;

/**
 * Erases the accounts whose 7-day deletion grace has ended (since v1.5, docs/API.md "Your account"). Hourly; an
 * account that fails is tried again on the next run.
 */
final class EraseAccounts extends Command
{
    protected $signature = 'echoledger:erase-accounts';

    protected $description = 'Erase accounts whose deletion date has passed, with everything only they own';

    public function handle(AccountEraser $eraser): int
    {
        $erased = 0;
        $failed = 0;
        foreach (User::query()->whereNotNull('deletes_at')->where('deletes_at', '<=', now())->orderBy('deletes_at')->limit(50)->get() as $user) {
            try {
                $result = $eraser->erase($user);
                $erased++;
                $this->line("Erased account #{$user->id}: {$result['recordings']} recording(s), {$result['projects']} project(s), {$result['workspaces']} workspace(s).");
            } catch (Throwable $e) {
                report($e);
                $failed++;
                $this->error("Account #{$user->id} could not be erased now; it is tried again on the next run.");
            }
        }
        $this->info("Erased {$erased} account(s).");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
