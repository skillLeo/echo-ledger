<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\ApiError;
use App\Models\CaptureSession;
use App\Models\Client;
use App\Models\ShareLink;
use App\Services\Views\TranscriptViews;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * Secure share links (docs/API.md "Share links", since v1.1, and "Share links (since v1.5)"). The token is 256 random
 * bits, shown once; the database keeps its SHA-256. A link opens one recording (`/s/…`) or, since v1.5, a project's
 * recordings (`/p/…`); it is open to anyone with it, to anyone with its password, or only to the people it names
 * (signed in to the console with one of their emails); it may let files be downloaded; it can expire and be revoked,
 * and every access is logged with a keyed hash of the IP address.
 */
final class ShareLinks
{
    /** @var list<int> */
    public const LIFETIMES = [3_600, 86_400, 604_800];

    public const MAX_ACTIVE_PER_SESSION = 20;

    public const MAX_PEOPLE = 20;

    /** A v1.1 body without a lifetime keeps the old default of one day. */
    public const DEFAULT_LIFETIME = 86_400;

    /**
     * The v1.1 call (the console's dialog and older apps): a lifetime, an optional password, the audio or not.
     *
     * @return array{link: ShareLink, url: string}
     */
    public function create(CaptureSession $session, int $expiresIn, ?string $password, bool $includeAudio, ?string $author, ?string $view = null): array
    {
        if (! in_array($expiresIn, self::LIFETIMES, true)) {
            throw new ApiError(422, 'validation_failed', 'Choose 1 hour, 1 day or 7 days.');
        }

        return $this->make($session, [
            'audience' => $password !== null && $password !== '' ? 'password' : 'public',
            'password' => $password,
            'include_audio' => $includeAudio,
            'allow_download' => $includeAudio,
            'expires_at' => now()->addSeconds($expiresIn),
            'view' => $view,
        ], $author);
    }

    /**
     * A link to a recording or a project (since v1.5). `$options`: audience (public | password | people), password
     * (6-100 characters, for `password`), people (1-20 emails, for `people`), include_audio (the page plays the
     * recording), allow_download (the audio and transcript can be downloaded), expires_at (null = no expiry), view.
     *
     * @param  array{audience: string, password?: string|null, people?: list<string>|null, include_audio: bool, allow_download: bool, expires_at: Carbon|null, view?: string|null}  $options
     * @return array{link: ShareLink, url: string}
     */
    public function make(CaptureSession|Client $target, array $options, ?string $author): array
    {
        $audience = in_array($options['audience'], ShareLink::AUDIENCES, true) ? $options['audience'] : 'public';
        $password = $audience === 'password' ? trim((string) ($options['password'] ?? '')) : '';
        if ($audience === 'password' && (mb_strlen($password) < 6 || mb_strlen($password) > 100)) {
            throw new ApiError(422, 'validation_failed', 'Give a password of 6 to 100 characters.', ['errors' => ['password' => ['Give a password of 6 to 100 characters.']]]);
        }
        $people = $audience === 'people' ? self::cleanPeople($options['people'] ?? []) : null;
        if ($audience === 'people' && $people === []) {
            throw new ApiError(422, 'validation_failed', 'Name at least one person by their email.', ['errors' => ['people' => ['Name at least one person by their email.']]]);
        }
        $expiresAt = $options['expires_at'];
        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new ApiError(422, 'validation_failed', 'Choose an expiry in the future.', ['errors' => ['expires_at' => ['Choose an expiry in the future.']]]);
        }
        $links = $target instanceof Client ? ShareLink::query()->where('client_id', $target->id) : $target->shareLinks();
        $active = $links->whereNull('revoked_at')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->count();
        if ($active >= self::MAX_ACTIVE_PER_SESSION) {
            $what = $target instanceof Client ? 'project' : 'recording';
            throw new ApiError(409, 'too_many_shares', "This {$what} already has 20 active links. Revoke one first.");
        }
        $view = $options['view'] ?? null;
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $link = ShareLink::query()->create([
            'capture_session_id' => $target instanceof CaptureSession ? $target->id : null,
            'client_id' => $target instanceof Client ? $target->id : null,
            'token_hash' => self::hash($token),
            'password_hash' => $audience === 'password' ? Hash::make($password) : null,
            'audience' => $audience,
            'people' => $people,
            'include_audio' => $options['include_audio'],
            'allow_download' => $options['allow_download'],
            // Show as: the page opens in this view while it is made and fresh, else in the original words.
            'view' => $view !== null && array_key_exists($view, TranscriptViews::VIEWS) ? $view : null,
            'expires_at' => $expiresAt,
            'created_by' => $author !== null ? mb_substr($author, 0, 120) : null,
        ]);

        return ['link' => $link, 'url' => $target instanceof Client ? self::projectUrl($token) : self::url($token)];
    }

    /**
     * Emails in lower case, without blanks or repeats; at most MAX_PEOPLE.
     *
     * @param  iterable<mixed>  $people
     * @return list<string>
     */
    public static function cleanPeople(iterable $people): array
    {
        $clean = [];
        foreach ($people as $email) {
            $value = is_string($email) ? mb_strtolower(trim($email)) : '';
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) !== false && ! in_array($value, $clean, true)) {
                $clean[] = $value;
            }
        }

        return array_slice($clean, 0, self::MAX_PEOPLE);
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

    /** Logs one access (`viewed`, `opened`, `wrong_password`, `audio`, `download`); `viewed` also counts as a view. */
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

    /** A project's link (since v1.5). */
    public static function projectUrl(string $token): string
    {
        return url('/p/'.$token);
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
