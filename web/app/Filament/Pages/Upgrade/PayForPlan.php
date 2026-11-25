<?php

declare(strict_types=1);

namespace App\Filament\Pages\Upgrade;

use App\Filament\Auth\EditProfile;
use App\Filament\Support\UpgradeFlow;
use App\Services\Entitlements\PaymentDetails;
use App\Services\Entitlements\UpgradeRequests;
use Filament\Panel;

/**
 * Upgrade, step 2 (since v1.7): "Pay for {Pro}": the exact amount, the account to pay into (each detail with Copy),
 * the reference for the transfer's note, then "Add payment receipt" (or "I'll pay later").
 */
class PayForPlan extends RequestPage
{
    protected static ?string $slug = 'settings/plan/requests/{request}/pay';

    protected string $view = 'filament.upgrade.pay';

    public static function getRelativeRouteName(Panel $panel): string
    {
        return 'upgrade.pay';
    }

    public function mount(string $request): void
    {
        parent::mount($request);
        if (! UpgradeFlow::isOpen($this->upgradeRequest())) {
            $this->redirect(UpgradeFlow::statusUrl($this->upgradeRequest()));
        }
    }

    public function getTitle(): string
    {
        return __('Pay for :plan', ['plan' => $this->planName()]);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        $request = $this->upgradeRequest();
        $payment = UpgradeFlow::payment($request);
        $accounts = [];
        foreach ((array) ($payment['accounts'] ?? []) as $account) {
            if (! is_array($account)) {
                continue;
            }
            $rows = [];
            $title = is_string($account['title'] ?? null) ? trim((string) $account['title']) : '';
            // Each row: its label, the value as shown, what Copy copies and the Copy button's name (none for the bank
            // and the branch), and whether it is a number (monospaced).
            if ($title !== '') {
                $rows[] = [__('Account title'), $title, $title, false, __('Copy account title')];
            }
            foreach (['bank' => __('Bank'), 'branch' => __('Branch')] as $key => $label) {
                if (is_string($account[$key] ?? null) && trim((string) $account[$key]) !== '') {
                    $rows[] = [$label, trim((string) $account[$key]), null, false, null];
                }
            }
            if (is_string($account['account_number'] ?? null) && trim((string) $account['account_number']) !== '') {
                $number = trim((string) $account['account_number']);
                $rows[] = [__('Account number'), $number, (string) preg_replace('/\s+/', '', $number), true, __('Copy :label', ['label' => __('Account number')])];
            }
            if (is_string($account['iban'] ?? null) && trim((string) $account['iban']) !== '') {
                $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $account['iban']));
                $rows[] = ['IBAN', trim(chunk_split($iban, 4, ' ')), $iban, true, __('Copy :label', ['label' => 'IBAN'])];
            }
            if ($rows !== []) {
                $accounts[] = ['title' => $title, 'rows' => $rows];
            }
        }
        $reference = UpgradeFlow::reference($request);
        $note = is_string($payment['note'] ?? null) && trim((string) $payment['note']) !== '' ? trim((string) $payment['note']) : null;
        // The server's own note when staff wrote none (PaymentDetails::defaultNote) is the reference line below: in the
        // console's language. A note staff wrote shows as written.
        if ($reference !== null && $note === PaymentDetails::defaultNote($reference)) {
            $note = null;
        }
        $instructions = $accounts === [] ? ($note ?? (string) (UpgradeFlow::payload($request)['payment_instructions'] ?? app(UpgradeRequests::class)->paymentInstructions())) : null;

        return [
            'plan' => $this->planName(),
            'amount' => UpgradeFlow::amount($request),
            'amountDigits' => UpgradeFlow::amountDigits($request),
            'accounts' => $accounts,
            'accountTitle' => $accounts[0]['title'] ?? null,
            'reference' => $reference,
            'referenceLine' => $accounts !== [] ? ($note ?? ($reference !== null ? __("Put :reference in the transfer's note.", ['reference' => $reference]) : null)) : ($reference !== null ? __("Put :reference in the transfer's note.", ['reference' => $reference]) : null),
            'instructions' => $instructions,
            'stage' => UpgradeFlow::stage($request),
            'receiptUrl' => UpgradeFlow::receiptUrl($request),
            'statusUrl' => UpgradeFlow::statusUrl($request),
            'backUrl' => EditProfile::settingsUrl('plan'),
        ];
    }
}
