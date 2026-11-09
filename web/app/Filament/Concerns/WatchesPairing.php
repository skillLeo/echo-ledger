<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Services\PairingService;
use Livewire\Attributes\Locked;

/**
 * The "Sign in a device" modal's live status (since v1.6, P3): Waiting → "✓ Galaxy S25 Ultra signed in" (then the modal
 * closes after 2 seconds; the code is used up) or "expired". Only the signed-in person's own code is ever looked at.
 */
trait WatchesPairing
{
    /** The SHA-256 of the code shown in the modal (the code itself is never kept). */
    #[Locked]
    public ?string $pairingHash = null;

    protected function watchPairing(string $displayCode): void
    {
        $this->pairingHash = hash('sha256', PairingService::normalize($displayCode));
    }

    /** @return array{state: string, name: string|null} */
    public function pairingStatus(): array
    {
        $code = $this->pairingHash === null ? null : DevicePairingCode::query()->withoutGlobalScopes()
            ->where('code_hash', $this->pairingHash)->where('user_id', (int) auth()->id())->first();
        if (! $code instanceof DevicePairingCode) {
            return ['state' => 'waiting', 'name' => null];
        }
        if ($code->used_at !== null) {
            $device = $code->device_id === null ? null : Device::query()->withoutGlobalScopes()->find($code->device_id);

            return ['state' => 'signed_in', 'name' => $device?->readableName() ?? 'Your device'];
        }

        return ['state' => $code->expires_at->isPast() ? 'expired' : 'waiting', 'name' => null];
    }
}
