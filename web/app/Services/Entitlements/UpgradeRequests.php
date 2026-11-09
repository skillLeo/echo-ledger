<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Exceptions\ApiError;
use App\Mail\UpgradeDecidedMail;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Services\ActivityFeed;
use App\Services\AuditLog;
use App\Services\SettingsRepository;
use App\Services\Staff\StaffAccess;
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
     * request_pending` with the pending one while another waits. Since v1.6.2 with an optional promo code for that plan
     * (`422 invalid_promo_code`, `422 promo_other_plan`): the request keeps the code, the percent and the amount due,
     * and at 100 % it is approved at once, the plan on for the code's months.
     */
    public function create(User $user, int $workspaceId, string $planKey, ?string $message, ?string $promoCode = null): UpgradeRequest
    {
        $plan = Plan::query()->where('key', $planKey)->where('on_offer', true)->whereNull('archived_at')->first();
        if (! $plan instanceof Plan) {
            throw ValidationException::withMessages(['plan' => 'Choose one of the plans on offer.']);
        }
        if (app(Entitlements::class)->plan($workspaceId)->is($plan)) {
            throw ValidationException::withMessages(['plan' => "You are on {$plan->name} already."]);
        }
        $promoCode = filled($promoCode) ? (string) $promoCode : null;

        $request = DB::transaction(function () use ($user, $workspaceId, $plan, $message, $promoCode): UpgradeRequest {
            User::query()->whereKey($user->id)->lockForUpdate()->first();
            $pending = $this->pendingFor($user);
            if ($pending instanceof UpgradeRequest) {
                throw new ApiError(409, 'request_pending', "Your request for {$this->planName($pending->plan)} is still waiting. We will tell you once it is decided.", ['request' => $this->payload($pending, created: true)]);
            }
            // The code is read again under a lock, so two people cannot take its last use at once.
            $promo = $promoCode !== null ? app(PromoCodes::class)->usable($user, $promoCode, lock: true) : null;
            if ($promo instanceof PromoCode && $promo->plan !== $plan->key) {
                throw PromoCodes::otherPlan(app(PromoCodes::class)->planOf($promo));
            }
            $free = $promo instanceof PromoCode && $promo->percent >= 100;
            $until = $free ? now()->addMonths(max(1, $promo->months)) : null;

            $request = UpgradeRequest::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'plan' => $plan->key,
                'message' => filled($message) ? mb_substr(trim((string) $message), 0, 1000) : null,
                'promo_code' => $promo?->code,
                'percent' => $promo?->percent,
                'amount_due' => $promo instanceof PromoCode ? PromoCodes::discounted($plan, $promo->percent) : $plan->displayPrice(),
                'status' => $free ? UpgradeRequest::APPROVED : UpgradeRequest::PENDING,
            ]);
            if ($free) {
                // Nothing to pay: the plan is on at once, for the code's months.
                Workspace::query()->whereKey($workspaceId)->update(['plan_id' => $plan->id, 'plan_expires_at' => $until]);
                $request->forceFill(['approved_plan' => $plan->key, 'approved_until' => $until, 'decided_at' => now()])->save();
            }
            if ($promo instanceof PromoCode) {
                PromoCodeUse::query()->create(['promo_code_id' => $promo->id, 'user_id' => $user->id, 'upgrade_request_id' => $request->id, 'used_at' => now()]);
            }

            return $request;
        });
        if ($request->promo_code !== null) {
            $this->audit->record('promo_code_used', $user->id, $request, "Used the promo code {$request->promo_code} ({$request->percent} %) for {$plan->name}");
        }
        if ($request->status === UpgradeRequest::APPROVED) {
            app(Entitlements::class)->forget();
            $this->audit->record('upgrade_approved', $user->id, $request, "{$plan->name} switched on with the promo code {$request->promo_code}");
            $this->tell($request, true, $plan->name, $request->approved_until, null);

            return $request;
        }
        $this->audit->record('upgrade_requested', $user->id, $request, "Asked for the {$plan->name} plan");
        // Staff who answer them see it in the bell (and on Admin panel → Upgrade requests).
        $code = $request->promo_code !== null ? " with the code {$request->promo_code} ({$request->percent} % off, {$request->amount_due})" : '';
        User::query()->where(fn ($q) => $q->where('is_super_admin', true)->orWhereNotNull('staff_role_id'))->get()
            ->filter(fn (User $staff): bool => StaffAccess::can('upgrades.manage', $staff))
            ->each(fn (User $staff) => app(ActivityFeed::class)
                ->post($staff, 'upgrade_requested', 'Upgrade request', "{$user->name} ({$user->email}) asks for {$plan->name}{$code}.", null, 'heroicon-o-arrow-trending-up'));

        return $request;
    }

    /** Approves: the workspace goes on [$planKey], until [$until] or not, with an optional [$message]; the person is told. */
    public function approve(UpgradeRequest $request, User $admin, string $planKey, ?Carbon $until, ?string $message = null): void
    {
        $message = filled($message) ? mb_substr(trim((string) $message), 0, 1000) : null;
        $plan = Plan::query()->where('key', $planKey)->firstOrFail();
        DB::transaction(function () use ($request, $admin, $plan, $until, $message): void {
            Workspace::query()->whereKey($request->workspace_id)->update(['plan_id' => $plan->id, 'plan_expires_at' => $until]);
            $request->forceFill([
                'status' => UpgradeRequest::APPROVED, 'approved_plan' => $plan->key, 'approved_until' => $until,
                'decided_by' => $admin->id, 'decided_at' => now(), 'reply' => $message,
            ])->save();
        });
        app(Entitlements::class)->forget();
        $this->audit->record('upgrade_approved', $admin->id, $request, "Upgrade to {$plan->name} approved for ".$request->user->email);
        $this->tell($request, true, $plan->name, $until, $message);
    }

    /** Declines with [$reply]; the person is told. */
    public function decline(UpgradeRequest $request, User $admin, string $reply): void
    {
        $request->forceFill(['status' => UpgradeRequest::DECLINED, 'reply' => mb_substr(trim($reply), 0, 1000), 'decided_by' => $admin->id, 'decided_at' => now()])->save();
        // A declined request gives its promo code back to the person (since v1.6.2): nothing was bought with it.
        PromoCodeUse::query()->where('upgrade_request_id', $request->id)->delete();
        $this->audit->record('upgrade_declined', $admin->id, $request, 'Upgrade request declined for '.$request->user->email);
        $this->tell($request, false, $this->planName($request->plan), null, $request->reply);
    }

    private function tell(UpgradeRequest $request, bool $approved, string $planName, ?Carbon $until, ?string $reply): void
    {
        $user = $request->user;
        $when = $until?->copy()->setTimezone($this->settings->displayTimezone())->format('M j, Y');
        Mail::to($user->email)->queue(new UpgradeDecidedMail($approved, $planName, $when, $reply, url('/app')));
        $approved
            ? app(ActivityFeed::class)->post($user, 'upgrade_approved', "You are on {$planName} now", $reply ?? ($when !== null ? "Until {$when}. Thank you!" : 'Thank you! Everything in it is on.'), null, 'heroicon-o-check-badge')
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
            // Since v1.6.2: the promo code, its percent and the price after it; nothing to pay at 100 %.
            'promo_code' => $request->promo_code,
            'percent' => $request->percent,
            'amount_due' => $request->amount_due ?? (string) (Plan::query()->where('key', $request->plan)->first()?->displayPrice() ?? ''),
            'payment_instructions' => $request->percent !== null && $request->percent >= 100 ? null : $this->paymentInstructions(),
            'created_at' => $request->created_at->toAtomString(),
        ];
        if ($created) {
            // A 100 % promo code approves the request at once: the answer says so, with the plan and until when.
            return $request->status === UpgradeRequest::APPROVED
                ? [...$out, 'approved_plan' => $request->approved_plan, 'approved_until' => $request->approved_until?->toAtomString()] : $out;
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
