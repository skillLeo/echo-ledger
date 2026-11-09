<?php

declare(strict_types=1);

namespace App\Filament\Pages\Upgrade;

use App\Filament\Support\SettingsContext;
use App\Filament\Support\UpgradeFlow;
use App\Models\UpgradeRequest;
use App\Services\Workspaces\ConsoleAccess;
use App\Support\Formats;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * A page of one upgrade request (since v1.7): only the person's own request opens; anyone else's id, a teammate's
 * too, is the same 404 as an unknown one.
 */
abstract class RequestPage extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    #[Locked]
    public string $requestId = '';

    public static function canAccess(): bool
    {
        return ConsoleAccess::open() && SettingsContext::current() !== null;
    }

    public function mount(string $request): void
    {
        $this->requestId = $this->own($request)->id;
    }

    public function upgradeRequest(): UpgradeRequest
    {
        return $this->own($this->requestId);
    }

    protected function own(string $id): UpgradeRequest
    {
        return UpgradeRequest::query()->where('user_id', (int) auth()->id())->whereKey($id)->firstOrFail();
    }

    public function planName(): string
    {
        return UpgradeFlow::planName($this->upgradeRequest()->plan);
    }

    public function getHeading(): string
    {
        return '';
    }

    /** @return list<array<string, mixed>> the request's receipts, with the console's own addresses for them */
    public function proofs(): array
    {
        $out = [];
        foreach ((array) (UpgradeFlow::payment($this->upgradeRequest())['proofs'] ?? []) as $proof) {
            if (! is_array($proof) || ! is_string($proof['id'] ?? null)) {
                continue;
            }
            $pdf = ($proof['kind'] ?? '') === 'pdf';
            $out[] = [...$proof,
                'pdf' => $pdf,
                'thumb' => $pdf ? null : route('account.payment-proofs', ['proof' => $proof['id'], 'variant' => 'thumb']),
                'full' => route('account.payment-proofs', ['proof' => $proof['id'], 'variant' => 'full']),
                'size' => Formats::bytes((int) ($proof['bytes'] ?? 0)),
                'sent' => ($proof['locked'] ?? false) === true,
            ];
        }

        return $out;
    }
}
