<?php

declare(strict_types=1);

namespace App\Filament\Pages\Upgrade;

use App\Exceptions\ApiError;
use App\Filament\Auth\EditProfile;
use App\Filament\Support\Notice as Notification;
use App\Filament\Support\SettingsContext;
use App\Filament\Support\UpgradeFlow;
use App\Models\User;
use App\Services\Entitlements\PaymentProofs;
use App\Support\Formats;
use Filament\Actions\Action;
use Filament\Panel;
use Filament\Support\Exceptions\Halt;

/**
 * The request's page (since v1.7): its card for the stage it is at, its steps with their times, the details (plan,
 * amount, reference, transaction ID), the receipts sent, and Payment details, Add receipt and Cancel request while it
 * is open. An approved request opened here says "You're on {Pro}".
 */
class RequestStatus extends RequestPage
{
    protected static ?string $slug = 'settings/plan/requests/{request}';

    protected string $view = 'filament.upgrade.status';

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'upgrade.request';
    }

    public function getTitle(): string
    {
        return $this->planName().' request';
    }

    public function cancelRequestAction(): Action
    {
        return Action::make('cancelRequest')->label('Cancel request')->color('danger')
            ->requiresConfirmation()->modalIcon(null)
            ->modalHeading('Cancel this request?')
            ->modalDescription("If you've already paid, add your receipt instead. Cancelling doesn't refund a payment.")
            ->modalCancelActionLabel('Keep request')->modalSubmitActionLabel('Cancel request')
            ->action(function (): void {
                /** @var User $user */
                $user = auth()->user();
                try {
                    app(PaymentProofs::class)->cancel($this->upgradeRequest(), $user);
                } catch (ApiError $e) {
                    Notification::make()->title($e->apiCode() === 'request_decided' ? 'This request has already been decided.' : "Couldn't reach EchoLedger. Try again.")->danger()->send();

                    throw new Halt;
                }
                SettingsContext::forget();
                Notification::make()->title('Request cancelled')->success()->send();
            });
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $request = $this->upgradeRequest();
        $payload = UpgradeFlow::payload($request);
        $payment = UpgradeFlow::payment($request);
        $stage = UpgradeFlow::stage($request);
        $open = UpgradeFlow::isOpen($request);

        return [
            'request' => $request,
            'stage' => $stage,
            'open' => $open,
            'card' => UpgradeFlow::card($request),
            'steps' => UpgradeFlow::timeline($request),
            'plan' => $this->planName(),
            'details' => array_values(array_filter([
                ['Plan', $this->planName().', 1 month', null],
                ['Amount', UpgradeFlow::amount($request), null],
                is_string($payload['promo_code'] ?? null) ? ['Promo code', (string) $payload['promo_code'], null] : null,
                UpgradeFlow::reference($request) !== null ? ['Reference', (string) UpgradeFlow::reference($request), (string) UpgradeFlow::reference($request)] : null,
                ['Requested', Formats::shortDate($request->created_at, year: true), null],
                is_string($payment['reference'] ?? null) && $payment['reference'] !== '' ? ['Transaction ID', (string) $payment['reference'], null] : null,
                is_string($payment['payer_name'] ?? null) && $payment['payer_name'] !== '' ? ['Paid from', (string) $payment['payer_name'], null] : null,
            ])),
            'proofs' => $this->proofs(),
            'erased' => ($payment['erased'] ?? false) === true,
            'canAdd' => in_array($stage, ['awaiting_payment', 'needs_receipt'], true),
            'payUrl' => UpgradeFlow::payUrl($request),
            'receiptUrl' => UpgradeFlow::receiptUrl($request),
            'backUrl' => EditProfile::settingsUrl('plan'),
            'announce' => session()->pull('el.receipt.sent') === true ? "Receipt sent. We'll check your payment." : null,
            'approvedLine' => UpgradeFlow::approvedLine($request, UpgradeFlow::planName($request->approved_plan ?? $request->plan)),
        ];
    }
}
