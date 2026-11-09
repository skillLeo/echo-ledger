<?php

declare(strict_types=1);

namespace App\Filament\Pages\Upgrade;

use App\Filament\Auth\EditProfile;
use App\Filament\Support\UpgradeFlow;
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
        return 'Pay for '.$this->planName();
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
            if ($title !== '') {
                $rows[] = ['Account title', $title, $title, false];
            }
            foreach (['bank' => 'Bank', 'branch' => 'Branch'] as $key => $label) {
                if (is_string($account[$key] ?? null) && trim((string) $account[$key]) !== '') {
                    $rows[] = [$label, trim((string) $account[$key]), null, false];
                }
            }
            if (is_string($account['account_number'] ?? null) && trim((string) $account['account_number']) !== '') {
                $number = trim((string) $account['account_number']);
                $rows[] = ['Account number', $number, (string) preg_replace('/\s+/', '', $number), true];
            }
            if (is_string($account['iban'] ?? null) && trim((string) $account['iban']) !== '') {
                $iban = strtoupper((string) preg_replace('/\s+/', '', (string) $account['iban']));
                $rows[] = ['IBAN', trim(chunk_split($iban, 4, ' ')), $iban, true];
            }
            if ($rows !== []) {
                $accounts[] = ['title' => $title, 'rows' => $rows];
            }
        }
        $reference = UpgradeFlow::reference($request);
        $note = is_string($payment['note'] ?? null) && trim((string) $payment['note']) !== '' ? trim((string) $payment['note']) : null;
        $instructions = $accounts === [] ? ($note ?? (string) (UpgradeFlow::payload($request)['payment_instructions'] ?? app(UpgradeRequests::class)->paymentInstructions())) : null;

        return [
            'plan' => $this->planName(),
            'amount' => UpgradeFlow::amount($request),
            'amountDigits' => UpgradeFlow::amountDigits($request),
            'accounts' => $accounts,
            'accountTitle' => $accounts[0]['title'] ?? null,
            'reference' => $reference,
            'referenceLine' => $accounts !== [] ? ($note ?? ($reference !== null ? "Put {$reference} in the transfer's note." : null)) : ($reference !== null ? "Put {$reference} in the transfer's note." : null),
            'instructions' => $instructions,
            'stage' => UpgradeFlow::stage($request),
            'receiptUrl' => UpgradeFlow::receiptUrl($request),
            'statusUrl' => UpgradeFlow::statusUrl($request),
            'backUrl' => EditProfile::settingsUrl('plan'),
        ];
    }
}
