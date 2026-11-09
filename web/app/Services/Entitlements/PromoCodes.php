<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Exceptions\ApiError;
use App\Models\Plan;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Promo codes (since v1.6.2, docs/API.md "Promo codes"): whether a code may be used by this person for a plan, and the
 * price after its discount. One answer for a code that is unknown, switched off, ended, used up or already used by this
 * person (`422 invalid_promo_code`), so nobody learns which codes exist; a valid code for another plan says which plan
 * (`422 promo_other_plan`). Never says how many uses are left or who used it.
 */
final class PromoCodes
{
    /** Checks a person may make in the console (the API's route has the same limits). */
    public const PER_MINUTE = 10;

    public const PER_HOUR_PER_IP = 30;

    public const INVALID = 'This code is not valid.';

    /**
     * The check as `POST /api/promo-codes/check` answers it.
     *
     * @return array{code: string, plan: string, plan_name: string, percent: int, price: string, final_price: string, free: bool, months: int}
     */
    public function check(User $user, string $code, ?string $planKey = null): array
    {
        $promo = $this->usable($user, $code);
        $plan = $this->planOf($promo);
        if ($planKey !== null && $planKey !== '' && $planKey !== $plan->key) {
            throw self::otherPlan($plan);
        }

        return self::answer($promo, $plan);
    }

    /**
     * The console's check (the upgrade sheet's Apply), with the same limits as the API's route: 10 a minute per
     * person, 30 an hour per network.
     *
     * @return array{code: string, plan: string, plan_name: string, percent: int, price: string, final_price: string, free: bool, months: int}
     */
    public function checkThrottled(User $user, string $code, ?string $planKey, ?string $ip): array
    {
        $keys = [['promo-check-console:user:'.$user->id, self::PER_MINUTE, 60], ['promo-check-console:ip:'.($ip ?? 'none'), self::PER_HOUR_PER_IP, 3600]];
        foreach ($keys as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                throw new ApiError(429, 'rate_limited', 'Too many tries. Wait a minute and try again.');
            }
        }
        foreach ($keys as [$key, , $decay]) {
            RateLimiter::hit($key, $decay);
        }

        return $this->check($user, $code, $planKey);
    }

    /** The code, if this person may use it now; else `422 invalid_promo_code`. */
    public function usable(User $user, string $code, bool $lock = false): PromoCode
    {
        $code = PromoCode::normalize($code);
        $promo = preg_match(PromoCode::PATTERN, $code) === 1
            ? PromoCode::query()->where('code', $code)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
        $plan = $promo instanceof PromoCode ? Plan::query()->where('key', $promo->plan)->where('on_offer', true)->whereNull('archived_at')->first() : null;
        if (! $promo instanceof PromoCode || ! $plan instanceof Plan || ! $promo->active || $promo->hasEnded()
            || ($promo->max_uses !== null && $promo->uses()->count() >= $promo->max_uses)
            || ($promo->one_per_person && $promo->uses()->where('user_id', $user->id)->exists())) {
            throw new ApiError(422, 'invalid_promo_code', self::INVALID);
        }

        return $promo;
    }

    public function planOf(PromoCode $promo): Plan
    {
        return Plan::query()->where('key', $promo->plan)->firstOrFail();
    }

    public static function otherPlan(Plan $plan): ApiError
    {
        return new ApiError(422, 'promo_other_plan', "This code is for {$plan->name}.", ['plan' => $plan->key]);
    }

    /** @return array{code: string, plan: string, plan_name: string, percent: int, price: string, final_price: string, free: bool, months: int} */
    public static function answer(PromoCode $promo, Plan $plan): array
    {
        return [
            'code' => $promo->code,
            'plan' => $plan->key,
            'plan_name' => $plan->name,
            'percent' => $promo->percent,
            'price' => $plan->displayPrice(),
            'final_price' => self::discounted($plan, $promo->percent),
            'free' => $promo->percent >= 100,
            'months' => $promo->months,
        ];
    }

    /**
     * The plan's price after [$percent] off, written as the plan's price is ("Rs 1,500 a month"); "Free" at 100 %. A
     * plan whose price is only a text ("Ask for the price") keeps it, with the discount after it.
     */
    public static function discounted(Plan $plan, int $percent): string
    {
        if ($percent >= 100) {
            return 'Free';
        }
        // As Plan::displayPrice writes them: an amount in its currency (no decimals when whole), else US dollars with cents.
        [$amount, $symbol, $decimals] = match (true) {
            $plan->price_amount !== null && (float) $plan->price_amount > 0 => [(float) $plan->price_amount, Plan::CURRENCIES[(string) $plan->currency] ?? (string) $plan->currency,
                fmod((float) $plan->price_amount, 1.0) === 0.0 ? 0 : 2],
            (float) $plan->price_usd > 0 => [(float) $plan->price_usd, '$', 2],
            default => [null, '', 0],
        };
        if ($amount === null) {
            return $plan->displayPrice().', less '.$percent.' %';
        }
        $formatted = number_format(round($amount * (100 - $percent) / 100, $decimals), $decimals);

        return ($symbol === '$' ? '$'.$formatted : trim($symbol.' '.$formatted)).' a month';
    }
}
