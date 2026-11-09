<?php

declare(strict_types=1);

namespace App\Services\Titles;

use App\Enums\SourceApp;
use App\Models\CaptureSession;

/**
 * Titles nobody chose (since v1.6, P5.3): empty, the app's own name for an untitled recording ("Voice note", "WhatsApp
 * call"), "Imported file", or the name a phone or recorder gave the file ("PTT-20261108-WA0012", "WhatsApp Audio
 * 2026-11-08 at 10.15.32 PM", "Voice 001", "New Recording 3"). Such a title is replaced by an automatic one; any other
 * title was set by a person and is never replaced.
 */
final class GenericTitles
{
    /** Names that say nothing about the recording, besides each app's fallback title. */
    private const NAMES = ['imported file', 'recovered recording', 'untitled', 'untitled recording', 'new recording', 'audio', 'voice message', 'voice recording'];

    /** File names phones and recorders give a recording (the console and the apps turn `_` into spaces). */
    private const PATTERNS = [
        '/^(ptt|aud)-\d{8}-wa\d{2,}$/',
        '/^whatsapp (audio|ptt|voice note|voice message) \d{4}-\d{2}-\d{2} at \d{1,2}[.:]\d{2}[.:]\d{2}( ?[ap]\.?m\.?)?( \(\d+\))?$/',
        '/^(voice|recording|new recording|audio|voice note|call recording) ?\d{1,4}$/',
    ];

    public static function is(?string $title): bool
    {
        $title = mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', (string) $title)));
        if ($title === '') {
            return true;
        }
        if (in_array($title, self::NAMES, true) || in_array($title, self::fallbacks(), true)) {
            return true;
        }
        foreach (self::PATTERNS as $pattern) {
            if (preg_match($pattern, $title) === 1) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> every app's title for an untitled recording, lower case */
    private static function fallbacks(): array
    {
        static $fallbacks = null;

        return $fallbacks ??= array_values(array_unique(array_map(
            fn (SourceApp $app): string => mb_strtolower(CaptureSession::fallbackTitleFor($app)),
            SourceApp::cases(),
        )));
    }
}
