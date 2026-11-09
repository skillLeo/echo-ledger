<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Auth\EditProfile;
use App\Filament\Pages\Upgrade\ChoosePlan;
use App\Filament\Pages\Upgrade\PayForPlan;
use App\Filament\Pages\Upgrade\RequestStatus;
use App\Filament\Pages\Upgrade\SendReceipt;
use App\Models\Plan;
use App\Models\UpgradeRequest;
use App\Services\Entitlements\Entitlements;
use App\Services\Entitlements\Features;
use App\Services\Entitlements\PromoCodes;
use App\Services\Entitlements\UpgradeRequests;
use App\Services\SettingsRepository;
use App\Support\Formats;
use Illuminate\Support\Carbon;

/**
 * The upgrade flow of the console (since v1.7, docs/API.md "Payment receipts"): Plan & usage, then Upgrade (choose a
 * plan, a promo code, a note), Pay for {Pro} (the bank details, the amount, the reference), Send receipt (up to 3
 * screenshots or PDFs and the transaction ID) and the request's status. Its copy and each stage's card live here,
 * so Settings, the pages of the flow and the avatar menu say the same thing.
 */
final class UpgradeFlow
{
    /** The stages of a request still open. */
    public const OPEN = ['awaiting_payment', 'in_review', 'needs_receipt'];

    /** How long a decided request stays on Plan & usage. */
    public const SHOW_APPROVED_DAYS = 7;

    public const SHOW_DECLINED_DAYS = 14;

    public static function stage(UpgradeRequest $request): string
    {
        return $request->stage();
    }

    public static function isOpen(UpgradeRequest $request): bool
    {
        return in_array(self::stage($request), self::OPEN, true);
    }

    /** @return array<string, mixed> the request as the API answers it */
    public static function payload(UpgradeRequest $request): array
    {
        return app(UpgradeRequests::class)->payload($request);
    }

    /** @return array<string, mixed> the payment part of the request (bank details, receipts), or empty */
    public static function payment(UpgradeRequest $request): array
    {
        $payment = self::payload($request)['payment'] ?? [];

        return is_array($payment) ? $payment : [];
    }

    public static function planName(?string $key): string
    {
        if ($key === null || $key === '') {
            return 'a plan';
        }

        return (string) (Plan::query()->where('key', $key)->value('name') ?? ucfirst($key));
    }

    /** The amount to pay, "Rs 2,999". */
    public static function amount(UpgradeRequest $request): string
    {
        $payload = self::payload($request);
        if (is_int($payload['amount_due_pkr'] ?? null)) {
            return Formats::rupees($payload['amount_due_pkr']);
        }

        return trim(str_replace([' a month', ' / month'], '', (string) ($payload['amount_due'] ?? '')));
    }

    /** The amount as digits only, for the copy button ("2999"). */
    public static function amountDigits(UpgradeRequest $request): string
    {
        $payload = self::payload($request);

        return is_int($payload['amount_due_pkr'] ?? null) ? (string) $payload['amount_due_pkr'] : (string) preg_replace('/\D+/', '', self::amount($request));
    }

    public static function reference(UpgradeRequest $request): ?string
    {
        $code = self::payload($request)['reference_code'] ?? null;

        return is_string($code) && $code !== '' ? $code : null;
    }

    public static function chooseUrl(?string $plan = null, ?string $feature = null, ?string $promo = null): string
    {
        return ChoosePlan::getUrl(array_filter(['plan' => $plan, 'feature' => $feature, 'promo' => $promo]), panel: 'app');
    }

    public static function statusUrl(UpgradeRequest|string $request): string
    {
        return RequestStatus::getUrl(['request' => $request instanceof UpgradeRequest ? $request->id : $request], panel: 'app');
    }

    public static function payUrl(UpgradeRequest|string $request): string
    {
        return PayForPlan::getUrl(['request' => $request instanceof UpgradeRequest ? $request->id : $request], panel: 'app');
    }

    public static function receiptUrl(UpgradeRequest|string $request): string
    {
        return SendReceipt::getUrl(['request' => $request instanceof UpgradeRequest ? $request->id : $request], panel: 'app');
    }

    /** Where Upgrade goes: the flow, or the request still open. */
    public static function entryUrl(?SettingsContext $context, ?string $plan = null, ?string $feature = null): string
    {
        $open = $context?->openRequest();

        return $open !== null ? self::statusUrl($open) : self::chooseUrl($plan, $feature);
    }

    /** Whether a decided request still shows on Plan & usage (approved 7 days, declined 14, until dismissed). */
    public static function showsDecided(UpgradeRequest $request, ?SettingsContext $context = null): bool
    {
        $stage = self::stage($request);
        $decided = $request->decided_at ?? $request->created_at;
        if (self::dismissed($request, $context)) {
            return false;
        }

        return match ($stage) {
            'approved' => $decided->greaterThan(now()->subDays(self::SHOW_APPROVED_DAYS)),
            'declined' => $decided->greaterThan(now()->subDays(self::SHOW_DECLINED_DAYS)),
            default => false,
        };
    }

    public static function dismissed(UpgradeRequest $request, ?SettingsContext $context = null): bool
    {
        $user = $context !== null ? $context->user : $request->user;
        $dismissed = is_array($user->console_preferences) ? ($user->console_preferences['dismissed_requests'] ?? []) : [];

        return is_array($dismissed) && in_array($request->id, $dismissed, true);
    }

    /**
     * The request card (Plan & usage, the request's page): title, stage tag, body lines and its actions.
     *
     * @return array{stage: string, title: string, tag: string, tone: string, body: list<string>, primary: array{label: string, url: string}|null, secondary: array{label: string, url?: string, action?: string, danger?: bool}|null}
     */
    public static function card(UpgradeRequest $request): array
    {
        $stage = self::stage($request);
        $payload = self::payload($request);
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $plan = (string) ($payload['plan_name'] ?? self::planName($request->plan));
        $reference = self::reference($request);
        $reply = is_string($payload['reply'] ?? null) && trim((string) $payload['reply']) !== '' ? trim((string) $payload['reply']) : null;

        return match ($stage) {
            'in_review' => ['stage' => $stage, 'title' => 'Checking your payment', 'tag' => 'In review', 'tone' => 'neutral',
                'body' => array_filter([
                    ($paid = $payment['paid_at'] ?? null) !== null ? 'We got your receipt on '.Formats::shortDate((string) $paid).' at '.Formats::time((string) $paid).'.' : 'We got your receipt.',
                    is_string($payment['review_hours'] ?? null) ? (string) $payment['review_hours'] : null,
                ]),
                'primary' => ['label' => 'View request', 'url' => self::statusUrl($request)], 'secondary' => null],
            'needs_receipt' => ['stage' => $stage, 'title' => 'We need another receipt', 'tag' => 'Action needed', 'tone' => 'warning',
                'body' => array_values(array_filter([
                    is_array($payment['proof_request'] ?? null) ? (string) ($payment['proof_request']['note'] ?? '') : null,
                    "Your request is still open. You don't need to pay again.",
                ])),
                'primary' => ['label' => 'Add receipt', 'url' => self::receiptUrl($request)], 'secondary' => ['label' => 'Payment details', 'url' => self::payUrl($request)]],
            'declined' => ['stage' => $stage, 'title' => "We couldn't confirm your payment", 'tag' => 'Not approved', 'tone' => 'neutral',
                'body' => array_values(array_filter([$reply, "If you've paid, reply to our email with your transaction ID and we'll look again."])),
                'primary' => ['label' => 'Request again', 'url' => self::chooseUrl($request->plan)], 'secondary' => ['label' => 'Dismiss', 'action' => 'dismissRequest']],
            'approved' => ['stage' => $stage, 'title' => "You're on {$plan}", 'tag' => 'Approved', 'tone' => 'success',
                'body' => [self::approvedLine($request, $plan)],
                'primary' => null, 'secondary' => ['label' => 'Dismiss', 'action' => 'dismissRequest']],
            'cancelled' => ['stage' => $stage, 'title' => "You cancelled your {$plan} request", 'tag' => 'Cancelled', 'tone' => 'neutral',
                'body' => ['You can start again any time.'], 'primary' => null, 'secondary' => null],
            default => ['stage' => 'awaiting_payment', 'title' => "{$plan} requested", 'tag' => 'Waiting for payment', 'tone' => 'neutral',
                'body' => ['Send '.self::amount($request).' to our bank account, then add a screenshot of the receipt.'.($reference !== null ? " Reference {$reference}." : '')],
                'primary' => ['label' => 'Payment details', 'url' => self::payUrl($request)], 'secondary' => ['label' => 'Cancel request', 'action' => 'cancelRequest', 'danger' => true]],
        };
    }

    public static function approvedLine(UpgradeRequest $request, string $plan): string
    {
        return $request->approved_until !== null
            ? "Thank you. {$plan} is on until ".Formats::longDate($request->approved_until).'.'
            : "Thank you. {$plan} is on.";
    }

    /**
     * The request's steps: Requested, Receipt sent, (New receipt asked for), Checked by our team, {Pro} on; a
     * declined or cancelled request ends with one final step.
     *
     * @return list<array{label: string, state: string, at: string|null, note: string|null}>
     */
    public static function timeline(UpgradeRequest $request): array
    {
        $payload = self::payload($request);
        $stage = self::stage($request);
        $plan = (string) ($payload['plan_name'] ?? self::planName($request->plan));
        $times = [];
        foreach ((array) ($payload['timeline'] ?? []) as $step) {
            if (is_array($step) && is_string($step['step'] ?? null)) {
                $times[$step['step']] = $step['at'] ?? null;
            }
        }
        $times['requested'] ??= $request->created_at->toAtomString();
        $payment = is_array($payload['payment'] ?? null) ? $payload['payment'] : [];
        $times['receipt_sent'] ??= $payment['paid_at'] ?? null;
        $when = fn (?string $at): ?string => $at !== null ? Formats::shortDate($at).', '.Formats::time($at) : null;

        $steps = [['label' => 'Requested', 'state' => 'done', 'at' => $when($times['requested']), 'note' => null]];
        $sent = $times['receipt_sent'] !== null;
        $steps[] = ['label' => 'Receipt sent', 'state' => $sent ? 'done' : ($stage === 'awaiting_payment' ? 'current' : 'upcoming'), 'at' => $sent ? $when((string) $times['receipt_sent']) : null, 'note' => null];
        if (isset($times['receipt_requested'])) {
            $note = is_array($payment['proof_request'] ?? null) ? (string) ($payment['proof_request']['note'] ?? '') : null;
            $steps[] = ['label' => 'New receipt asked for', 'state' => $stage === 'needs_receipt' ? 'current' : 'done', 'at' => $when((string) $times['receipt_requested']), 'note' => $note !== '' ? $note : null];
        }
        $decided = $request->decided_at?->toAtomString() ?? ($times['decided'] ?? null);

        return match ($stage) {
            'declined' => [...$steps, ['label' => 'Not approved', 'state' => 'done', 'at' => $when($decided), 'note' => null]],
            'cancelled' => [...$steps, ['label' => 'Cancelled by you', 'state' => 'done', 'at' => $when($times['cancelled'] ?? $decided), 'note' => null]],
            'approved' => [...$steps,
                ['label' => 'Checked by our team', 'state' => 'done', 'at' => $when($decided), 'note' => null],
                ['label' => "{$plan} on", 'state' => 'done', 'at' => $request->approved_until !== null ? 'until '.Formats::shortDate($request->approved_until, year: true) : null, 'note' => null]],
            default => [...$steps,
                ['label' => 'Checked by our team', 'state' => $stage === 'in_review' ? 'current' : 'upcoming', 'at' => null, 'note' => null],
                ['label' => "{$plan} on", 'state' => 'upcoming', 'at' => null, 'note' => null]],
        };
    }

    /**
     * The state row under the profile card (one, the most urgent), or null.
     *
     * @return array{icon: string, tone: string, text: string, url: string, dismiss: string|null, action: array{label: string, url: string}|null}|null
     */
    public static function stateRow(SettingsContext $context): ?array
    {
        $user = $context->user;
        if ($user->deletes_at !== null) {
            return ['icon' => 'heroicon-o-exclamation-triangle', 'tone' => 'warning', 'text' => 'Your account will be deleted on '.Formats::shortDate($user->deletes_at).'.',
                'url' => EditProfile::settingsUrl('privacy', 'privacy.cancel_deletion'), 'dismiss' => null, 'action' => ['label' => 'Cancel deletion', 'url' => EditProfile::settingsUrl('privacy', 'privacy.cancel_deletion')]];
        }
        $request = $context->latestRequest();
        $stage = $request !== null ? self::stage($request) : null;
        $plan = $request !== null ? self::planName($request->plan) : '';
        $row = match (true) {
            $request === null => null,
            $stage === 'needs_receipt' => ['icon' => 'heroicon-o-exclamation-circle', 'tone' => 'warning', 'text' => "We need another receipt for {$plan}", 'url' => self::statusUrl($request), 'dismiss' => null],
            $stage === 'declined' && self::showsDecided($request, $context) => ['icon' => 'heroicon-o-information-circle', 'tone' => 'muted', 'text' => "Your {$plan} request wasn't approved", 'url' => self::statusUrl($request), 'dismiss' => $request->id],
            $stage === 'awaiting_payment' => ['icon' => 'heroicon-o-clock', 'tone' => 'muted', 'text' => "{$plan} requested. Waiting for your payment", 'url' => self::payUrl($request), 'dismiss' => null],
            $stage === 'in_review' => ['icon' => 'heroicon-o-clock', 'tone' => 'muted', 'text' => "Checking your payment for {$plan}", 'url' => self::statusUrl($request), 'dismiss' => null],
            default => null,
        };
        if ($row !== null) {
            return [...$row, 'action' => null];
        }
        $expires = $context->workspace()?->plan_expires_at;
        $current = $context->plan();
        if ($expires !== null && $current->key !== Plan::FREE && $expires->isFuture() && $expires->lessThanOrEqualTo(now()->addDays(7))) {
            return ['icon' => 'heroicon-o-calendar', 'tone' => 'warning', 'text' => "{$current->name} ends on ".Formats::shortDate($expires).'.', 'url' => EditProfile::settingsUrl('plan'), 'dismiss' => null, 'action' => null];
        }
        if ($request !== null && $stage === 'approved' && self::showsDecided($request, $context)) {
            $until = $request->approved_until !== null ? ' until '.Formats::shortDate($request->approved_until, year: true) : '';

            return ['icon' => 'heroicon-o-check-circle', 'tone' => 'success', 'text' => "You're on ".self::planName($request->approved_plan ?? $request->plan).$until,
                'url' => EditProfile::settingsUrl('plan'), 'dismiss' => $request->id, 'action' => null];
        }
        if ($context->flag('free_or_key') && ! $context->flag('key')) {
            return ['icon' => 'heroicon-o-key', 'tone' => 'muted', 'text' => 'Set up free transcription (2 minutes)', 'url' => EditProfile::settingsUrl('transcription-key'), 'dismiss' => null, 'action' => null];
        }

        return null;
    }

    /**
     * "This month": the minutes (a meter on a plan with minutes; the person's own key on Free), storage, devices,
     * team members (when the plan has seats) and uploads a day (from 80 %).
     *
     * @return list<array{id: string, title: string, value: string, percent: int|null, tone: string, line: string|null, link: array{label: string, url: string}|null, label: string}>
     */
    public static function meters(SettingsContext $context): array
    {
        $payload = app(Entitlements::class)->payload($context->user, $context->workspaceId());
        $limits = (array) $payload['limits'];
        $open = $context->hasOpenRequest();
        $buyer = $context->flag('buyer') && ! $open && $context->higherPlans() !== [];
        $upgradeUrl = self::chooseUrl();
        $minutes = (array) ($limits['monthly_minutes'] ?? []);
        $resets = self::resetsAt($minutes['resets_at'] ?? null);
        $out = [];

        $limit = $minutes['limit'] ?? null;
        if ($limit === 0 || $limit === 0.0) {
            $key = $context->flag('key');
            $out[] = ['id' => 'plan.usage.minutes', 'title' => 'Transcription', 'value' => 'Your Groq key', 'percent' => null, 'tone' => 'neutral',
                'line' => $key ? 'Key added' : 'Not added.', 'link' => $key ? null : ['label' => 'Add key', 'url' => EditProfile::settingsUrl('transcription-key')],
                'label' => 'Transcription, with your own Groq key, '.($key ? 'key added' : 'not added')];
        } else {
            $used = (int) ($minutes['used'] ?? 0);
            $meter = self::meter($used, $limit === null ? null : (int) $limit);
            $value = $limit === null ? number_format($used).' min' : number_format($used).' of '.number_format((int) $limit).' min';
            $line = match (true) {
                $meter['percent'] !== null && $meter['percent'] >= 100 => 'Transcription is paused until '.Formats::shortDate($resets).'.',
                $meter['percent'] !== null && $meter['percent'] >= 80 => "You've used {$meter['percent']}% of your minutes. ".($buyer ? 'Upgrade or wait until '.Formats::shortDate($resets).'.' : 'They reset on '.Formats::shortDate($resets).'.'),
                default => 'Resets '.Formats::shortDate($resets),
            };
            $out[] = ['id' => 'plan.usage.minutes', 'title' => 'Transcription', 'value' => $value, 'percent' => $meter['percent'], 'tone' => $meter['tone'],
                'line' => $line, 'link' => $meter['percent'] !== null && $meter['percent'] >= 80 && $meter['percent'] < 100 && $buyer ? ['label' => 'Upgrade', 'url' => $upgradeUrl] : null,
                'label' => "Transcription, {$value} used, resets ".Formats::longDate($resets)];
        }

        $storage = (array) ($limits['storage_bytes'] ?? []);
        $meter = self::meter((int) ($storage['used'] ?? 0), isset($storage['limit']) ? (int) $storage['limit'] : null);
        $value = Formats::bytes((int) ($storage['used'] ?? 0)).(isset($storage['limit']) ? ' of '.Formats::bytes((int) $storage['limit']) : '');
        $out[] = ['id' => 'plan.usage.storage', 'title' => 'Storage', 'value' => $value, 'percent' => $meter['percent'], 'tone' => $meter['tone'],
            'line' => $meter['percent'] !== null && $meter['percent'] >= 100 ? ($buyer ? "New recordings can't upload until you free up space or upgrade." : "New recordings can't upload until you free up space.") : null,
            'link' => null, 'label' => "Storage, {$value} used"];

        $devices = (array) ($limits['devices'] ?? []);
        $meter = self::meter((int) ($devices['used'] ?? 0), isset($devices['limit']) ? (int) $devices['limit'] : null);
        $value = number_format((int) ($devices['used'] ?? 0)).(isset($devices['limit']) ? ' of '.number_format((int) $devices['limit']) : '');
        $out[] = ['id' => 'plan.usage.devices', 'title' => 'Devices', 'value' => $value, 'percent' => $meter['percent'], 'tone' => $meter['tone'],
            'line' => $meter['percent'] !== null && $meter['percent'] >= 100 ? 'Sign out a device to add another.' : null, 'link' => null, 'label' => "Devices, {$value} signed in"];

        $seats = (array) ($limits['seats'] ?? []);
        if (! isset($seats['limit']) || (int) $seats['limit'] > 1) {
            $value = number_format((int) ($seats['used'] ?? 1)).(isset($seats['limit']) ? ' of '.number_format((int) $seats['limit']) : '');
            $meter = self::meter((int) ($seats['used'] ?? 1), isset($seats['limit']) ? (int) $seats['limit'] : null);
            $out[] = ['id' => 'plan.usage.members', 'title' => 'Team members', 'value' => $value, 'percent' => $meter['percent'], 'tone' => $meter['percent'] !== null && $meter['percent'] >= 100 ? 'warning' : $meter['tone'],
                'line' => null, 'link' => null, 'label' => "Team members, {$value}"];
        }

        $uploads = (array) ($limits['uploads_per_day'] ?? []);
        if (isset($uploads['limit']) && (int) $uploads['limit'] > 0 && (int) ($uploads['used'] ?? 0) * 100 >= 80 * (int) $uploads['limit']) {
            $meter = self::meter((int) $uploads['used'], (int) $uploads['limit']);
            $value = number_format((int) $uploads['used']).' of '.number_format((int) $uploads['limit']);
            $out[] = ['id' => 'plan.usage.uploads', 'title' => 'Uploads in the last 24 hours', 'value' => $value, 'percent' => $meter['percent'], 'tone' => $meter['tone'],
                'line' => null, 'link' => null, 'label' => "Uploads in the last 24 hours, {$value}"];
        }

        return $out;
    }

    /** @return array{percent: int|null, tone: string} */
    private static function meter(int $used, ?int $limit): array
    {
        if ($limit === null || $limit <= 0) {
            return ['percent' => null, 'tone' => 'neutral'];
        }
        $percent = min(100, (int) floor($used * 100 / $limit));

        return ['percent' => $percent, 'tone' => $percent >= 100 ? 'danger' : ($percent >= 80 ? 'warning' : 'neutral')];
    }

    /** When the minutes reset: the server's date, else the 1st of next month in the console's time zone. */
    public static function resetsAt(mixed $at): Carbon
    {
        if (is_string($at) && $at !== '') {
            return Carbon::parse($at);
        }

        return now(app(SettingsRepository::class)->displayTimezone())->startOfMonth()->addMonth();
    }

    /** The price of a plan as its card writes it ("Rs 2,999 a month"). */
    public static function priceText(Plan $plan): string
    {
        $card = Entitlements::planCard($plan);

        return is_string($card['price_text'] ?? null) && $card['price_text'] !== '' ? (string) $card['price_text'] : $plan->displayPrice();
    }

    /** The amount alone, "Rs 2,999", or null when the plan has no price. */
    public static function price(Plan $plan, int $percent = 0): ?string
    {
        $card = Entitlements::planCard($plan);
        if (is_int($card['price_pkr'] ?? null)) {
            return Formats::rupees((int) round($card['price_pkr'] * (100 - $percent) / 100));
        }
        $text = trim(str_replace([' a month', ' / month'], '', $percent > 0 ? PromoCodes::discounted($plan, $percent) : $plan->displayPrice()));

        return preg_match('/\d/', $text) === 1 ? $text : null;
    }

    /**
     * What a plan card lists: its minutes, then what it adds to the plan below it (at most 5 lines).
     *
     * @return list<string>
     */
    public static function highlights(Plan $plan, ?Plan $below): array
    {
        $card = Entitlements::planCard($plan);
        if (is_array($card['highlights'] ?? null) && $card['highlights'] !== []) {
            return array_slice(array_values(array_filter($card['highlights'], 'is_string')), 0, 5);
        }
        $lines = [];
        $minutes = $plan->limit('monthly_minutes');
        if ($minutes !== null && $minutes > 0 && ($below === null || $below->limit('monthly_minutes') !== $minutes)) {
            $lines[] = number_format((int) $minutes).' transcription minutes a month'.($below === null || ! $below->allows('platform_transcription') ? ', no key needed' : '');
        }
        $seats = $plan->limit('seats');
        if (($seats === null || $seats > 1) && ($below === null || $below->limit('seats') !== $seats)) {
            $lines[] = $seats === null ? 'A team of any size' : 'A team of up to '.number_format((int) $seats).' people';
        }
        foreach (Features::paid() as $feature) {
            if (in_array($feature, ['platform_transcription', 'team'], true) || ! $plan->allows($feature) || ($below !== null && $below->allows($feature))) {
                continue;
            }
            $lines[] = Features::ALL[$feature]['description'];
        }

        return array_slice($lines, 0, 5);
    }
}
