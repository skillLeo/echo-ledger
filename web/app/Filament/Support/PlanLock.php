<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Exceptions\ApiError;
use App\Filament\Support\Notice as Notification;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Models\User;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\Entitlements\PromoCodes;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\Workspaces\CurrentWorkspace;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ComponentAttributeBag;

/**
 * Plan-locked features in the console (since v1.6, P1.4): one badge style ("PRO ✦" in the plan's colour with a small
 * lock, the lowest plan that has the feature) and one upgrade sheet (what the feature does, the plans side by side,
 * "Request upgrade" with an optional message, then the admin's payment instructions and "We'll activate it once
 * confirmed").
 */
final class PlanLock
{
    public static function person(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }

    public static function allows(string $feature): bool
    {
        $user = self::person();

        return $user !== null && app(Entitlements::class)->allows((int) CurrentWorkspace::id(), $user->id, $feature);
    }

    /** The badge of the lowest plan that has [$feature], or null when the person has it (or no plan on offer does). */
    public static function badge(string $feature): ?HtmlString
    {
        return self::allows($feature) ? null : self::lowestBadge($feature);
    }

    /** The badge of the lowest plan that has [$feature], whoever has it (a recording's owner's plan decides there). */
    public static function lowestBadge(string $feature): ?HtmlString
    {
        $plan = app(Entitlements::class)->lowestPlan($feature);

        return $plan === null ? null : self::badgeFor($plan);
    }

    public static function badgeFor(Plan $plan, bool $locked = true): HtmlString
    {
        return new HtmlString(view('components.plan-badge', ['badge' => $plan->badgeText(), 'color' => $plan->colorOrDefault(), 'locked' => $locked, 'attributes' => new ComponentAttributeBag])->render());
    }

    /** "Show as… PRO ✦": the action's label followed by the badge of the plan that has the feature. */
    public static function label(string $label, string $feature): HtmlString|string
    {
        $badge = self::badge($feature);

        return $badge === null ? $label : new HtmlString(e($label).' '.$badge->toHtml());
    }

    /**
     * The upgrade sheet as an action; [$feature] null shows the plans without one feature in front.
     */
    public static function sheet(string $name, ?string $feature = null, ?string $label = null): Action
    {
        return self::build($name, fn (): ?string => $feature, $label ?? ($feature !== null ? Features::name($feature) : 'Compare plans'), $feature !== null);
    }

    /**
     * The upgrade sheet for whichever feature the click names (since v1.6.2, "All features"):
     * `mountAction('upgradeFeature', { feature: 'views' })`; no feature (or one not in the registry) shows the plans.
     */
    public static function sheetFor(string $name): Action
    {
        return self::build($name, fn (array $arguments): ?string => self::argumentFeature($arguments), 'Compare plans', false);
    }

    /** @param  array<string, mixed>  $arguments */
    private static function argumentFeature(array $arguments): ?string
    {
        $feature = $arguments['feature'] ?? null;

        return is_string($feature) && isset(Features::ALL[$feature]) ? $feature : null;
    }

    /** @param  Closure(array<string, mixed>): ?string  $feature */
    private static function build(string $name, Closure $feature, string $label, bool $badged): Action
    {
        $of = fn (array $arguments): ?string => $feature($arguments);

        return Action::make($name)
            ->label(fn (array $arguments): HtmlString|string => $badged && ($key = $of($arguments)) !== null ? self::label($label, $key) : $label)
            ->icon($badged ? Heroicon::OutlinedLockClosed : Heroicon::OutlinedSparkles)
            ->color('gray')
            ->modalHeading(fn (array $arguments): string => ($key = $of($arguments)) !== null ? Features::name($key).' is in '.(app(Entitlements::class)->lowestPlan($key)->name ?? 'a bigger plan') : 'Plans')
            ->modalContent(fn (array $arguments) => view('filament.upgrade-sheet', self::sheetData($of($arguments))))
            ->modalWidth('2xl')
            ->modalSubmitActionLabel('Request upgrade')
            ->modalSubmitAction(fn ($action) => self::pending() !== null ? false : $action)
            ->schema(fn (array $arguments): array => self::pending() !== null ? [] : [
                Select::make('plan')->label('Plan')->required()->selectablePlaceholder(false)
                    ->options(fn (): array => collect(app(Entitlements::class)->offered())
                        ->reject(fn (Plan $p): bool => $p->is(app(Entitlements::class)->plan((int) CurrentWorkspace::id())))
                        ->mapWithKeys(fn (Plan $p): array => [$p->key => $p->name.(filled($p->price_label) ? ' · '.$p->price_label : '')])->all())
                    ->default(fn (): ?string => ($key = $of($arguments)) !== null ? app(Entitlements::class)->lowestPlan($key)?->key : null),
                // Since v1.6.2: a promo code, checked with Apply (the new price, the plan it is for, or nothing to pay).
                TextInput::make('promo_code')->label('Promo code (optional)')->maxLength(24)->autocomplete('off')->placeholder('SAVE50')
                    ->suffixAction(Action::make('applyPromo')->label('Apply')->icon(Heroicon::OutlinedTicket)
                        ->action(fn (Get $get, Set $set) => self::applyPromo($get, $set))),
                Hidden::make('promo_line'),
                Text::make(fn (Get $get): string => (string) $get('promo_line'))->visible(fn (Get $get): bool => filled($get('promo_line'))),
                Textarea::make('message')->label('Message (optional)')->rows(2)->maxLength(1000)->placeholder('Anything we should know'),
            ])
            ->action(function (array $data): void {
                $user = self::person();
                if ($user === null) {
                    return;
                }
                $requests = app(UpgradeRequests::class);
                try {
                    $request = $requests->create($user, (int) CurrentWorkspace::id(), (string) ($data['plan'] ?? ''), $data['message'] ?? null, $data['promo_code'] ?? null);
                } catch (ApiError|ValidationException $e) {
                    Notification::make()->title($e->getMessage())->url(NoticeTargets::forError($e))->danger()->send();

                    return;
                }
                if ($request->status === UpgradeRequest::APPROVED) {
                    $name = (string) (Plan::query()->where('key', $request->plan)->value('name') ?? $request->plan);
                    Notification::make()->title(e("You are on {$name} now"))
                        ->body($request->approved_until !== null ? e('Until '.$request->approved_until->format('M j, Y').'. Nothing to pay.') : null)->success()->send();

                    return;
                }
                Notification::make()->title('Request sent. We\'ll activate it once confirmed.')
                    ->body(e(($request->promo_code !== null ? "To pay: {$request->amount_due} with {$request->promo_code}.\n" : '').$requests->paymentInstructions()))->success()->persistent()->send();
            });
    }

    /**
     * Apply in the upgrade sheet (since v1.6.2): the price after the code, or why it cannot be used. A code for another
     * plan chooses that plan (the line says so); at 100 % the plan switches on as soon as the request is sent.
     */
    private static function applyPromo(Get $get, Set $set): void
    {
        $user = self::person();
        $code = trim((string) $get('promo_code'));
        if ($user === null || $code === '') {
            $set('promo_line', $code === '' ? 'Type the code first.' : null);

            return;
        }
        $codes = app(PromoCodes::class);
        $line = function (array $r, string $before = ''): string {
            $months = $r['months'] === 1 ? '1 month' : $r['months'].' months';

            return $before.($r['free']
                ? "{$r['percent']} % off: {$r['plan_name']} is free for {$months}. It switches on as soon as you press Request upgrade."
                : "{$r['percent']} % off: {$r['final_price']} instead of {$r['price']}, for {$months}.");
        };
        try {
            $set('promo_line', $line($codes->checkThrottled($user, $code, (string) $get('plan'), request()->ip())));
        } catch (ApiError $e) {
            $other = $e->apiCode() === 'promo_other_plan' ? ($e->extra()['plan'] ?? null) : null;
            if (! is_string($other)) {
                $set('promo_line', $e->getMessage());

                return;
            }
            $set('plan', $other);
            try {
                $set('promo_line', $line($codes->check($user, $code, $other), $e->getMessage().' We chose it for you. '));
            } catch (ApiError $again) {
                $set('promo_line', $again->getMessage());
            }
        }
    }

    /**
     * Every feature of the registry in its order (since v1.6.2, Account → Plan & usage → All features): its name and
     * line, whether the person's plan has it, and otherwise the badge of the lowest plan that has it.
     *
     * @return list<array{key: string, name: string, description: string, has: bool, badge: HtmlString|null}>
     */
    public static function features(): array
    {
        $user = self::person();
        $has = $user === null ? [] : app(Entitlements::class)->features((int) CurrentWorkspace::id(), $user->id);
        $rows = [];
        foreach (Features::ALL as $key => $feature) {
            $on = $has[$key] ?? false;
            $plan = $on ? null : app(Entitlements::class)->lowestPlan($key);
            $rows[] = ['key' => $key, 'name' => $feature['name'], 'description' => $feature['description'], 'has' => $on, 'badge' => $plan === null ? null : self::badgeFor($plan)];
        }

        return $rows;
    }

    /** @return array{name: string, plan_name: string}|null the person's request still waiting */
    private static function pending(): ?array
    {
        $user = self::person();
        $pending = $user === null ? null : app(UpgradeRequests::class)->pendingFor($user);

        return $pending === null ? null : ['name' => $pending->plan, 'plan_name' => (string) (Plan::query()->where('key', $pending->plan)->value('name') ?? $pending->plan)];
    }

    /** @return array<string, mixed> */
    private static function sheetData(?string $feature): array
    {
        $entitlements = app(Entitlements::class);
        $plans = array_map(fn (Plan $p): array => Entitlements::planCard($p), $entitlements->offered());
        $rows = [];
        foreach (Features::paid() as $key) {
            $rows[] = ['label' => Features::name($key), 'highlight' => $key === $feature,
                'values' => array_map(fn (array $p): string => in_array($key, $p['features'], true) ? '✓' : '—', $plans)];
        }
        $format = fn (string $key, int|float|null $v): string => match (true) {
            $v === null => 'No limit',
            $key === 'storage_bytes' => Number::fileSize((int) $v),
            $key === 'monthly_minutes' => $v === 0 ? '—' : round($v / 60, 1).' hours',
            $key === 'ai_usd_month' => '$'.number_format((float) $v, 2),
            default => (string) $v,
        };
        foreach (['monthly_minutes' => 'Transcription a month', 'devices' => 'Devices', 'storage_bytes' => 'Storage', 'retention_days' => 'Keeps recordings (days)', 'seats' => 'People'] as $key => $label) {
            $rows[] = ['label' => $label, 'highlight' => false, 'values' => array_map(fn (array $p): string => $format($key, $p['limits'][$key] ?? null), $plans)];
        }

        return [
            'feature' => $feature !== null ? Features::ALL[$feature] ?? null : null,
            'plans' => $plans,
            'rows' => $rows,
            'current' => $entitlements->plan((int) CurrentWorkspace::id())->key,
            'pending' => self::pending(),
            'instructions' => app(UpgradeRequests::class)->paymentInstructions(),
        ];
    }
}
