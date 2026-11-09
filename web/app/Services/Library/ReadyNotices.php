<?php

declare(strict_types=1);

namespace App\Services\Library;

use App\Mail\TranscriptFailedMail;
use App\Mail\TranscriptReadyMail;
use App\Models\CaptureSession;
use App\Models\User;
use App\Services\Accounts\EmailPreferences;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * "Notify me when ready" on the console's recording page (since v1.6, P5.4): one email for that recording when its
 * transcript is ready, or when it could not be made, whatever the person's email settings say (the bell shows it as
 * always). Kept in the cache, no migration: a request waits at most three days. Only the recording's owner can ask.
 */
final class ReadyNotices
{
    /** How long a request waits for its transcript. */
    public const KEEP_HOURS = 72;

    /** Asks for the one email of [$session]'s transcript for [$user]. False when [$user] is not its owner. */
    public function ask(User $user, CaptureSession $session): bool
    {
        if ((int) $session->user_id !== (int) $user->id) {
            return false;
        }
        Cache::put(self::key($session), [(int) $user->id], now()->addHours(self::KEEP_HOURS));

        return true;
    }

    public function asked(User $user, CaptureSession $session): bool
    {
        return in_array((int) $user->id, (array) Cache::get(self::key($session), []), true);
    }

    /**
     * Sends the asked-for email once the transcript is ready ([$ready]) or could not be made, then forgets the request.
     * A person who gets "transcript ready" emails anyway is not sent a second one. Never throws.
     */
    public function deliver(CaptureSession $session, bool $ready): void
    {
        try {
            $asked = Cache::pull(self::key($session));
            if (! is_array($asked) || ! in_array((int) $session->user_id, array_map('intval', $asked), true)) {
                return;
            }
            $owner = $session->user()->first();
            if (! $owner instanceof User || ($ready && EmailPreferences::wants($owner, EmailPreferences::TRANSCRIPTION))) {
                return;
            }
            $url = url('/app/sessions/'.$session->id);
            Mail::to($owner->email)->queue($ready
                ? new TranscriptReadyMail($session->displayTitle(), $url, asked: true)
                : new TranscriptFailedMail($session->displayTitle(), $url));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private static function key(CaptureSession $session): string
    {
        return 'ready-notice:'.$session->id;
    }
}
