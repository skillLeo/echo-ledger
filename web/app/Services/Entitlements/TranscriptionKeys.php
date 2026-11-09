<?php

declare(strict_types=1);

namespace App\Services\Entitlements;

use App\Exceptions\ApiError;
use App\Models\User;
use App\Services\AuditLog;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A person's own transcription key (since v1.6, P1.3, docs/API.md "Your own transcription key"): the Free plan's
 * transcripts are made with it, through the server's Groq driver, and never billed to the server. Groq is asked once
 * whether it takes the key; the key is kept encrypted with the app's key, for that person only, and is never sent
 * back, put in a log or in an error.
 */
final class TranscriptionKeys
{
    public const PROVIDER = 'groq';

    /** Groq's list of models answers 200 for a key it takes and 401 for one it refuses (console.groq.com/docs/api-reference). */
    public const CHECK_URL = 'https://api.groq.com/openai/v1/models';

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Checks [$key] with Groq once and keeps it: `422 invalid_key` when Groq refuses it, `502 provider_error` when Groq
     * cannot be asked now.
     *
     * @return array{provider: string, set: bool, last4: string|null}
     */
    public function put(User $user, string $provider, #[\SensitiveParameter] string $key): array
    {
        $key = trim($key);
        if ($provider !== self::PROVIDER || preg_match('/^[A-Za-z0-9_\-]{20,200}$/', $key) !== 1) {
            throw new ApiError(422, 'invalid_key', 'That is not a Groq key. It starts with gsk_: copy it again from console.groq.com → API Keys.');
        }
        try {
            $answer = Http::withToken($key)->acceptJson()->connectTimeout(10)->timeout(15)->get(self::CHECK_URL);
        } catch (Throwable) {
            throw new ApiError(502, 'provider_error', 'Groq could not be reached to check the key. Try again in a minute.');
        }
        if (in_array($answer->status(), [401, 403], true)) {
            throw new ApiError(422, 'invalid_key', 'Groq did not accept this key. Copy it again from console.groq.com → API Keys, or make a new one.');
        }
        if (! $answer->successful()) {
            throw new ApiError(502, 'provider_error', 'Groq could not check the key just now. Try again in a minute.');
        }
        $user->forceFill([
            'transcription_key' => $key,
            'transcription_key_provider' => self::PROVIDER,
            'transcription_key_last4' => substr($key, -4),
            'transcription_key_checked_at' => now(),
        ])->save();
        $this->audit->record('transcription_key_set', $user->id, null, 'Own Groq key saved (…'.substr($key, -4).')');

        return $this->describe($user);
    }

    public function forget(User $user): void
    {
        if ($user->transcription_key_provider === null && $user->transcription_key === null) {
            return;
        }
        $user->forceFill(['transcription_key' => null, 'transcription_key_provider' => null, 'transcription_key_last4' => null, 'transcription_key_checked_at' => null])->save();
        $this->audit->record('transcription_key_removed', $user->id, null, 'Own Groq key removed');
    }

    /** The key itself, for that person's own recordings only (the transcription job). */
    public function keyFor(?int $userId): ?string
    {
        $user = $userId === null ? null : User::query()->find($userId);
        if (! $user instanceof User || $user->transcription_key_provider !== self::PROVIDER) {
            return null;
        }
        $raw = $user->getRawOriginal('transcription_key');
        try {
            $key = is_string($raw) ? Crypt::decryptString($raw) : null;
        } catch (DecryptException) {
            return null; // made with another app key: as good as none
        }

        return is_string($key) && $key !== '' ? $key : null;
    }

    /** @return array{provider: string, set: bool, last4: string|null} */
    public function describe(User $user): array
    {
        $set = $user->transcription_key_provider === self::PROVIDER && $user->transcription_key_last4 !== null;

        return ['provider' => self::PROVIDER, 'set' => $set, 'last4' => $set ? $user->transcription_key_last4 : null];
    }
}
