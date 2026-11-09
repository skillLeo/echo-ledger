<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person's request for a plan (since v1.6, P1.4, docs/API.md "Upgrade requests"): pending until the super admin
 * approves it (the plan, until a date or not) or declines it with a reply.
 *
 * @property string $id
 * @property int $user_id
 * @property int $workspace_id
 * @property string $plan
 * @property string|null $message
 * @property string|null $promo_code since v1.6.2
 * @property int|null $percent the promo code's discount
 * @property string|null $amount_due the price after the discount, as shown
 * @property string $status
 * @property string|null $reply
 * @property string|null $approved_plan
 * @property Carbon|null $approved_until
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property Carbon $created_at
 * @property string|null $reference_code since v1.7: "EL-" and 6 characters, for the bank transfer's note
 * @property int|null $amount_due_pkr whole rupees to pay, after the promo code
 * @property string|null $payment_reference the bank's transaction ID the customer gave (upper case, no spaces)
 * @property string|null $payer_name the name the money came from
 * @property Carbon|null $paid_at when the customer last said they paid (a receipt or a transaction ID sent)
 * @property Carbon|null $proof_requested_at when staff last asked for a new receipt
 * @property string|null $proof_request_note
 * @property Carbon|null $cancelled_at the customer cancelled it (status declined)
 * @property-read User $user
 * @property-read Workspace $workspace
 * @property-read Collection<int, UpgradeRequestProof> $proofs
 */
class UpgradeRequest extends Model
{
    use HasUlids;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const DECLINED = 'declined';

    /** Since v1.7, `payment.stage` (computed, never stored; docs/API.md "Payment receipts"). */
    public const STAGE_APPROVED = 'approved';

    public const STAGE_CANCELLED = 'cancelled';

    public const STAGE_DECLINED = 'declined';

    public const STAGE_NEEDS_RECEIPT = 'needs_receipt';

    public const STAGE_IN_REVIEW = 'in_review';

    public const STAGE_AWAITING_PAYMENT = 'awaiting_payment';

    /** The reference code's characters: easy to read aloud, no 0, 1, I, L, O or U. */
    public const REFERENCE_ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';

    public const REFERENCE_PATTERN = '/^EL-[2-9A-HJKMNP-TV-Z]{6}$/';

    protected $fillable = ['user_id', 'workspace_id', 'plan', 'message', 'promo_code', 'percent', 'amount_due', 'status', 'reply', 'approved_plan', 'approved_until', 'decided_by', 'decided_at',
        'reference_code', 'amount_due_pkr', 'payment_reference', 'payer_name', 'paid_at', 'proof_requested_at', 'proof_request_note', 'cancelled_at'];

    protected static function booted(): void
    {
        // Every request has its reference from the start, whichever path makes it.
        static::creating(function (self $request): void {
            if (blank($request->reference_code)) {
                $request->reference_code = self::newReferenceCode();
            }
        });
    }

    /** A reference code no request has yet: "EL-" and 6 characters of REFERENCE_ALPHABET. */
    public static function newReferenceCode(): string
    {
        do {
            $code = 'EL-';
            for ($i = 0; $i < 6; $i++) {
                $code .= self::REFERENCE_ALPHABET[random_int(0, strlen(self::REFERENCE_ALPHABET) - 1)];
            }
        } while (self::query()->where('reference_code', $code)->exists());

        return $code;
    }

    /**
     * Where the request is (since v1.7), first match wins: approved, cancelled, declined, needs_receipt (staff asked
     * for a new receipt after the last one was sent), in_review (a receipt or transaction ID was sent),
     * awaiting_payment.
     */
    public function stage(): string
    {
        return match (true) {
            $this->status === self::APPROVED => self::STAGE_APPROVED,
            $this->cancelled_at !== null => self::STAGE_CANCELLED,
            $this->status === self::DECLINED => self::STAGE_DECLINED,
            $this->proof_requested_at !== null && ($this->paid_at === null || $this->proof_requested_at->greaterThan($this->paid_at)) => self::STAGE_NEEDS_RECEIPT,
            $this->paid_at !== null => self::STAGE_IN_REVIEW,
            default => self::STAGE_AWAITING_PAYMENT,
        };
    }

    /** Approved, declined or cancelled: nothing more can be sent, removed or decided. */
    public function isDecided(): bool
    {
        return $this->status !== self::PENDING || $this->cancelled_at !== null;
    }

    /** @return HasMany<UpgradeRequestProof, $this> */
    public function proofs(): HasMany
    {
        return $this->hasMany(UpgradeRequestProof::class)->orderBy('created_at')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['approved_until' => 'datetime', 'decided_at' => 'datetime', 'percent' => 'integer', 'amount_due_pkr' => 'integer',
            'paid_at' => 'datetime', 'proof_requested_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }
}
