<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\ShareLink;
use App\Services\Views\TranscriptViews;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * Secure share links (docs/API.md "Share links", since v1.1). The token is 256 random bits, shown once; the database
 * keeps its SHA-256. Links expire after 1 hour, 1 day or 7 days, can carry a password and the audio, can be revoked,
 * and every access is logged with a keyed hash of the IP address.
 */
final class ShareLinks
{
    /** @var list<int> */
    public const LIFETIMES = [3_600, 86_400, 604_800];

    public const MAX_ACTIVE_PER_SESSION = 20;

    /** @return array{link: ShareLink, url: string} */
    public function create(CaptureSession $session, int $expiresIn, ?string $password, bool $includeAudio, ?string $author, ?string $view = null): array
    {
        if (! in_array($expiresIn, self::LIFETIMES, true)) {
            throw new ApiError(422, 'validation_failed', 'Choose 1 hour, 1 day or 7 days.');
        }
        $active = $session->shareLinks()->whereNull('revoked_at')->where('expires_at', '>', now())->count();
        if ($active >= self::MAX_ACTIVE_PER_SESSION) {
            throw new ApiError(409, 'too_many_shares', 'This recording already has 20 active links. Revoke one first.');
        }
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $link = $session->shareLinks()->create([
            'token_hash' => self::hash($token),
            'password_hash' => $password !== null && $password !== '' ? Hash::make($password) : null,
            'include_audio' => $includeAudio,
            // Show as: the page opens in this view while it is made and fresh, else in the original words.
            'view' => $view !== null && array_key_exists($view, TranscriptViews::VIEWS) ? $view : null,
            'expires_at' => now()->addSeconds($expiresIn),
            'created_by' => $author !== null ? mb_substr($author, 0, 120) : null,
        ]);

        return ['link' => $link, 'url' => self::url($token)];
    }

    /** The link a token belongs to, in any state; null for an unknown or malformed token. */
    public function find(string $token): ?ShareLink
    {
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        return ShareLink::query()->where('token_hash', self::hash($token))->first();
    }

    public function revoke(ShareLink $link): void
    {
        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now()]);
        }
    }

    public function passwordMatches(ShareLink $link, string $password): bool
    {
        return $link->password_hash !== null && Hash::check($password, $link->password_hash);
    }

    /** Logs one access; a `viewed` access also counts as a view. */
    public function log(ShareLink $link, string $outcome, Request $request): void
    {
        $link->views()->create([
            'outcome' => $outcome,
            'ip_hash' => $request->ip() !== null ? hash_hmac('sha256', (string) $request->ip(), (string) config('app.key')) : null,
            'user_agent' => mb_substr((string) $request->userAgent(), 0, 255) ?: null,
            'viewed_at' => now(),
        ]);
        if ($outcome === 'viewed') {
            $link->forceFill(['view_count' => $link->view_count + 1, 'last_viewed_at' => now()])->save();
        }
    }

    public static function url(string $token): string
    {
        return url('/s/'.$token);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
