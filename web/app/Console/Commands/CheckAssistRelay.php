<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AssistRelayCheck;
use App\Services\Assist\AssistMonitor;
use Illuminate\Console\Command;

/**
 * Every 5 minutes (plan B4): asks the Live Assist relay for its health once and keeps the answer for the admin's
 * up/down and uptime (AssistMonitor::checkNow), and drops checks older than 7 days. Nothing to ask when no relay
 * address is set.
 */
final class CheckAssistRelay extends Command
{
    protected $signature = 'assist:relay-check';

    protected $description = 'Check the Live Assist relay\'s health once and keep the result for 7 days';

    public function handle(AssistMonitor $monitor): int
    {
        AssistMonitor::prune();
        $result = $monitor->checkNow(AssistRelayCheck::SCHEDULED);
        if ($result === null) {
            $this->line('No relay address is set.');
        } else {
            $this->line($result['ok'] ? "Relay ok ({$result['ms']} ms)" : 'Relay down: '.$result['error']);
        }

        return self::SUCCESS;
    }
}
