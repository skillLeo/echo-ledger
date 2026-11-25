<?php

declare(strict_types=1);

namespace App\Filament\Auth\Settings;

use App\Exceptions\ApiError;
use App\Filament\Pages\Usage;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\UpgradeFlow;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\Entitlements\PaymentProofs;
use App\Services\Entitlements\PromoCodes;
use App\Support\Brand;
use App\Support\Formats;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;

/**
 * Settings › Plan & usage: the current plan, the request while one is open or just decided, this month's use, what
 * the plan includes (and every feature of every plan), the plans above it, and Payments with the promo code.
 */
trait ManagesPlanPage
{
    /** @return array<string, mixed> */
    public function planData(): array
    {
        $context = $this->context();
        $plan = $context->plan();
        $workspace = $context->workspace();
        $request = $context->latestRequest();
        $open = $context->openRequest();
        $showCard = $request !== null && ($open !== null || UpgradeFlow::showsDecided($request, $context));
        $higher = $context->higherPlans();
        $buyer = $context->flag('buyer');
        $free = $plan->key === Plan::FREE || (float) $plan->price_usd <= 0 && ! $plan->allows('platform_transcription');
        $expires = $workspace?->plan_expires_at;
        $offered = app(Entitlements::class)->offered();

        $cards = [];
        $below = $plan;
        foreach ($higher as $p) {
            $cards[] = ['key' => $p->key, 'name' => $p->name, 'price' => UpgradeFlow::priceText($p), 'everything' => $below->key === Plan::FREE || ! $below->allows('platform_transcription') ? null : $below->name,
                'lines' => UpgradeFlow::highlights($p, $below), 'url' => UpgradeFlow::chooseUrl($p->key)];
            $below = $p;
        }
        $matrixPlans = array_values(array_filter($offered, fn (Plan $p): bool => $p->key !== 'personal'));
        $features = app(Entitlements::class)->features($context->workspaceId(), $context->user->id);
        $history = UpgradeRequest::query()->where('user_id', $context->user->id)->orderByDesc('created_at')->limit(5)->get();
        $trial = app(Entitlements::class)->trialMinutesLeft($context->workspaceId(), $context->user->id);
        $owner = $context->flag('member') && $workspace !== null ? User::query()->find(Entitlements::ownerOf($workspace->id)) : null;

        return [
            'plan' => $plan,
            'free' => $free,
            'priceLine' => $free ? __('Transcripts use your own free Groq key.') : UpgradeFlow::priceText($plan),
            'untilLine' => $free ? null : ($expires !== null ? __('Paid until :date', ['date' => Formats::shortDate($expires, year: true)]) : __('No end date')),
            'footer' => ! $free && $expires !== null ? __('Your plan stops on :date. There is nothing to cancel.', ['date' => Formats::longDate($expires)]) : null,
            'trial' => $trial > 0 ? __(':minutes trial minutes left', ['minutes' => number_format($trial)]) : null,
            'managed' => $owner !== null ? __(':workspace is on :plan. Managed by :owner.', ['workspace' => $workspace->name, 'plan' => $plan->name, 'owner' => $owner->name]) : null,
            'buyer' => $buyer && ! $context->flag('member'),
            'open' => $open !== null,
            'next' => $higher[0] ?? null,
            'top' => $higher === [],
            'upgradeUrl' => UpgradeFlow::chooseUrl($higher[0]->key ?? null),
            'request' => $showCard ? UpgradeFlow::card($request) : null,
            'requestUrl' => $request !== null ? UpgradeFlow::statusUrl($request) : null,
            'meters' => UpgradeFlow::meters($context),
            'resetsFooter' => ($context->plan()->limit('monthly_minutes') ?? 1) > 0 ? __("Minutes reset on the 1st of each month. Unused minutes don't carry over.") : null,
            'included' => $this->included($plan),
            'usageUrl' => $buyer && Usage::canAccess() ? Usage::getUrl(panel: 'app') : null,
            'cards' => $context->flag('member') ? [] : $cards,
            'matrixPlans' => array_map(fn (Plan $p): array => ['key' => $p->key, 'name' => $p->name, 'yours' => $p->is($plan),
                'features' => array_values(array_filter(Features::keys(), fn (string $f): bool => $p->allows($f))), 'plan' => $p], $matrixPlans),
            'features' => array_map(fn (string $key): array => ['key' => $key, 'name' => Features::all()[$key]['name'], 'has' => (bool) ($features[$key] ?? false)], Features::keys()),
            'history' => $history->map(fn (UpgradeRequest $r): array => [
                'id' => $r->id, 'title' => __(':plan, 1 month', ['plan' => UpgradeFlow::planName($r->plan)]), 'value' => UpgradeFlow::amount($r),
                'line' => match (UpgradeFlow::stage($r)) {
                    'approved' => __('Approved on :date', ['date' => Formats::shortDate($r->decided_at ?? $r->created_at, year: true)]),
                    'declined' => __('Not approved on :date', ['date' => Formats::shortDate($r->decided_at ?? $r->created_at, year: true)]),
                    'cancelled' => __('Cancelled on :date', ['date' => Formats::shortDate($r->decided_at ?? $r->created_at, year: true)]),
                    'in_review' => __('In review since :date', ['date' => Formats::shortDate(UpgradeFlow::payment($r)['paid_at'] ?? $r->created_at)]),
                    'needs_receipt' => __('Needs a new receipt'),
                    default => __('Waiting for payment since :date', ['date' => Formats::shortDate($r->created_at)]),
                },
                'url' => UpgradeFlow::statusUrl($r),
            ])->all(),
        ];
    }

    /** @return list<array{title: string, value: string}> what the plan includes, as facts */
    private function included(Plan $plan): array
    {
        $retention = $plan->limit('retention_days');
        $file = $plan->limit('max_file_minutes');
        $uploads = $plan->limit('uploads_per_day');
        $seats = $plan->limit('seats');

        return [
            ['title' => __('settings_context.recordings_kept'), 'value' => $retention === null ? __('Until you delete them') : Formats::days((int) $retention)],
            ['title' => __('Longest recording'), 'value' => $file === null ? __('No limit') : Formats::fileLength((int) $file)],
            ['title' => __('Uploads a day'), 'value' => $uploads === null ? __('No limit') : __('Up to :count', ['count' => number_format((int) $uploads)])],
            ['title' => __('Team members'), 'value' => $seats === null ? __('No limit') : ((int) $seats <= 1 ? __('Just you') : __('Up to :count people', ['count' => number_format((int) $seats)]))],
        ];
    }

    /** Payments › Promo code: one field; a good code opens the upgrade on its plan with the code applied. */
    public function promoCodeAction(): Action
    {
        return Action::make('promoCode')->label(__('Promo code'))
            ->modalHeading(__('Promo code'))->modalWidth('sm')
            ->schema([TextInput::make('code')->label(__('Code'))->required()->minLength(6)->maxLength(24)->autocomplete('off')
                ->extraInputAttributes(['style' => 'text-transform: uppercase', 'autocapitalize' => 'characters', 'spellcheck' => 'false'])])
            ->modalSubmitActionLabel(__('Apply'))
            ->action(function (array $data, Action $action): void {
                $code = strtoupper(trim((string) ($data['code'] ?? '')));
                try {
                    $answer = app(PromoCodes::class)->checkThrottled($this->account(), $code, null, request()->ip());
                } catch (ApiError $e) {
                    throw ValidationException::withMessages(['mountedActions.0.data.code' => self::promoError($e)]);
                }
                $this->redirect(UpgradeFlow::chooseUrl($answer['plan'], promo: $answer['code']));
            });
    }

    public static function promoError(ApiError $e): string
    {
        return match ($e->apiCode()) {
            'promo_other_plan' => $e->getMessage(),
            'rate_limited' => __('Too many tries. Please try again in a minute.'),
            default => __("We don't know this code. Check the spelling."),
        };
    }

    /** The request card's Dismiss (an approved or declined request). */
    public function dismissRequest(): void
    {
        $request = $this->context()->latestRequest();
        if ($request === null) {
            return;
        }
        $user = $this->account();
        $preferences = is_array($user->console_preferences) ? $user->console_preferences : [];
        $dismissed = is_array($preferences['dismissed_requests'] ?? null) ? $preferences['dismissed_requests'] : [];
        $preferences['dismissed_requests'] = array_values(array_unique([...array_slice($dismissed, -19), $request->id]));
        $user->forceFill(['console_preferences' => $preferences])->save();
        SettingsContext::forget();
    }

    /** Cancel request, from the request card while it waits for the payment. */
    public function cancelRequestAction(): Action
    {
        return Action::make('cancelRequest')->label(__('Cancel request'))->color('danger')
            ->requiresConfirmation()->modalIcon(null)
            ->modalHeading(__('Cancel this request?'))
            ->modalDescription(__("If you've already paid, add your receipt instead. Cancelling doesn't refund a payment."))
            ->modalCancelActionLabel(__('Keep request'))->modalSubmitActionLabel(__('Cancel request'))
            ->action(function (): void {
                $request = $this->context()->openRequest();
                if ($request === null) {
                    return;
                }
                try {
                    app(PaymentProofs::class)->cancel($request, $this->account());
                } catch (ApiError $e) {
                    Notification::make()->title($e->apiCode() === 'request_decided' ? __('This request has already been decided.') : __("Couldn't reach :brand. Try again.", ['brand' => Brand::name()]))->danger()->send();

                    throw new Halt;
                }
                SettingsContext::forget();
                Notification::make()->title(__('Request cancelled'))->success()->send();
            });
    }
}
