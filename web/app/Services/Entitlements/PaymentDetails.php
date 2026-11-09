<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Services\SettingsRepository;

/**
 * The account customers pay into (since v1.7, docs/API.md "Payment receipts"): bank, branch, account title, account
 * number, IBAN and a note, set in Admin → Plans → Payment details. `payment.accounts` is made from them. While they are
 * all empty the request's note is the v1.6 free text (`upgrade.payment_instructions`), as production had it; v1.6 apps
 * keep reading `payment_instructions`, written from these fields when they are set.
 */
final class PaymentDetails
{
    /** The settings, in the order the form and the text show them. */
    public const FIELDS = ['payment.bank', 'payment.branch', 'payment.account_title', 'payment.account_number', 'payment.iban', 'payment.note'];

    public const REVIEW_HOURS = 'We check receipts every day. Most are done within a few hours.';

    public function __construct(private readonly SettingsRepository $settings) {}

    /** @return list<array{bank: string|null, branch: string|null, title: string|null, account_number: string|null, iban: string|null}> */
    public function accounts(): array
    {
        $account = [
            'bank' => $this->text('payment.bank'),
            'branch' => $this->text('payment.branch'),
            'title' => $this->text('payment.account_title'),
            'account_number' => $this->text('payment.account_number'),
            'iban' => $this->text('payment.iban'),
        ];

        return array_filter($account, fn (?string $v): bool => $v !== null) === [] ? [] : [$account];
    }

    public function hasAccounts(): bool
    {
        return $this->accounts() !== [];
    }

    /** What to do when paying, with the request's [$reference] in it; the v1.6 text while no account is set. */
    public function note(?string $reference): string
    {
        if (! $this->hasAccounts()) {
            return $this->legacyText();
        }
        $note = $this->text('payment.note');
        if ($note === null) {
            return $reference !== null ? "Put {$reference} in the transfer's note." : "Put your request's reference in the transfer's note.";
        }

        return str_replace('{reference}', $reference ?? 'your reference', $note);
    }

    /** `payment_instructions` for v1.6 apps: the account as text lines and the note, else the v1.6 free text. */
    public function instructions(?string $reference): string
    {
        $accounts = $this->accounts();
        if ($accounts === []) {
            return $this->legacyText();
        }
        $labels = ['bank' => 'Bank', 'branch' => 'Branch', 'title' => 'Account title', 'account_number' => 'Account number', 'iban' => 'IBAN'];
        $lines = [];
        foreach ($labels as $key => $label) {
            if ($accounts[0][$key] !== null) {
                $lines[] = "{$label}: {$accounts[0][$key]}";
            }
        }
        $lines[] = $this->note($reference);

        return implode("\n", $lines);
    }

    /** The v1.6 free text (Admin → Plans → Payment instructions), or the default sentence. */
    public function legacyText(): string
    {
        return $this->text('upgrade.payment_instructions') ?? UpgradeRequests::DEFAULT_INSTRUCTIONS;
    }

    /** An IBAN as stored: upper case, no spaces. */
    public static function normalizeIban(string $iban): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $iban));
    }

    /** A Pakistani IBAN: "PK", 2 check digits and 20 letters and digits (24 in all), with a correct mod-97 check. */
    public static function validIban(string $iban): bool
    {
        $iban = self::normalizeIban($iban);
        if (preg_match('/^PK[0-9]{2}[A-Z0-9]{20}$/', $iban) !== 1) {
            return false;
        }
        $digits = '';
        foreach (str_split(substr($iban, 4).substr($iban, 0, 4)) as $char) {
            $digits .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }
        $remainder = 0;
        foreach (str_split($digits, 7) as $part) {
            $remainder = (int) ((string) $remainder.$part) % 97;
        }

        return $remainder === 1;
    }

    private function text(string $key): ?string
    {
        $value = $this->settings->get($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
