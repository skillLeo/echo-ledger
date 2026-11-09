<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Exceptions\ApiError;
use App\Filament\Auth\EditProfile;
use App\Mail\UpgradeDecidedMail;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\PromoCodeUse;
use App\Models\UpgradeRequest;
use App\Models\UpgradeRequestProof;
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
 *
 * Since v1.7 (docs/API.md "Payment receipts") each request has a reference code for the transfer and the amount due in
 * rupees; the customer sends receipts and the transaction ID (PaymentProofs), staff are told once it is in review,
 * and approving or declining runs under the request's row lock (`409 request_decided` when it was decided meanwhile).
 */
final class UpgradeRequests
{
    public const DEFAULT_INSTRUCTIONS = 'We will email you how to pay. Once the payment is confirmed, we switch your plan on.';

    public function __construct(private readonly SettingsRepository $settings, private readonly AuditLog $audit) {}

    /**
     * What v1.6 apps show after asking (`payment_instructions`): since v1.7 written from the payment details, with the
     * request's reference, else the admin's free text (Admin → Plans → Payment instructions).
     */
    public function paymentInstructions(?UpgradeRequest $request = null): string
    {
        return app(PaymentDetails::class)->instructions($request?->reference_code);
    }

    /** Where a customer's emails about a request send them: Account → Plan & usage. */
    public static function accountUrl(): string
    {
        try {
            return EditProfile::settingsUrl('plan');
        } catch (\Throwable) {
            return url('/app/settings/plan');
        }
    }

    /** A notice in the bell of every member of staff who answers upgrade requests. */
    public function tellStaff(string $title, string $body): void
    {
        User::query()->where(fn ($q) => $q->where('is_super_admin', true)->orWhereNotNull('staff_role_id'))->get()
            ->filter(fn (User $staff): bool => StaffAccess::can('upgrades.manage', $staff))
            ->each(fn (User $staff) => app(ActivityFeed::class)->post($staff, 'upgrade_requested', $title, $body, null, 'heroicon-o-arrow-trending-up'));
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
            // Since v1.7: whole rupees to pay, after the code.
            $pkr = $plan->pricePkr();
            $duePkr = $pkr === null ? null : ($promo instanceof PromoCode ? (int) round($pkr * (100 - min(100, $promo->percent)) / 100) : $pkr);

            $request = UpgradeRequest::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'plan' => $plan->key,
                'message' => filled($message) ? mb_substr(trim((string) $message), 0, 1000) : null,
                'promo_code' => $promo?->code,
                'percent' => $promo?->percent,
                // Since v1.7 written from the rupee price when the plan has one, so the text and the number agree.
                'amount_due' => match (true) {
                    $free => 'Free',
                    $duePkr !== null => Plan::rupees($duePkr).' a month',
                    $promo instanceof PromoCode => PromoCodes::discounted($plan, $promo->percent),
                    default => $plan->displayPrice(),
                },
                'amount_due_pkr' => $duePkr,
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
        $this->audit->record('upgrade_requested', $user->id, $request, "Asked for the {$plan->name} plan ({$request->reference_code})");
        // Since v1.7 staff are told once a receipt or transaction ID arrives (PaymentProofs::markPaid), not now; the
        // request is listed on Admin panel → Upgrade requests from the start.

        return $request;
    }

    /** Approves: the workspace goes on [$planKey], until [$until] or not, with an optional [$message]; the person is told. */
    public function approve(UpgradeRequest $request, User $admin, string $planKey, ?Carbon $until, ?string $message = null): void
    {
        $message = filled($message) ? mb_substr(trim((string) $message), 0, 1000) : null;
        $plan = Plan::query()->where('key', $planKey)->firstOrFail();
        DB::transaction(function () use ($request, $admin, $plan, $until, $message): void {
            self::assertOpen(self::lock($request));
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
        DB::transaction(function () use ($request, $admin, $reply): void {
            self::assertOpen(self::lock($request));
            $request->forceFill(['status' => UpgradeRequest::DECLINED, 'reply' => mb_substr(trim($reply), 0, 1000), 'decided_by' => $admin->id, 'decided_at' => now()])->save();
            // A declined request gives its promo code back to the person (since v1.6.2): nothing was bought with it.
            PromoCodeUse::query()->where('upgrade_request_id', $request->id)->delete();
        });
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

    /** The request's row, held until the transaction ends. */
    private static function lock(UpgradeRequest $request): UpgradeRequest
    {
        return UpgradeRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
    }

    /** `409 request_decided` once approved, declined or cancelled (two members of staff at once, or a cancel). */
    private static function assertOpen(UpgradeRequest $request): void
    {
        if ($request->isDecided()) {
            $who = $request->decided_by !== null ? User::query()->whereKey($request->decided_by)->value('name') : null;
            throw new ApiError(409, 'request_decided', match ($request->stage()) {
                UpgradeRequest::STAGE_APPROVED => 'This request was approved already'.($who !== null ? " by {$who}." : '.'),
                UpgradeRequest::STAGE_CANCELLED => 'The customer cancelled this request.',
                default => 'This request was declined already'.($who !== null ? " by {$who}." : '.'),
            });
        }
    }

    private function planName(string $key): string
    {
        return (string) (Plan::query()->where('key', $key)->value('name') ?? $key);
    }

    /**
     * The request as the API answers it: since its creation with the payment instructions, once decided with the
     * reply and when. Since v1.7 also the reference code, the amount in rupees, `payment` (stage, the account to pay
     * into, the receipts) and `timeline`.
     *
     * @return array<string, mixed>
     */
    public function payload(UpgradeRequest $request, bool $created = false): array
    {
        $out = $this->basePayload($request);
        $receipts = [
            'reference_code' => $request->reference_code,
            'amount_due_pkr' => $request->amount_due_pkr,
            'currency' => 'PKR',
            'payment' => $this->paymentPayload($request),
            'timeline' => self::timeline($request),
        ];
        if ($created) {
            // A 100 % promo code approves the request at once: the answer says so, with the plan and until when.
            return [...$out, ...($request->status === UpgradeRequest::APPROVED
                ? ['approved_plan' => $request->approved_plan, 'approved_until' => $request->approved_until?->toAtomString()] : []), ...$receipts];
        }

        return [...$out, 'decided_at' => $request->decided_at?->toAtomString(), 'reply' => $request->reply,
            'approved_plan' => $request->approved_plan, 'approved_until' => $request->approved_until?->toAtomString(), ...$receipts];
    }

    /** @return array<string, mixed> the v1.6 fields */
    private function basePayload(UpgradeRequest $request): array
    {
        return [
            'id' => $request->id,
            'plan' => $request->plan,
            'status' => $request->status,
            'message' => $request->message,
            // Since v1.6.2: the promo code, its percent and the price after it; nothing to pay at 100 %.
            'promo_code' => $request->promo_code,
            'percent' => $request->percent,
            'amount_due' => $request->amount_due ?? (string) (Plan::query()->where('key', $request->plan)->first()?->displayPrice() ?? ''),
            'payment_instructions' => $request->percent !== null && $request->percent >= 100 ? null : $this->paymentInstructions($request),
            'created_at' => $request->created_at->toAtomString(),
        ];
    }

    /** @return array<string, mixed> `payment`: the stage, where to pay, what was sent, and what files are taken */
    private function paymentPayload(UpgradeRequest $request): array
    {
        $details = app(PaymentDetails::class);
        $stage = $request->stage();

        return [
            'stage' => $stage,
            'accounts' => $details->accounts(),
            'note' => $details->note($request->reference_code),
            'review_hours' => PaymentDetails::REVIEW_HOURS,
            'paid_at' => $request->paid_at?->toAtomString(),
            'reference' => $request->payment_reference,
            'payer_name' => $request->payer_name,
            'proof_request' => $stage === UpgradeRequest::STAGE_NEEDS_RECEIPT && $request->proof_requested_at !== null
                ? ['note' => $request->proof_request_note, 'at' => $request->proof_requested_at->toAtomString()] : null,
            'proofs' => $request->proofs()->whereNull('erased_at')->get()->map(fn (UpgradeRequestProof $p): array => self::proofPayload($request, $p))->values()->all(),
            'max_proofs' => PaymentProofs::MAX_UNSENT,
            'max_bytes' => PaymentProofs::MAX_BYTES,
            'accepts' => PaymentProofs::ACCEPTS,
        ];
    }

    /** @return array<string, mixed> a receipt as the API shows it; its links need the device token */
    public static function proofPayload(UpgradeRequest $request, UpgradeRequestProof $proof): array
    {
        $url = fn (string $variant): string => route('api.upgrade-requests.proofs.show', ['id' => $request->id, 'proof' => $proof->id, 'variant' => $variant]);

        return [
            'id' => $proof->id,
            'kind' => $proof->kind,
            'mime' => $proof->mime,
            'name' => $proof->name,
            'bytes' => $proof->bytes,
            'width' => $proof->width,
            'height' => $proof->height,
            'created_at' => $proof->created_at->toAtomString(),
            'locked' => $proof->isLocked(),
            'thumb_url' => $proof->isPdf() ? null : $url('thumb'),
            'file_url' => $url('full'),
        ];
    }

    /** @return list<array{step: string, at: string}> what happened, oldest first */
    public static function timeline(UpgradeRequest $request): array
    {
        $steps = [['step' => 'requested', 'at' => $request->created_at]];
        if ($request->paid_at !== null) {
            $steps[] = ['step' => 'receipt_sent', 'at' => $request->paid_at];
        }
        if ($request->proof_requested_at !== null) {
            $steps[] = ['step' => 'receipt_requested', 'at' => $request->proof_requested_at];
        }
        if ($request->cancelled_at !== null) {
            $steps[] = ['step' => 'cancelled', 'at' => $request->cancelled_at];
        } elseif ($request->status !== UpgradeRequest::PENDING && $request->decided_at !== null) {
            $steps[] = ['step' => 'decided', 'at' => $request->decided_at];
        }
        usort($steps, fn (array $a, array $b): int => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());

        return array_map(fn (array $s): array => ['step' => $s['step'], 'at' => $s['at']->copy()->utc()->toIso8601ZuluString()], $steps);
    }

    /** @return array<string, mixed>|null the newest request as the entitlements show it: since v1.7 the whole request */
    public function latestPayload(User $user): ?array
    {
        $request = $this->latestFor($user);

        return $request === null ? null : $this->payload($request);
    }
}
