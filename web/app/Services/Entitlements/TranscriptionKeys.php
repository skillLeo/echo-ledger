<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Models\User;

/** A person's own transcription key (since v1.6, P1.3): set with PUT /api/me/transcription-key. */
final class TranscriptionKeys
{
    /** @return array{provider: string, set: bool, last4: string|null} */
    public function describe(User $user): array
    {
        return ['provider' => 'groq', 'set' => false, 'last4' => null];
    }
}
