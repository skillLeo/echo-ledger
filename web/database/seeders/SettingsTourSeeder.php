<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Device;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
use App\Models\UsageLedgerEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Entitlements\PaymentProofs;
use App\Services\SettingsRepository;
use App\Services\Workspaces\Workspaces;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
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
        // Sample bank details (made up), as staff would enter them under Admin → Plans → Payment details.
        app(SettingsRepository::class)->setMany(['payment.bank' => 'Example Bank', 'payment.branch' => 'Main Branch (0001)', 'payment.account_title' => 'EXAMPLE PRIVATE LIMITED',
            'payment.account_number' => '0123456789', 'payment.iban' => 'PK36SCBL0000001123456702']);
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
            Device::query()->withoutGlobalScopes()->where('user_id', $user->id)->delete();
            $devices = [['Galaxy S25 Ultra', 'android', '16', 5], ['Office PC', 'windows', '11', 180]];
            foreach (array_slice($devices, 0, $planKey === 'free' ? 1 : 2) as [$device, $platform, $os, $minutes]) {
                Device::query()->create(['workspace_id' => $workspace->id, 'user_id' => $user->id, 'name' => $device, 'platform' => $platform,
                    'app_version' => '1.7.0', 'os_version' => $os, 'last_seen_at' => now()->subMinutes($minutes)])->forceFill(['name_chosen' => true])->save();
            }
            UpgradeRequestProof::query()->where('user_id', $user->id)->delete();
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
            'amount_due' => $pro->priceText(), 'amount_due_pkr' => $pro->pricePkr(), 'message' => null]);
        $request->forceFill(['created_at' => now()->subHours(3)])->save();
        $proofs = app(PaymentProofs::class);
        $staff = User::query()->where('is_super_admin', true)->first() ?? $user;
        if (in_array($stage, ['in_review', 'needs_receipt'], true)) {
            $proofs->add($request, $user, $this->receiptPicture('Rs 2,999'), 'Screenshot_bank.png');
            $proofs->markPaid($request->fresh(), 'FT26313ABC7781', null);
        }
        if ($stage === 'needs_receipt') {
            $proofs->requestNewReceipt($request->fresh(), $staff, 'The screenshot is cut off at the amount. Please send one that shows the whole receipt.');
        }
        match ($stage) {
            'declined' => $request->forceFill(['status' => UpgradeRequest::DECLINED, 'decided_at' => now()->subHour(), 'reply' => 'We could not find this transfer in our account.'])->save(),
            'approved' => $request->forceFill(['status' => UpgradeRequest::APPROVED, 'approved_plan' => 'pro', 'approved_until' => now()->addMonth()->startOfDay(),
                'decided_at' => now()->subHour(), 'paid_at' => now()->subHours(2), 'payment_reference' => 'FT26313ABC7781'])->save(),
            default => null,
        };
    }

    /** A plain picture of a bank app's receipt, for the sample receipts. */
    private function receiptPicture(string $amount): string
    {
        $image = imagecreatetruecolor(540, 960);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 246, 247, 249));
        $dark = (int) imagecolorallocate($image, 28, 32, 36);
        $muted = (int) imagecolorallocate($image, 96, 100, 108);
        imagefilledrectangle($image, 0, 0, 540, 120, (int) imagecolorallocate($image, 18, 110, 84));
        imagestring($image, 5, 30, 50, 'Transfer successful', (int) imagecolorallocate($image, 255, 255, 255));
        foreach ([['Amount', $amount], ['To', 'EXAMPLE PRIVATE LIMITED'], ['Account', '0123456789'], ['Transaction ID', 'FT26313ABC7781'], ['Date', now()->format('d M Y, H:i')]] as $i => [$label, $value]) {
            imagestring($image, 3, 30, 180 + $i * 70, $label, $muted);
            imagestring($image, 5, 30, 200 + $i * 70, $value, $dark);
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'receipt');
        imagepng($image, $path);
        imagedestroy($image);

        return $path;
    }
}
