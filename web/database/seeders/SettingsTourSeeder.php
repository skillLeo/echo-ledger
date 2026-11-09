<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Workspaces\Workspaces;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Sample customers for the Settings and Plan & usage screenshots (since v1.7, web/scripts/screenshots.mjs): one per
 * plan and one per stage of an upgrade request, each signing in with the documented local password. Local only.
 *
 *   php artisan db:seed --class=SettingsTourSeeder      (APP_ENV=local)
 */
final class SettingsTourSeeder extends Seeder
{
    /** email => [name, plan, minutes used this month, request stage or null] */
    public const PEOPLE = [
        'free@echoledger.local' => ['Hassam Ali', 'free', 0, null],
        'pro@echoledger.local' => ['Sara Khan', 'pro', 492, null],
        'ultra@echoledger.local' => ['Farah Ahmed', 'business', 1180, null],
        'awaiting@echoledger.local' => ['Bilal Raza', 'free', 0, 'awaiting_payment'],
        'review@echoledger.local' => ['Ayesha Malik', 'free', 0, 'in_review'],
        'receipt@echoledger.local' => ['Usman Tariq', 'free', 0, 'needs_receipt'],
        'declined@echoledger.local' => ['Zara Hussain', 'free', 0, 'declined'],
        'approved@echoledger.local' => ['Omar Siddiqui', 'pro', 40, 'approved'],
    ];

    public function run(): void
    {
        if (! app()->environment('local')) {
            throw new RuntimeException('SettingsTourSeeder makes sample accounts with a known password: local only.');
        }
        Plan::query()->where('key', 'business')->where('name', 'Business')->update(['name' => 'Ultra']);
        foreach (self::PEOPLE as $email => [$name, $planKey, $minutes, $stage]) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill(['name' => $name, 'password' => Hash::make(DatabaseSeeder::LOCAL_DEMO_PASSWORD), 'email_verified_at' => now(), 'consent_seen_at' => now()])->save();
            $plan = Plan::query()->where('key', $planKey)->firstOrFail();
            $workspace = $user->current_workspace_id !== null ? Workspace::query()->find($user->current_workspace_id) : null;
            $workspace ??= app(Workspaces::class)->createFor($user, 'Personal', $plan);
            $workspace->forceFill(['plan_id' => $plan->id, 'plan_expires_at' => $planKey === 'free' ? null : now()->addDays(30)->startOfDay()])->save();
            UsageLedgerEntry::query()->withoutGlobalScopes()->where('workspace_id', $workspace->id)->where('report_id', 'like', 'tour-%')->delete();
            if ($minutes > 0) {
                UsageLedgerEntry::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'source' => 'server', 'report_id' => 'tour-'.$user->id,
                    'use_case' => 'final_transcript', 'provider' => 'assemblyai', 'duration_ms' => $minutes * 60000, 'channels' => 1, 'billed_ms' => $minutes * 60000,
                    'unit_price_usd' => 0, 'cost_usd' => 0, 'billable' => true, 'occurred_at' => now()]);
            }
            UpgradeRequest::query()->where('user_id', $user->id)->delete();
            if ($stage !== null) {
                $this->request($user, $workspace, $stage);
            }
        }
    }

    private function request(User $user, Workspace $workspace, string $stage): void
    {
        $pro = Plan::query()->where('key', 'pro')->firstOrFail();
        $request = UpgradeRequest::query()->create(['user_id' => $user->id, 'workspace_id' => $workspace->id, 'plan' => 'pro', 'status' => UpgradeRequest::PENDING,
            'amount_due' => $pro->displayPrice(), 'message' => null]);
        $request->forceFill(['created_at' => now()->subHours(3)])->save();
        $columns = array_flip(Schema::getColumnListing('upgrade_requests'));
        $set = fn (array $values) => $request->forceFill(array_intersect_key($values, $columns))->save();
        match ($stage) {
            'in_review' => $set(['paid_at' => now()->subHour(), 'payment_reference' => 'FT26313ABC7781']),
            'needs_receipt' => $set(['paid_at' => now()->subHours(2), 'payment_reference' => 'FT26313ABC7781', 'proof_requested_at' => now()->subMinutes(30),
                'proof_request_note' => 'The screenshot is cut off at the amount. Please send one that shows the whole receipt.', 'round' => 2]),
            'declined' => $set(['status' => UpgradeRequest::DECLINED, 'decided_at' => now()->subDay(), 'reply' => 'We could not find this transfer in our account.']),
            'approved' => $set(['status' => UpgradeRequest::APPROVED, 'approved_plan' => 'pro', 'approved_until' => now()->addMonth()->startOfDay(), 'decided_at' => now()->subHour(), 'paid_at' => now()->subHours(2)]),
            default => null,
        };
    }
}
