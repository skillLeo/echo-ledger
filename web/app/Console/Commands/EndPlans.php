<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\Workspace;
use App\Services\AuditLog;
use Illuminate\Console\Command;

/**
 * Plans with an end date (since v1.6): a workspace whose plan has ended goes back to the default plan. The entitlements
 * already apply the default plan from the moment it ends; this keeps every other page (usage, retention, the Plans
 * list) right too. Runs hourly.
 */
final class EndPlans extends Command
{
    protected $signature = 'echoledger:end-plans';

    protected $description = 'Moves workspaces whose plan has ended to the default plan';

    public function handle(AuditLog $audit): int
    {
        $default = Plan::defaultPlan();
        $moved = 0;
        Workspace::query()->whereNotNull('plan_expires_at')->where('plan_expires_at', '<=', now())->orderBy('id')
            ->each(function (Workspace $workspace) use ($default, $audit, &$moved): void {
                $workspace->forceFill(['plan_id' => $default->id, 'plan_expires_at' => null])->save();
                $audit->record('plan_assigned', null, $workspace, "{$workspace->name}'s plan ended: back on {$default->name}");
                $moved++;
            });
        $this->info("{$moved} workspace(s) back on {$default->name}.");

        return self::SUCCESS;
    }
}
