<?php

declare(strict_types=1);

use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Accounts\AccountDeletion;
use App\Services\Usage\UsageBreakdown;
use App\Services\Workspaces\WorkspaceEraser;
use App\Services\Workspaces\Workspaces;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Feature\Security\Support\SecurityFixtures as Fx;

/**
 * What the server paid a provider stays on Server usage when the account or workspace that used it goes (since v1.5):
 * the ledger rows are kept without the person and the workspace (anonymised), so the provider totals stay true.
 */
function spend(User $user, int $workspaceId, float $cost): UsageLedgerEntry
{
    return UsageLedgerEntry::query()->withoutGlobalScopes()->create([
        'workspace_id' => $workspaceId, 'user_id' => $user->id,
        'source' => 'server', 'report_id' => 'final/'.Str::ulid().'/a1', 'use_case' => 'final', 'provider' => 'assemblyai',
        'duration_ms' => 600000, 'channels' => 1, 'billed_ms' => 600000, 'unit_price_usd' => 0.15,
        'cost_usd' => $cost, 'billable' => true, 'occurred_at' => now(),
    ]);
}

function serverTotal(): float
{
    return app(UsageBreakdown::class)->report(now()->subMonth(), now()->addDay())['totals']['cost_usd'];
}

it('keeps an erased account\'s usage, without the person or its own workspace', function () {
    $user = Fx::user(['email' => 'gone@example.test']);
    $personal = (int) $user->current_workspace_id;
    $row = spend($user, $personal, 0.25);
    $before = serverTotal();

    app(AccountDeletion::class)->schedule($user->fresh());
    $this->travel(7)->days();
    $this->travel(1)->minutes();
    expect(Artisan::call('echoledger:erase-accounts'))->toBe(0);

    expect(User::query()->find($user->id))->toBeNull()
        ->and(Workspace::query()->find($personal))->toBeNull();
    $kept = UsageLedgerEntry::query()->withoutGlobalScopes()->find($row->id);
    expect($kept)->not->toBeNull()
        ->and($kept->user_id)->toBeNull()
        ->and($kept->workspace_id)->toBeNull()
        ->and((float) $kept->cost_usd)->toBe(0.25)
        ->and(serverTotal())->toBe($before);
});

it('keeps a deleted workspace\'s usage, and its person\'s, without the workspace', function () {
    $owner = Fx::user();
    $team = app(Workspaces::class)->createFor($owner, 'Short-lived team');
    $row = spend($owner, $team->id, 0.5);
    $before = serverTotal();

    app(WorkspaceEraser::class)->erase($team->fresh(), $owner->fresh());

    $kept = UsageLedgerEntry::query()->withoutGlobalScopes()->find($row->id);
    expect(Workspace::query()->find($team->id))->toBeNull()
        ->and($kept)->not->toBeNull()
        ->and($kept->workspace_id)->toBeNull()
        ->and($kept->user_id)->toBe($owner->id)
        ->and(serverTotal())->toBe($before);
});
