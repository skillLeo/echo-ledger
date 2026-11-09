<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Exceptions\ApiError;
use App\Mail\UpgradeDecidedMail;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Upgrade requests (since v1.6, P1.4, docs/API.md "Upgrade requests"): a person asks for a plan on offer from the
 * workspace they are in, with an optional message, and sees the admin's payment instructions; one request waits at a
 * time. The super admin approves it (the workspace goes on the plan, until a date or not) or declines it with a reply;
 * either way the person gets an email and an activity item. No payment is taken here (a gateway comes later).
 */
final class UpgradeRequests
{
    public const DEFAULT_INSTRUCTIONS = 'We will email you how to pay. Once the payment is confirmed, we switch your plan on.';

    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLog $audit) {}

    /** The admin's text (Settings → Plans → Payment instructions): bank or JazzCash details and what to write. */
    public function paymentInstructions(): string
    {
        $text = $this->settings->get('upgrade.payment_instructions');

        return is_string($text) && trim($text) !== '' ? trim($text) : self::DEFAULT_INSTRUCTIONS;
    }

    public function pendingFor(User $user): ?UpgradeRequest
    {
        return UpgradeRequest::query()->where('user_id', $user->id)->where('status', UpgradeRequest::PENDING)->latest('created_at')->first();
    }

    public function latestFor(User $user): ?UpgradeRequest
    {
        return UpgradeRequest::query()->where('user_id', $user->id)->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    /**
     * A new request for the plan [$planKey] (one on offer, other than the one the workspace is on). `409
     * request_pending` with the pending one while another waits.
     */
    public function create(User $user, int $workspaceId, string $planKey, ?string $message): UpgradeRequest
    {
        $plan = Plan::query()->where('key', $planKey)->where('on_offer', true)->whereNull('archived_at')->first();
        if (! $plan instanceof Plan) {
            throw ValidationException::withMessages(['plan' => 'Choose one of the plans on offer.']);
        }
        if (app(Entitlements::class)->plan($workspaceId)->is($plan)) {
            throw ValidationException::withMessages(['plan' => "You are on {$plan->name} already."]);
        }

        $request = DB::transaction(function () use ($user, $workspaceId, $plan, $message): UpgradeRequest {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $pending = $this->pendingFor($user);
            if ($pending instanceof UpgradeRequest) {
                throw new ApiError(409, 'request_pending', "Your request for {$this->planName($pending->plan)} is still waiting. We will tell you once it is decided.", ['request' => $this->payload($pending, created: true)]);
            }

            return UpgradeRequest::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'plan' => $plan->key,
                'message' => filled($message) ? mb_substr(trim((string) $message), 0, 1000) : null,
                'status' => UpgradeRequest::PENDING,
            ]);
        });
        $this->audit->record('upgrade_requested', $user->id, $request, "Asked for the {$plan->name} plan");
        // The super admins see it in the bell (and on System → Upgrade requests).
        User::query()->where('is_super_admin', true)->get()->each(fn (User $admin) => app(ActivityFeed::class)
            ->post($admin, 'upgrade_requested', 'Upgrade request', "{$user->name} ({$user->email}) asks for {$plan->name}.", null, 'heroicon-o-arrow-trending-up'));

        return $request;
    }

    /** Approves: the workspace goes on [$planKey], until [$until] or not; the person is told. */
    public function approve(UpgradeRequest $request, User $admin, string $planKey, ?Carbon $until): void
    {
        $plan = Plan::query()->where('key', $planKey)->firstOrFail();
        DB::transaction(function () use ($request, $admin, $plan, $until): void {
            Workspace::query()->whereKey($request->workspace_id)->update(['plan_id' => $plan->id, 'plan_expires_at' => $until]);
            $request->forceFill([
                'status' => UpgradeRequest::APPROVED, 'approved_plan' => $plan->key, 'approved_until' => $until,
                'decided_by' => $admin->id, 'decided_at' => now(), 'reply' => null,
            ])->save();
        });
        app(Entitlements::class)->forget();
        $this->audit->record('upgrade_approved', $admin->id, $request, "Upgrade to {$plan->name} approved for ".$request->user->email);
        $this->tell($request, true, $plan->name, $until, null);
    }

    /** Declines with [$reply]; the person is told. */
    public function decline(UpgradeRequest $request, User $admin, string $reply): void
    {
        $request->forceFill(['status' => UpgradeRequest::DECLINED, 'reply' => mb_substr(trim($reply), 0, 1000), 'decided_by' => $admin->id, 'decided_at' => now()])->save();
        $this->audit->record('upgrade_declined', $admin->id, $request, 'Upgrade request declined for '.$request->user->email);
        $this->tell($request, false, $this->planName($request->plan), null, $request->reply);
    }

    private function tell(UpgradeRequest $request, bool $approved, string $planName, ?Carbon $until, ?string $reply): void
    {
        $user = $request->user;
        $when = $until?->copy()->setTimezone($this->settings->displayTimezone())->format('M j, Y');
        Mail::to($user->email)->queue(new UpgradeDecidedMail($approved, $planName, $when, $reply, url('/app')));
        $approved
            ? app(ActivityFeed::class)->post($user, 'upgrade_approved', "You are on {$planName} now", $when !== null ? "Until {$when}. Thank you!" : 'Thank you! Everything in it is on.', null, 'heroicon-o-check-badge')
            : app(ActivityFeed::class)->post($user, 'upgrade_declined', "Your request for {$planName} was declined", $reply ?? 'Write to us if you have a question.', null, 'heroicon-o-x-circle');
    }

    private function planName(string $key): string
    {
        return (string) (Plan::query()->where('key', $key)->value('name') ?? $key);
    }

    /**
     * The request as the API answers it: since its creation with the payment instructions, once decided with the
     * reply and when.
     *
     * @return array<string, mixed>
     */
    public function payload(UpgradeRequest $request, bool $created = false): array
    {
        $out = [
            'id' => $request->id,
            'plan' => $request->plan,
            'status' => $request->status,
            'message' => $request->message,
            'payment_instructions' => $this->paymentInstructions(),
            'created_at' => $request->created_at->toAtomString(),
        ];
        if ($created) {
            return $out;
        }

        return [...$out, 'decided_at' => $request->decided_at?->toAtomString(), 'reply' => $request->reply,
            'approved_plan' => $request->approved_plan, 'approved_until' => $request->approved_until?->toAtomString()];
    }

    /** @return array{id: string, plan: string, status: string, created_at: string}|null the newest request as the entitlements show it */
    public function latestPayload(User $user): ?array
    {
        $request = $this->latestFor($user);

        return $request === null ? null : ['id' => $request->id, 'plan' => $request->plan, 'status' => $request->status, 'created_at' => $request->created_at->toAtomString()];
    }
}
