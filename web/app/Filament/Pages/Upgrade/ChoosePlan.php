<?php

declare(strict_types=1);

namespace App\Filament\Pages\Upgrade;

use App\Exceptions\ApiError;
use App\Filament\Auth\EditProfile;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\UpgradeFlow;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\Entitlements\PromoCodes;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\Workspaces\ConsoleAccess;
use Filament\Pages\Page;
use Filament\Panel;
use Illuminate\Validation\ValidationException;

/**
 * Upgrade, step 1 (since v1.7): the plans above yours as radio cards (the next one chosen, or the lowest with the
 * feature that opened it), a promo code, a note for our team, and "Request {Pro}" with what you will pay.
 */
class ChoosePlan extends Page
{
    protected static ?string $slug = 'settings/plan/upgrade';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $title = 'Upgrade';

    protected string $view = 'filament.upgrade.choose';

    public string $plan = '';

    public ?string $feature = null;

    public string $promo = '';

    public bool $promoOpen = false;

    /** @var array{code: string, plan: string, plan_name: string, percent: int, free: bool, months: int}|null */
    public ?array $promoApplied = null;

    public ?string $promoError = null;

    public string $note = '';

    public ?string $error = null;

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'upgrade';
    }

    public static function canAccess(): bool
    {
        $context = SettingsContext::current();

        return ConsoleAccess::open() && $context !== null && $context->flag('buyer') && ! $context->flag('member');
    }

    public function mount(): void
    {
        $context = $this->context();
        if (($open = $context->openRequest()) !== null) {
            $this->redirect(UpgradeFlow::statusUrl($open));

            return;
        }
        $plans = $context->higherPlans();
        if ($plans === []) {
            $this->redirect(EditProfile::settingsUrl('plan'));

            return;
        }
        $feature = (string) request()->query('feature', '');
        $this->feature = isset(Features::ALL[$feature]) ? $feature : null;
        $wanted = (string) request()->query('plan', '');
        $keys = array_map(fn (Plan $p): string => $p->key, $plans);
        $withFeature = $this->feature !== null ? array_values(array_filter($plans, fn (Plan $p): bool => $p->allows((string) $this->feature)))[0] ?? null : null;
        $this->plan = in_array($wanted, $keys, true) ? $wanted : ($withFeature->key ?? $keys[0]);
        $promo = strtoupper(trim((string) request()->query('promo', '')));
        if ($promo !== '') {
            $this->promo = $promo;
            $this->promoOpen = true;
            $this->applyPromo();
        }
    }

    /** @return list<Plan> */
    public function plans(): array
    {
        return $this->context()->higherPlans();
    }

    public function selected(): ?Plan
    {
        foreach ($this->plans() as $plan) {
            if ($plan->key === $this->plan) {
                return $plan;
            }
        }

        return null;
    }

    public function choose(string $key): void
    {
        if (! in_array($key, array_map(fn (Plan $p): string => $p->key, $this->plans()), true)) {
            return;
        }
        $this->plan = $key;
        $this->error = null;
        if ($this->promoApplied !== null || $this->promoError !== null) {
            $this->applyPromo();
        }
    }

    /** The promo code, checked for the chosen plan; a code for another plan says which and is not sent. */
    public function applyPromo(): void
    {
        $this->promoError = null;
        $this->promoApplied = null;
        $code = strtoupper(trim($this->promo));
        if (mb_strlen($code) < 6 || mb_strlen($code) > 24 || preg_match('/^[A-Z0-9-]+$/', $code) !== 1) {
            $this->promoError = "We don't know this code. Check the spelling.";

            return;
        }
        try {
            $answer = app(PromoCodes::class)->checkThrottled($this->context()->user, $code, $this->plan, request()->ip());
        } catch (ApiError $e) {
            $this->promoError = EditProfile::promoError($e);

            return;
        }
        $this->promo = $answer['code'];
        $this->promoApplied = ['code' => $answer['code'], 'plan' => $answer['plan'], 'plan_name' => $answer['plan_name'], 'percent' => $answer['percent'], 'free' => $answer['free'], 'months' => $answer['months']];
    }

    public function removePromo(): void
    {
        $this->promo = '';
        $this->promoApplied = null;
        $this->promoError = null;
    }

    /** Request {Pro}: the request is made; the bank details are next (or, with a 100 % code, the plan is on). */
    public function submit(): void
    {
        $this->error = null;
        $context = $this->context();
        $plan = $this->selected();
        if ($plan === null) {
            $this->error = "This plan isn't available right now. Please choose another.";

            return;
        }
        try {
            $request = app(UpgradeRequests::class)->create($context->user, $context->workspaceId(), $plan->key,
                trim($this->note) !== '' ? mb_substr(trim($this->note), 0, 500) : null, $this->promoApplied['code'] ?? null);
        } catch (ApiError $e) {
            $pending = $e->extra()['request']['id'] ?? null;
            if ($e->apiCode() === 'request_pending' && is_string($pending) && UpgradeRequest::query()->where('user_id', $context->user->id)->whereKey($pending)->exists()) {
                $this->redirect(UpgradeFlow::statusUrl($pending));

                return;
            }
            $this->error = match ($e->apiCode()) {
                'request_pending' => $e->getMessage(),
                'not_allowed' => 'Only the owner of '.($context->workspace()->name ?? 'this workspace').' can upgrade it. Ask them to upgrade.',
                'rate_limited' => 'Too many tries today. Please try again later.',
                'promo_other_plan', 'invalid_promo_code' => EditProfile::promoError($e),
                default => "This plan isn't available right now. Please choose another.",
            };

            return;
        } catch (ValidationException) {
            $this->error = "This plan isn't available right now. Please choose another.";

            return;
        }
        SettingsContext::forget();
        app(Entitlements::class)->forget();
        $this->redirect($request->status === UpgradeRequest::APPROVED ? UpgradeFlow::statusUrl($request) : UpgradeFlow::payUrl($request));
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $context = $this->context();
        $plans = $this->plans();
        $selected = $this->selected();
        $percent = $this->promoApplied !== null && $this->promoApplied['plan'] === $this->plan ? $this->promoApplied['percent'] : 0;
        $cards = [];
        $below = $context->plan();
        foreach ($plans as $plan) {
            $cards[] = ['key' => $plan->key, 'name' => $plan->name, 'price' => UpgradeFlow::priceText($plan),
                'everything' => $below->is($context->plan()) ? null : $below->name, 'lines' => UpgradeFlow::highlights($plan, $below)];
            $below = $plan;
        }
        $months = $this->promoApplied['months'] ?? 1;

        return [
            'context' => $context,
            'current' => $context->plan(),
            'cards' => $cards,
            'selected' => $selected,
            'featureLine' => $this->feature !== null
                ? Features::name($this->feature).' is in '.(app(Entitlements::class)->lowestPlan($this->feature)->name ?? 'a bigger plan').". You're on {$context->plan()->name}."
                : "You're on {$context->plan()->name}.",
            'workspaceLine' => $context->user->workspaces()->count() > 1 ? 'Upgrading '.($context->workspace()->name ?? 'this workspace') : null,
            'price' => $selected !== null ? UpgradeFlow::price($selected) : null,
            'toPay' => $selected !== null ? UpgradeFlow::price($selected, $percent) : null,
            'percent' => $percent,
            'free' => $percent >= 100,
            'promoLine' => $this->promoApplied !== null
                ? ($this->promoApplied['plan'] === $this->plan
                    ? "{$this->promoApplied['code']}: {$this->promoApplied['percent']}% off {$this->promoApplied['plan_name']} for ".($months === 1 ? '1 month' : "{$months} months")
                    : "This code is for {$this->promoApplied['plan_name']}.")
                : null,
            'promoOk' => $this->promoApplied !== null && $this->promoApplied['plan'] === $this->plan,
            'firstMonths' => $months === 1 ? 'for the first month' : "for the first {$months} months",
            'backUrl' => EditProfile::settingsUrl('plan'),
        ];
    }

    public function getHeading(): string
    {
        return '';
    }

    private function context(): SettingsContext
    {
        $context = SettingsContext::current();
        abort_if($context === null, 403);

        return $context;
    }
}
