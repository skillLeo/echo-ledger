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
            $cards[] = ['key' => $p->key, 'name' => $p->name, 'price' => UpgradeFlow::priceText($p), 'everything' => $below->is($plan) ? null : $below->name,
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
            'priceLine' => $free ? 'Transcripts use your own free Groq key.' : UpgradeFlow::priceText($plan),
            'untilLine' => $free ? null : ($expires !== null ? 'Paid until '.Formats::shortDate($expires, year: true) : 'No end date'),
            'footer' => ! $free && $expires !== null ? 'Your plan stops on '.Formats::longDate($expires).'. There is nothing to cancel.' : null,
            'trial' => $trial > 0 ? number_format($trial).' trial minutes left' : null,
            'managed' => $owner !== null ? "{$workspace->name} is on {$plan->name}. Managed by {$owner->name}." : null,
            'buyer' => $buyer && ! $context->flag('member'),
            'open' => $open !== null,
            'next' => $higher[0] ?? null,
            'top' => $higher === [],
            'upgradeUrl' => UpgradeFlow::chooseUrl($higher[0]->key ?? null),
            'request' => $showCard ? UpgradeFlow::card($request) : null,
            'requestUrl' => $request !== null ? UpgradeFlow::statusUrl($request) : null,
            'meters' => UpgradeFlow::meters($context),
            'resetsFooter' => ($context->plan()->limit('monthly_minutes') ?? 1) > 0 ? "Minutes reset on the 1st of each month. Unused minutes don't carry over." : null,
            'included' => $this->included($plan),
            'usageUrl' => $buyer && Usage::canAccess() ? Usage::getUrl(panel: 'app') : null,
            'cards' => $context->flag('member') ? [] : $cards,
            'matrixPlans' => array_map(fn (Plan $p): array => ['key' => $p->key, 'name' => $p->name, 'yours' => $p->is($plan),
                'features' => array_values(array_filter(Features::keys(), fn (string $f): bool => $p->allows($f))), 'plan' => $p], $matrixPlans),
            'features' => array_map(fn (string $key): array => ['key' => $key, 'name' => Features::ALL[$key]['name'], 'has' => (bool) ($features[$key] ?? false)], Features::keys()),
            'history' => $history->map(fn (UpgradeRequest $r): array => [
                'id' => $r->id, 'title' => UpgradeFlow::planName($r->plan).', 1 month', 'value' => UpgradeFlow::amount($r),
                'line' => match (UpgradeFlow::stage($r)) {
                    'approved' => 'Approved on '.Formats::shortDate($r->decided_at ?? $r->created_at, year: true),
                    'declined' => 'Not approved on '.Formats::shortDate($r->decided_at ?? $r->created_at, year: true),
                    'cancelled' => 'Cancelled on '.Formats::shortDate($r->decided_at ?? $r->created_at, year: true),
                    'in_review' => 'In review since '.Formats::shortDate(UpgradeFlow::payment($r)['paid_at'] ?? $r->created_at),
                    'needs_receipt' => 'Needs a new receipt',
                    default => 'Waiting for payment since '.Formats::shortDate($r->created_at),
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
            ['title' => 'Recordings kept', 'value' => $retention === null ? 'Until you delete them' : Formats::days((int) $retention)],
            ['title' => 'Longest recording', 'value' => $file === null ? 'No limit' : Formats::fileLength((int) $file)],
            ['title' => 'Uploads a day', 'value' => $uploads === null ? 'No limit' : 'Up to '.number_format((int) $uploads)],
            ['title' => 'Team members', 'value' => $seats === null ? 'No limit' : ((int) $seats <= 1 ? 'Just you' : 'Up to '.number_format((int) $seats).' people')],
        ];
    }

    /** Payments › Promo code: one field; a good code opens the upgrade on its plan with the code applied. */
    public function promoCodeAction(): Action
    {
        return Action::make('promoCode')->label('Promo code')
            ->modalHeading('Promo code')->modalWidth('sm')
            ->schema([TextInput::make('code')->label('Code')->required()->minLength(6)->maxLength(24)->autocomplete('off')
                ->extraInputAttributes(['style' => 'text-transform: uppercase', 'autocapitalize' => 'characters', 'spellcheck' => 'false'])])
            ->modalSubmitActionLabel('Apply')
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
            'rate_limited' => 'Too many tries. Please try again in a minute.',
            default => "We don't know this code. Check the spelling.",
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
        return Action::make('cancelRequest')->label('Cancel request')->color('danger')
            ->requiresConfirmation()->modalIcon(null)
            ->modalHeading('Cancel this request?')
            ->modalDescription("If you've already paid, add your receipt instead. Cancelling doesn't refund a payment.")
            ->modalCancelActionLabel('Keep request')->modalSubmitActionLabel('Cancel request')
            ->action(function (): void {
                $request = $this->context()->openRequest();
                if ($request === null) {
                    return;
                }
                try {
                    app(PaymentProofs::class)->cancel($request, $this->account());
                } catch (ApiError $e) {
                    Notification::make()->title($e->apiCode() === 'request_decided' ? 'This request has already been decided.' : "Couldn't reach EchoLedger. Try again.")->danger()->send();

                    throw new Halt;
                }
                SettingsContext::forget();
                Notification::make()->title('Request cancelled')->success()->send();
            });
    }
}
